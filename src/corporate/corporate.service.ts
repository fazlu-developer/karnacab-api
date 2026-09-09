import { BadRequestException, ForbiddenException, Injectable, NotFoundException } from '@nestjs/common';
import { randomInt } from 'crypto';
import { UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { DomainEvents } from '../common/domain-events.service';
import { PaymentsService } from '../payments/payments.service';
import { SupportService } from '../support/support.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';
import {
  AddCorporateEmployeeDto,
  CorporateSupportDto,
  PayCorporateInvoiceDto,
  UpsertCorporateAccountDto,
} from './dto/corporate.dto';

@Injectable()
export class CorporateService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly events: DomainEvents,
    private readonly scopes: ScopeService,
    private readonly payments: PaymentsService,
    private readonly supportDesk: SupportService,
  ) {}

  async dashboard(actor: Actor) {
    const account = await this.requireAccount(actor);
    const [employees, bulkJobs, invoices, rideCount] = await Promise.all([
      this.prisma.corporateEmployee.count({ where: { accountId: account.id, active: true } }),
      this.prisma.bulkBooking.findMany({
        where: { corporateAccountId: account.id },
        orderBy: { createdAt: 'desc' },
        take: 20,
      }),
      this.prisma.corporateInvoice.findMany({
        where: { accountId: account.id },
        orderBy: { createdAt: 'desc' },
        take: 12,
      }),
      this.prisma.booking.count({ where: { corporateAccountId: account.id } }),
    ]);
    return {
      account: this.presentAccount(account),
      employees,
      rideBookings: rideCount,
      bulkBookings: bulkJobs.map((row) => ({
        id: row.id.toString(),
        publicRef: row.publicRef,
        status: row.status,
        eventKey: row.eventKey,
        quotePaise: row.quotePaise == null ? null : Number(row.quotePaise),
      })),
      invoices: invoices.map((row) => this.presentInvoice(row)),
    };
  }

  async upsertAccount(actor: Actor, dto: UpsertCorporateAccountDto) {
    this.assertOwnerRole(actor);
    const existing = await this.prisma.corporateAccount.findUnique({ where: { ownerUserId: actor.userId } });
    const data = {
      companyName: dto.companyName,
      gstin: dto.gstin?.toUpperCase(),
      contactName: dto.contactName,
      contactPhone: dto.contactPhone,
      contactEmail: dto.contactEmail,
      districtId: actor.districtId,
      status: 'active',
    };
    const row = existing
      ? await this.prisma.corporateAccount.update({ where: { id: existing.id }, data })
      : await this.prisma.corporateAccount.create({ data: { ...data, ownerUserId: actor.userId } });
    this.events.emit('corporate.updated', { accountId: row.id.toString(), userId: actor.userId.toString() });
    return this.presentAccount(row);
  }

  async employees(actor: Actor) {
    const account = await this.requireAccount(actor);
    const rows = await this.prisma.corporateEmployee.findMany({
      where: { accountId: account.id },
      orderBy: { createdAt: 'desc' },
    });
    return {
      employees: rows.map((row) => ({
        id: row.id.toString(),
        name: row.name,
        phone: row.phone,
        email: row.email,
        department: row.department,
        active: row.active,
      })),
    };
  }

  async addEmployee(actor: Actor, dto: AddCorporateEmployeeDto) {
    const account = await this.requireAccount(actor);
    try {
      const row = await this.prisma.corporateEmployee.create({
        data: {
          accountId: account.id,
          name: dto.name,
          phone: dto.phone,
          email: dto.email,
          department: dto.department,
        },
      });
      return { id: row.id.toString(), name: row.name, phone: row.phone, department: row.department, active: true };
    } catch {
      throw new BadRequestException('That employee phone is already on this account');
    }
  }

  async invoices(actor: Actor) {
    const account = await this.requireAccount(actor);
    const rows = await this.prisma.corporateInvoice.findMany({
      where: { accountId: account.id },
      orderBy: { createdAt: 'desc' },
    });
    return { invoices: rows.map((row) => this.presentInvoice(row)) };
  }

  async generateMonthlyInvoice(actor: Actor) {
    const account = await this.requireAccount(actor);
    const now = new Date();
    const periodStart = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1));
    const periodEnd = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() + 1, 0));
    const existing = await this.prisma.corporateInvoice.findFirst({
      where: { accountId: account.id, periodStart, periodEnd },
    });
    if (existing) {
      return this.presentInvoice(existing);
    }
    const bulk = await this.prisma.bulkBooking.findMany({
      where: {
        corporateAccountId: account.id,
        status: { in: ['completed', 'invoiced', 'trip'] },
        createdAt: { gte: periodStart, lte: new Date(periodEnd.getTime() + 86400000) },
      },
    });
    const rides = await this.prisma.booking.findMany({
      where: { corporateAccountId: account.id, createdAt: { gte: periodStart } },
      select: { quotePaise: true },
    });
    const subtotal =
      bulk.reduce((sum, row) => sum + Number(row.quotePaise ?? 0), 0) +
      rides.reduce((sum, row) => sum + Number(row.quotePaise ?? 0), 0);
    const gstPaise = Math.round(subtotal * 0.05);
    const row = await this.prisma.corporateInvoice.create({
      data: {
        publicRef: `KI${Date.now().toString(36)}${randomInt(100, 999)}`.toUpperCase(),
        accountId: account.id,
        periodStart,
        periodEnd,
        subtotalPaise: BigInt(subtotal),
        gstPaise: BigInt(gstPaise),
        totalPaise: BigInt(subtotal + gstPaise),
        gstin: account.gstin,
        status: 'issued',
      },
    });
    return this.presentInvoice(row);
  }

  async payInvoice(actor: Actor, id: bigint, dto: PayCorporateInvoiceDto) {
    const account = await this.requireAccount(actor);
    const invoice = await this.prisma.corporateInvoice.findFirst({ where: { id, accountId: account.id } });
    if (!invoice) {
      throw new NotFoundException('Invoice not found');
    }
    if (invoice.status === 'paid') {
      return this.presentInvoice(invoice);
    }
    await this.payments.initiate(actor, {
      method: dto.method || 'cash',
      corporateInvoiceId: invoice.id,
      amountPaise: Number(invoice.totalPaise),
    });
    const updated = await this.prisma.corporateInvoice.findUniqueOrThrow({ where: { id: invoice.id } });
    return this.presentInvoice(updated);
  }

  async reports(actor: Actor) {
    const account = await this.requireAccount(actor);
    const [employees, bulk, rides, invoices] = await Promise.all([
      this.prisma.corporateEmployee.count({ where: { accountId: account.id, active: true } }),
      this.prisma.bulkBooking.findMany({ where: { corporateAccountId: account.id } }),
      this.prisma.booking.findMany({
        where: { corporateAccountId: account.id },
        select: { quotePaise: true, status: true },
      }),
      this.prisma.corporateInvoice.findMany({ where: { accountId: account.id } }),
    ]);
    const spend =
      bulk.reduce((sum, row) => sum + Number(row.quotePaise ?? 0), 0) +
      rides.reduce((sum, row) => sum + Number(row.quotePaise ?? 0), 0);
    return {
      company: account.companyName,
      employees,
      bulkJobs: bulk.length,
      rideBookings: rides.length,
      spendPaise: spend,
      spendRupees: spend / 100,
      openInvoices: invoices.filter((row) => row.status !== 'paid').length,
      gstin: account.gstin,
    };
  }

  async support(actor: Actor, dto: CorporateSupportDto) {
    const account = await this.requireAccount(actor);
    const ticket = await this.supportDesk.create(actor, {
      subject: 'Corporate support',
      message: dto.message,
      kind: 'corporate',
    });
    return { id: ticket.id, status: ticket.status, type: 'corporate', accountId: account.id.toString() };
  }

  private presentAccount(row: {
    id: bigint;
    companyName: string;
    gstin: string | null;
    contactName: string;
    contactPhone: string;
    contactEmail: string | null;
    status: string;
  }) {
    return {
      id: row.id.toString(),
      companyName: row.companyName,
      gstin: row.gstin,
      contactName: row.contactName,
      contactPhone: row.contactPhone,
      contactEmail: row.contactEmail,
      status: row.status,
    };
  }

  private presentInvoice(row: {
    id: bigint;
    publicRef: string;
    periodStart: Date;
    periodEnd: Date;
    subtotalPaise: bigint;
    gstPaise: bigint;
    totalPaise: bigint;
    gstin: string | null;
    status: string;
  }) {
    return {
      id: row.id.toString(),
      publicRef: row.publicRef,
      kind: 'gst_invoice' as const,
      periodStart: row.periodStart.toISOString().slice(0, 10),
      periodEnd: row.periodEnd.toISOString().slice(0, 10),
      subtotalPaise: Number(row.subtotalPaise),
      gstPaise: Number(row.gstPaise),
      totalPaise: Number(row.totalPaise),
      totalRupees: Number(row.totalPaise) / 100,
      gstin: row.gstin,
      status: row.status,
    };
  }

  private async requireAccount(actor: Actor) {
    this.assertOwnerRole(actor);
    if (actor.unrestricted) {
      const row = await this.prisma.corporateAccount.findFirst({ orderBy: { id: 'asc' } });
      if (!row) {
        throw new NotFoundException('No corporate account');
      }
      return row;
    }
    const row = await this.prisma.corporateAccount.findUnique({ where: { ownerUserId: actor.userId } });
    if (!row) {
      throw new NotFoundException('Register a company first');
    }
    this.scopes.assertCorporateAccount(actor, {
      ownerUserId: row.ownerUserId,
      districtId: row.districtId,
    });
    return row;
  }

  private assertOwnerRole(actor: Actor) {
    if (
      actor.role !== UserRole.CUSTOMER &&
      actor.role !== UserRole.CORPORATE &&
      !actor.unrestricted
    ) {
      throw new ForbiddenException('Corporate access required');
    }
  }
}
