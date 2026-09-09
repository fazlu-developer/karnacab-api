import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { randomInt } from 'crypto';
import { ConfigService } from '@nestjs/config';
import { Prisma, UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { DomainEvents } from '../common/domain-events.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';
import { PaymentsService } from '../payments/payments.service';
import { BulkQuoteEngine } from './bulk-quote.engine';
import { presentBulk } from './bulk.presenter';
import { BulkAction, nextBulkStatus, parseEventList } from './bulk-lifecycle';
import { BulkLifecycleDto, BulkQuoteDto, CreateBulkDto, PayBulkDto } from './dto/bulk.dto';

@Injectable()
export class BulkService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly quotes: BulkQuoteEngine,
    private readonly events: DomainEvents,
    private readonly scopes: ScopeService,
    private readonly config: ConfigService,
    private readonly payments: PaymentsService,
  ) {}

  async catalog() {
    const [types, advance, rules] = await Promise.all([
      this.prisma.systemSetting.findUnique({ where: { key: 'bulk_event_types' } }),
      this.prisma.systemSetting.findUnique({ where: { key: 'bulk_advance_percent' } }),
      this.prisma.bulkRateRule.findMany({ where: { active: true }, orderBy: [{ eventKey: 'asc' }, { category: 'asc' }] }),
    ]);
    return {
      events: parseEventList(types?.value),
      vehicles: ['SEDAN', 'SUV', 'TRAVELLER'],
      advancePercent: Number(advance?.value ?? 30),
      tracking: ['Request', 'Quotation', 'Accepted', 'Advance', 'Assignment', 'Trip', 'Final Invoice'],
      rates: rules.map((row) => ({
        eventKey: row.eventKey,
        category: row.category,
        perVehiclePaise: row.perVehiclePaise,
        gstPercent: row.gstPercent,
      })),
    };
  }

  async quote(dto: BulkQuoteDto) {
    return this.quotes.quote({
      ...dto,
      advancePercent: await this.advancePercent(),
    });
  }

  async create(actor: Actor, dto: CreateBulkDto) {
    this.assertCustomer(actor);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dto.eventDate)) {
      throw new BadRequestException('eventDate must be YYYY-MM-DD');
    }
    const events = parseEventList((await this.prisma.systemSetting.findUnique({ where: { key: 'bulk_event_types' } }))?.value);
    if (!events.some((row) => row.key === dto.eventKey)) {
      throw new BadRequestException('Unknown event type');
    }
    const quote = await this.quote(dto);
    const demo = this.config.get<boolean>('demo.autoAssign') === true;
    const row = await this.prisma.bulkBooking.create({
      data: {
        publicRef: `KB${Date.now().toString(36)}${randomInt(100, 999)}`.toUpperCase(),
        customerId: actor.userId,
        corporateAccountId: (await this.ownerAccountId(actor.userId)) ?? undefined,
        eventKey: dto.eventKey,
        vehicleCount: dto.vehicleCount,
        category: dto.category,
        pickupText: dto.pickupText,
        dropText: dto.dropText,
        pickupLat: dto.pickupLat,
        pickupLng: dto.pickupLng,
        dropLat: dto.dropLat,
        dropLng: dto.dropLng,
        eventDate: new Date(`${dto.eventDate}T00:00:00.000Z`),
        eventTime: dto.eventTime,
        passengers: dto.passengers,
        requirements: dto.requirements,
        quotePaise: BigInt(quote.totalPaise),
        quoteSnapshot: quote as Prisma.InputJsonValue,
        advancePaise: BigInt(quote.advancePaise),
        status: demo ? 'quoted' : 'requested',
        paymentStatus: 'unpaid',
      },
    });
    this.events.emit('bulk.created', { bookingId: row.id.toString(), customerId: actor.userId.toString() });
    return presentBulk(row, { events, role: actor.role, quote });
  }

  async list(actor: Actor) {
    const events = await this.eventList();
    const rows = await this.prisma.bulkBooking.findMany({
      where: this.scopes.bulkWhere(actor),
      orderBy: { createdAt: 'desc' },
      take: 50,
    });
    return { bookings: rows.map((row) => presentBulk(row, { events, role: actor.role })) };
  }

  async one(actor: Actor, id: bigint) {
    return presentBulk(await this.load(actor, id), { events: await this.eventList(), role: actor.role });
  }

  async payAdvance(actor: Actor, id: bigint, dto: PayBulkDto) {
    const row = await this.load(actor, id);
    this.assertCustomer(actor);
    if (row.status !== 'accepted') {
      throw new BadRequestException('Accept the quotation before paying advance');
    }
    const amount = row.advancePaise ?? BigInt(0);
    await this.payments.initiate(actor, {
      method: dto.method || 'cash',
      intent: 'advance',
      bulkBookingId: row.id,
      amountPaise: Number(amount),
    });
    const updated = await this.prisma.bulkBooking.findUniqueOrThrow({ where: { id: row.id } });
    this.events.emit('bulk.lifecycle', { bookingId: id.toString(), action: 'pay_advance', status: updated.status });
    return presentBulk(updated, { events: await this.eventList(), role: actor.role });
  }

  async payBalance(actor: Actor, id: bigint, dto: PayBulkDto) {
    const row = await this.load(actor, id);
    this.assertCustomer(actor);
    if (row.status !== 'invoiced') {
      throw new BadRequestException('Final invoice is not ready');
    }
    const amount = row.invoicePaise ?? BigInt(0);
    await this.payments.initiate(actor, {
      method: dto.method || 'cash',
      intent: 'partial',
      bulkBookingId: row.id,
      amountPaise: Number(amount),
    });
    const updated = await this.prisma.bulkBooking.findUniqueOrThrow({ where: { id: row.id } });
    this.events.emit('bulk.lifecycle', { bookingId: id.toString(), action: 'pay_balance', status: updated.status });
    return presentBulk(updated, { events: await this.eventList(), role: actor.role });
  }

  async transition(actor: Actor, id: bigint, dto: BulkLifecycleDto) {
    const row = await this.load(actor, id);
    const action = dto.action as BulkAction;
    const ops =
      actor.unrestricted ||
      actor.role === UserRole.ADMIN ||
      actor.role === UserRole.SUPER_ADMIN ||
      actor.role === UserRole.DISTRICT_HEAD;
    if (action === 'quote' && !ops) {
      throw new ForbiddenException('Ops must issue the quotation');
    }
    if (action === 'assign' && !ops) {
      throw new ForbiddenException('Ops must assign vehicles');
    }
    if ((action === 'pay_advance' || action === 'pay_balance') && !this.isCustomer(actor)) {
      throw new ForbiddenException('Customer must pay');
    }
    let next: ReturnType<typeof nextBulkStatus>;
    try {
      next = nextBulkStatus(row.status, action);
    } catch (err) {
      throw new BadRequestException(err instanceof Error ? err.message : 'Invalid transition');
    }
    const data: Prisma.BulkBookingUpdateInput = { status: next };
    if (action === 'quote') {
      const quote = await this.quote({
        eventKey: row.eventKey,
        category: row.category,
        vehicleCount: row.vehicleCount,
      });
      if (dto.quotePaise != null) {
        quote.totalPaise = dto.quotePaise;
        quote.totalRupees = dto.quotePaise / 100;
        quote.advancePaise = Math.round((dto.quotePaise * (await this.advancePercent())) / 100);
        quote.advanceRupees = quote.advancePaise / 100;
        quote.balancePaise = dto.quotePaise - quote.advancePaise;
      }
      data.quotePaise = BigInt(quote.totalPaise);
      data.quoteSnapshot = quote as Prisma.InputJsonValue;
      data.advancePaise = BigInt(quote.advancePaise);
    }
    if (action === 'assign') {
      data.assignmentNotes = dto.assignmentNotes || 'Fleet assigned';
    }
    if (action === 'invoice') {
      const total = Number(row.quotePaise ?? 0);
      const advance = Number(row.advancePaise ?? 0);
      data.invoicePaise = BigInt(Math.max(0, total - advance));
    }
    if (action === 'accept' && row.status !== 'quoted') {
      throw new BadRequestException('Quotation is not ready');
    }
    const updated = await this.prisma.bulkBooking.update({ where: { id }, data });
    this.events.emit('bulk.lifecycle', { bookingId: id.toString(), action, status: next });
    return presentBulk(updated, { events: await this.eventList(), role: actor.role });
  }

  private async load(actor: Actor, id: bigint) {
    const row = await this.prisma.bulkBooking.findUnique({
      where: { id },
      include: { customer: { select: { districtId: true, stateId: true } } },
    });
    if (!row) {
      throw new NotFoundException('Bulk booking not found');
    }
    this.scopes.assertBulk(actor, {
      customerId: row.customerId,
      customerDistrictId: row.customer.districtId,
      customerStateId: row.customer.stateId,
    });
    return row;
  }

  private async advancePercent() {
    const row = await this.prisma.systemSetting.findUnique({ where: { key: 'bulk_advance_percent' } });
    const value = Number(row?.value ?? 30);
    return Number.isFinite(value) ? value : 30;
  }

  private async eventList() {
    const row = await this.prisma.systemSetting.findUnique({ where: { key: 'bulk_event_types' } });
    return parseEventList(row?.value);
  }

  private async ownerAccountId(userId: bigint) {
    const account = await this.prisma.corporateAccount.findUnique({ where: { ownerUserId: userId } });
    return account?.id ?? null;
  }

  private isCustomer(actor: Actor) {
    return actor.role === UserRole.CUSTOMER || actor.role === UserRole.CORPORATE;
  }

  private assertCustomer(actor: Actor) {
    if (!this.isCustomer(actor)) {
      throw new ForbiddenException('Only customers can do that');
    }
  }
}
