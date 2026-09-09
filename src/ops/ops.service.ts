import { ForbiddenException, Injectable, NotFoundException } from '@nestjs/common';
import { UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';
import { UpdateFareRuleDto } from './dto/update-fare-rule.dto';
import { UpdateCommissionRuleDto } from './dto/update-commission-rule.dto';
import { serializeCommissionPolicy } from '../ride-engine/commission.engine';

const OPS_ROLES: UserRole[] = [
  UserRole.FLEET_OWNER,
  UserRole.DISTRICT_HEAD,
  UserRole.STATE_HEAD,
  UserRole.FRANCHISE,
  UserRole.CORPORATE,
  UserRole.ADMIN,
  UserRole.SUPER_ADMIN,
];

@Injectable()
export class OpsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly scopes: ScopeService,
  ) {}

  assertOperator(actor: Actor) {
    if (!OPS_ROLES.includes(actor.role)) {
      throw new ForbiddenException('Operator role required');
    }
  }

  async parcels(actor: Actor) {
    this.assertOperator(actor);
    const ids = await this.scopes.parcelCustomerIds(actor);
    if (ids === 'none') {
      return { parcels: [] };
    }
    const rows = await this.prisma.parcelShipment.findMany({
      where: ids === 'all' ? {} : { customerId: { in: ids } },
      orderBy: { createdAt: 'desc' },
      take: 100,
    });
    return {
      parcels: rows.map((row) => ({
        id: row.id.toString(),
        publicRef: row.publicRef,
        customerId: row.customerId.toString(),
        status: row.status,
        pickupText: row.pickupText,
        dropText: row.dropText,
      })),
    };
  }

  async fleet(actor: Actor) {
    this.assertOperator(actor);
    const rows = await this.prisma.fleetOwner.findMany({
      where: this.scopes.fleetWhere(actor),
      include: {
        user: { select: { name: true } },
        vehicles: { select: { districtId: true, district: { select: { stateId: true } } } },
      },
      take: 100,
    });
    return {
      fleets: rows.map((row) => ({
        id: row.id.toString(),
        userId: row.userId.toString(),
        tradeName: row.tradeName,
        vehicleCount: row.vehicles.length,
      })),
    };
  }

  async revenue(actor: Actor) {
    this.assertOperator(actor);
    const where = this.scopes.bookingWhere(actor);
    const bookings = await this.prisma.booking.findMany({
      where,
      select: { quotePaise: true, districtId: true },
    });
    const totalPaise = bookings.reduce(
      (sum, row) => sum + (row.quotePaise ? Number(row.quotePaise) : 0),
      0,
    );
    return {
      bookingCount: bookings.length,
      totalPaise,
      totalRupees: totalPaise / 100,
      scope: {
        role: actor.role,
        districtId: actor.districtId,
        stateId: actor.stateId,
      },
    };
  }

  async reports(actor: Actor) {
    const revenue = await this.revenue(actor);
    return { type: 'territory-summary', ...revenue };
  }

  async fareRules() {
    const rows = await this.prisma.fareRule.findMany({
      orderBy: [{ product: 'asc' }, { category: 'asc' }, { id: 'asc' }],
      take: 500,
    });
    return { fareRules: rows.map((row) => this.serializeFareRule(row)) };
  }

  async updateFareRule(id: number, dto: UpdateFareRuleDto) {
    const existing = await this.prisma.fareRule.findUnique({ where: { id } });
    if (!existing) {
      throw new NotFoundException('Fare rule not found');
    }
    const row = await this.prisma.fareRule.update({
      where: { id },
      data: dto,
    });
    return this.serializeFareRule(row);
  }

  async commissionRules() {
    const rows = await this.prisma.commissionRule.findMany({ orderBy: { id: 'asc' } });
    return { commissionRules: rows.map((row) => this.serializeCommission(row)) };
  }

  async updateCommissionRule(id: number, dto: UpdateCommissionRuleDto) {
    const existing = await this.prisma.commissionRule.findUnique({ where: { id } });
    if (!existing) {
      throw new NotFoundException('Commission rule not found');
    }
    const row = await this.prisma.commissionRule.update({
      where: { id },
      data: dto,
    });
    return this.serializeCommission(row);
  }

  private serializeCommission(row: {
    id: number;
    name: string;
    percent: unknown;
    onBaseFare: boolean;
    onGst: boolean;
    onToll: boolean;
    onParking: boolean;
    onWaiting: boolean;
    onDiscount: boolean;
    onOther: boolean;
    active: boolean;
  }) {
    return {
      id: row.id,
      name: row.name,
      active: row.active,
      ...serializeCommissionPolicy(row),
    };
  }

  private serializeFareRule(row: {
    id: number;
    districtId: number | null;
    product: string;
    category: string;
    minKm: unknown;
    includedKm: unknown;
    perKmPaise: number;
    extraKmPaise: number;
    waitingPaise: number;
    nightPercent: number;
    gstPercent: number;
    cancelPaise: number;
    discountPaise?: number;
    discountPercent?: number;
    applyToll: boolean;
    applyParking: boolean;
    applyGstToBase: boolean;
    active: boolean;
    driverAllowPaise?: number;
    extraHourPaise?: number;
    nightStayPaise?: number;
    rentalHours?: number | null;
    stopPaise?: number;
  }) {
    return {
      id: row.id,
      districtId: row.districtId,
      product: row.product,
      category: row.category,
      active: row.active,
      rates: {
        minKm: Number(row.minKm),
        includedKm: Number(row.includedKm),
        perKmPaise: row.perKmPaise,
        extraKmPaise: row.extraKmPaise,
        waitingPaisePerMin: row.waitingPaise,
        nightPercent: row.nightPercent,
        gstPercent: row.gstPercent,
        cancelPaise: row.cancelPaise,
        discountPaise: row.discountPaise ?? 0,
        discountPercent: row.discountPercent ?? 0,
        applyToll: row.applyToll,
        applyParking: row.applyParking,
        applyGstToBase: row.applyGstToBase,
        driverAllowPaise: row.driverAllowPaise ?? 0,
        extraHourPaise: row.extraHourPaise ?? 15000,
        nightStayPaise: row.nightStayPaise ?? 0,
        rentalHours: row.rentalHours ?? null,
        stopPaise: row.stopPaise ?? 0,
      },
    };
  }
}
