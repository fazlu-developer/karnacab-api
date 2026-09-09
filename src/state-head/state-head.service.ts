import { Injectable, NotFoundException } from '@nestjs/common';
import { BookingStatus, FranchiseStatus, UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';
import { BookingsService } from '../bookings/bookings.service';
import { DriversService } from '../drivers/drivers.service';
import { VehiclesService } from '../vehicles/vehicles.service';
import { LIVE_STATUSES } from '../ride-engine/booking-lifecycle';
import { FRANCHISE_KIND_LABELS, FRANCHISE_STATUS_LABELS } from '../franchise/franchise-status';
import { serializeCommissionPolicy } from '../ride-engine/commission.engine';

@Injectable()
export class StateHeadService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly scopes: ScopeService,
    private readonly bookingSvc: BookingsService,
    private readonly driverSvc: DriversService,
    private readonly vehicleSvc: VehiclesService,
  ) {}

  private gate(actor: Actor) {
    this.scopes.requireAssignedState(actor);
  }

  async dashboard(actor: Actor) {
    this.gate(actor);
    const vehicleWhere = this.scopes.vehicleWhere(actor);
    const driverWhere = this.scopes.driverWhere(actor);
    const bookingWhere = this.scopes.bookingWhere(actor);
    const parcelWhere = this.scopes.parcelWhere(actor);
    const leadWhere = this.scopes.leadWhere(actor);
    const dayStart = new Date();
    dayStart.setHours(0, 0, 0, 0);

    const [
      totalVehicles,
      onlineVehicles,
      drivers,
      tripsToday,
      tripsLive,
      tripsCompleted,
      tripsCancelled,
      revenueRows,
      parcelVolume,
      complaints,
      state,
    ] = await Promise.all([
      this.prisma.vehicle.count({ where: vehicleWhere }),
      this.prisma.vehicle.count({
        where: {
          AND: [
            vehicleWhere,
            {
              OR: [
                { driver: { online: true } },
                { driver: { dutyStatus: { in: ['online', 'on_trip', 'on_delivery'] } } },
                { status: { in: ['online', 'on_trip'] } },
              ],
            },
          ],
        },
      }),
      this.prisma.driver.count({ where: driverWhere }),
      this.prisma.booking.count({ where: { AND: [bookingWhere, { createdAt: { gte: dayStart } }] } }),
      this.prisma.booking.count({ where: { AND: [bookingWhere, { status: { in: LIVE_STATUSES } }] } }),
      this.prisma.booking.count({ where: { AND: [bookingWhere, { status: BookingStatus.COMPLETED }] } }),
      this.prisma.booking.count({ where: { AND: [bookingWhere, { status: BookingStatus.CANCELLED }] } }),
      this.prisma.booking.findMany({
        where: { AND: [bookingWhere, { status: BookingStatus.COMPLETED }] },
        select: { quotePaise: true },
        take: 8000,
      }),
      this.prisma.parcelShipment.count({ where: parcelWhere }),
      this.prisma.lead.count({ where: leadWhere }),
      actor.stateId
        ? this.prisma.state.findUnique({ where: { id: actor.stateId }, select: { id: true, name: true, code: true } })
        : Promise.resolve(null),
    ]);

    const revenuePaise = revenueRows.reduce((sum, row) => sum + Number(row.quotePaise ?? 0), 0);
    const settled = tripsCompleted + tripsCancelled;
    return {
      scope: { role: actor.role, stateId: actor.stateId, stateName: state?.name ?? null, unrestricted: actor.unrestricted },
      modules: [
        'districts',
        'district-heads',
        'fleet',
        'drivers',
        'vehicles',
        'bookings',
        'parcel',
        'revenue',
        'commission',
        'reports',
        'complaints',
        'operations',
      ],
      totals: {
        vehicles: totalVehicles,
        onlineVehicles,
        drivers,
        trips: tripsToday,
        tripsLive,
        revenuePaise,
        revenueRupees: revenuePaise / 100,
        parcelVolume,
        complaints,
      },
      performance: {
        completionRate: settled === 0 ? null : Math.round((tripsCompleted / settled) * 1000) / 10,
        onlineVehicleShare: totalVehicles === 0 ? null : Math.round((onlineVehicles / totalVehicles) * 1000) / 10,
        completedTrips: tripsCompleted,
        cancelledTrips: tripsCancelled,
      },
    };
  }

  async districts(actor: Actor) {
    this.gate(actor);
    const rows = await this.prisma.district.findMany({
      where: this.scopes.districtWhere(actor),
      include: { state: { select: { name: true, code: true } } },
      orderBy: { name: 'asc' },
    });
    return {
      districts: rows.map((row) => ({
        id: row.id,
        name: row.name,
        code: row.code,
        stateId: row.stateId,
        stateName: row.state.name,
      })),
    };
  }

  async district(actor: Actor, districtId: number) {
    this.gate(actor);
    const row = await this.prisma.district.findUnique({
      where: { id: districtId },
      include: { state: { select: { name: true, code: true } } },
    });
    if (!row) {
      throw new NotFoundException('District not found');
    }
    this.scopes.assertDistrict(actor, { id: row.id, stateId: row.stateId });
    return {
      id: row.id,
      name: row.name,
      code: row.code,
      stateId: row.stateId,
      stateName: row.state.name,
    };
  }

  async districtHeads(actor: Actor) {
    this.gate(actor);
    const rows = await this.prisma.franchise.findMany({
      where: this.scopes.franchiseWhere(actor),
      include: {
        owner: { select: { id: true, name: true, email: true, phone: true, role: true } },
        district: { select: { id: true, name: true, stateId: true } },
      },
      orderBy: { createdAt: 'desc' },
      take: 200,
    });
    return {
      districtHeads: rows.map((row) => ({
        id: row.id.toString(),
        kind: row.kind,
        kindLabel: FRANCHISE_KIND_LABELS[row.kind],
        status: row.status,
        statusLabel: FRANCHISE_STATUS_LABELS[row.status],
        tradeName: row.tradeName,
        exclusiveSeat: row.status === FranchiseStatus.ACTIVE,
        district: { id: row.district.id, name: row.district.name, stateId: row.district.stateId },
        owner: {
          userId: row.owner.id.toString(),
          name: row.owner.name,
          email: row.owner.email,
          phone: row.owner.phone,
          role: row.owner.role,
        },
      })),
    };
  }

  async fleet(actor: Actor) {
    this.gate(actor);
    const rows = await this.prisma.fleetOwner.findMany({
      where: this.scopes.fleetWhere(actor),
      include: {
        user: { select: { name: true, email: true, phone: true } },
        vehicles: { select: { districtId: true, district: { select: { stateId: true } } } },
        drivers: { select: { id: true } },
      },
      take: 200,
    });
    return {
      fleets: rows.map((row) => ({
        id: row.id.toString(),
        userId: row.userId.toString(),
        tradeName: row.tradeName,
        ownerName: row.user.name,
        email: row.user.email,
        phone: row.user.phone,
        vehicleCount: row.vehicles.length,
        driverCount: row.drivers.length,
      })),
    };
  }

  async fleetOne(actor: Actor, fleetId: bigint) {
    this.gate(actor);
    const row = await this.prisma.fleetOwner.findUnique({
      where: { id: fleetId },
      include: {
        user: { select: { name: true, email: true } },
        vehicles: { select: { districtId: true, district: { select: { stateId: true } } } },
      },
    });
    if (!row) {
      throw new NotFoundException('Fleet not found');
    }
    this.scopes.assertFleet(actor, {
      userId: row.userId,
      vehicleDistrictIds: row.vehicles.map((item) => item.districtId),
      vehicleStateIds: row.vehicles.map((item) => item.district.stateId),
    });
    return {
      id: row.id.toString(),
      tradeName: row.tradeName,
      ownerName: row.user.name,
      email: row.user.email,
      vehicleCount: row.vehicles.length,
    };
  }

  async drivers(actor: Actor) {
    this.gate(actor);
    return this.driverSvc.list(actor);
  }

  async driver(actor: Actor, id: bigint) {
    this.gate(actor);
    return this.driverSvc.one(actor, id);
  }

  async vehiclesList(actor: Actor) {
    this.gate(actor);
    return this.vehicleSvc.list(actor);
  }

  async vehicle(actor: Actor, id: bigint) {
    this.gate(actor);
    return this.vehicleSvc.one(actor, id);
  }

  async bookings(actor: Actor) {
    this.gate(actor);
    const bookings = await this.bookingSvc.list(actor);
    return { bookings };
  }

  async booking(actor: Actor, id: bigint) {
    this.gate(actor);
    return this.bookingSvc.one(actor, id);
  }

  async parcels(actor: Actor) {
    this.gate(actor);
    const rows = await this.prisma.parcelShipment.findMany({
      where: this.scopes.parcelWhere(actor),
      orderBy: { createdAt: 'desc' },
      take: 200,
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

  async parcel(actor: Actor, id: bigint) {
    this.gate(actor);
    const parcel = await this.prisma.parcelShipment.findUnique({ where: { id } });
    if (!parcel) {
      throw new NotFoundException('Parcel not found');
    }
    const customer = await this.prisma.user.findUnique({
      where: { id: parcel.customerId },
      select: { districtId: true, stateId: true },
    });
    this.scopes.assertParcel(actor, {
      customerId: parcel.customerId,
      customerDistrictId: customer?.districtId ?? null,
      customerStateId: customer?.stateId ?? null,
      driverId: parcel.driverId,
    });
    return {
      id: parcel.id.toString(),
      publicRef: parcel.publicRef,
      customerId: parcel.customerId.toString(),
      status: parcel.status,
      pickupText: parcel.pickupText,
      dropText: parcel.dropText,
    };
  }

  async revenue(actor: Actor) {
    this.gate(actor);
    const rows = await this.prisma.booking.findMany({
      where: { AND: [this.scopes.bookingWhere(actor), { status: BookingStatus.COMPLETED }] },
      select: { quotePaise: true, districtId: true },
    });
    const byDistrict = new Map<number, { count: number; paise: number }>();
    let totalPaise = 0;
    for (const row of rows) {
      totalPaise += Number(row.quotePaise ?? 0);
      if (row.districtId == null) {
        continue;
      }
      const current = byDistrict.get(row.districtId) ?? { count: 0, paise: 0 };
      current.count += 1;
      current.paise += Number(row.quotePaise ?? 0);
      byDistrict.set(row.districtId, current);
    }
    return {
      scope: { role: actor.role, stateId: actor.stateId },
      bookingCount: rows.length,
      totalPaise,
      totalRupees: totalPaise / 100,
      districts: [...byDistrict.entries()].map(([districtId, value]) => ({
        districtId,
        bookingCount: value.count,
        totalPaise: value.paise,
        totalRupees: value.paise / 100,
      })),
    };
  }

  async commission(actor: Actor) {
    this.gate(actor);
    const rule = await this.prisma.commissionRule.findFirst({ where: { active: true, name: 'default' } });
    const seats = await this.prisma.franchise.findMany({
      where: { AND: [this.scopes.franchiseWhere(actor), { status: FranchiseStatus.ACTIVE }] },
      select: { id: true, districtId: true, tradeName: true, commissionPercent: true },
    });
    return {
      platformRule: serializeCommissionPolicy(rule),
      districtSeats: seats.map((row) => ({
        id: row.id.toString(),
        districtId: row.districtId,
        tradeName: row.tradeName,
        commissionPercent: Number(row.commissionPercent),
      })),
    };
  }

  async reports(actor: Actor) {
    const [dash, revenue, districts] = await Promise.all([
      this.dashboard(actor),
      this.revenue(actor),
      this.districts(actor),
    ]);
    return {
      type: 'state-summary',
      scope: dash.scope,
      totals: dash.totals,
      performance: dash.performance,
      revenue,
      districtCount: districts.districts.length,
    };
  }

  async complaints(actor: Actor) {
    this.gate(actor);
    const rows = await this.prisma.lead.findMany({
      where: this.scopes.leadWhere(actor),
      include: { user: { select: { id: true, name: true, districtId: true, stateId: true } } },
      orderBy: { createdAt: 'desc' },
      take: 200,
    });
    return {
      complaints: rows.map((row) => ({
        id: row.id.toString(),
        status: row.status,
        name: row.name,
        phone: row.phone,
        message: row.message,
        createdAt: row.createdAt.toISOString(),
        user: row.user
          ? {
              userId: row.user.id.toString(),
              name: row.user.name,
              districtId: row.user.districtId,
              stateId: row.user.stateId,
            }
          : null,
      })),
    };
  }

  async complaint(actor: Actor, id: bigint) {
    this.gate(actor);
    const row = await this.prisma.lead.findUnique({
      where: { id },
      include: { user: { select: { id: true, districtId: true, stateId: true, name: true } } },
    });
    if (!row) {
      throw new NotFoundException('Complaint not found');
    }
    this.scopes.assertLead(actor, {
      userId: row.userId,
      userDistrictId: row.user?.districtId ?? null,
      userStateId: row.user?.stateId ?? null,
    });
    return {
      id: row.id.toString(),
      status: row.status,
      name: row.name,
      phone: row.phone,
      message: row.message,
      createdAt: row.createdAt.toISOString(),
    };
  }

  async operations(actor: Actor) {
    this.gate(actor);
    const bookingWhere = this.scopes.bookingWhere(actor);
    const driverWhere = this.scopes.driverWhere(actor);
    const [searching, live, onlineDrivers, fleets] = await Promise.all([
      this.prisma.booking.count({
        where: { AND: [bookingWhere, { status: { in: [BookingStatus.REQUESTED, BookingStatus.DRIVER_SEARCHING] } }] },
      }),
      this.prisma.booking.count({ where: { AND: [bookingWhere, { status: { in: LIVE_STATUSES } }] } }),
      this.prisma.driver.count({ where: { AND: [driverWhere, { online: true }] } }),
      this.prisma.fleetOwner.count({ where: this.scopes.fleetWhere(actor) }),
    ]);
    return {
      scope: { role: actor.role, stateId: actor.stateId },
      searchingTrips: searching,
      liveTrips: live,
      onlineDrivers,
      fleets,
    };
  }
}
