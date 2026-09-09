import { Injectable, NotFoundException } from '@nestjs/common';
import { BookingStatus, FranchiseStatus } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';
import { BookingsService } from '../bookings/bookings.service';
import { DriversService } from '../drivers/drivers.service';
import { VehiclesService } from '../vehicles/vehicles.service';
import { BulkService } from '../bulk/bulk.service';
import { WalletsService } from '../wallets/wallets.service';
import { serializeCommissionPolicy } from '../ride-engine/commission.engine';
import { LIVE_STATUSES } from '../ride-engine/booking-lifecycle';

@Injectable()
export class DistrictHeadService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly scopes: ScopeService,
    private readonly bookingSvc: BookingsService,
    private readonly driverSvc: DriversService,
    private readonly vehicleSvc: VehiclesService,
    private readonly bulkSvc: BulkService,
    private readonly wallets: WalletsService,
  ) {}

  private gate(actor: Actor) {
    this.scopes.requireAssignedDistrict(actor);
  }

  async dashboard(actor: Actor) {
    this.gate(actor);
    const vehicleWhere = this.scopes.vehicleWhere(actor);
    const driverWhere = this.scopes.driverWhere(actor);
    const bookingWhere = this.scopes.bookingWhere(actor);
    const parcelWhere = this.scopes.parcelWhere(actor);
    const leadWhere = this.scopes.leadWhere(actor);
    const fleetWhere = this.scopes.fleetWhere(actor);

    const [
      vehicles,
      onlineDrivers,
      activeTrips,
      completedTrips,
      cancelledTrips,
      revenueRows,
      parcelVolume,
      complaints,
      fleets,
      district,
    ] = await Promise.all([
      this.prisma.vehicle.count({ where: vehicleWhere }),
      this.prisma.driver.count({ where: { AND: [driverWhere, { online: true }] } }),
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
      this.prisma.fleetOwner.count({ where: fleetWhere }),
      actor.districtId
        ? this.prisma.district.findUnique({
            where: { id: actor.districtId },
            include: { state: { select: { name: true, code: true } } },
          })
        : Promise.resolve(null),
    ]);

    const revenuePaise = revenueRows.reduce((sum, row) => sum + Number(row.quotePaise ?? 0), 0);
    const settled = completedTrips + cancelledTrips;
    return {
      scope: {
        role: actor.role,
        districtId: actor.districtId,
        districtName: district?.name ?? null,
        stateId: actor.stateId ?? district?.stateId ?? null,
        stateName: district?.state.name ?? null,
        unrestricted: actor.unrestricted,
      },
      modules: [
        'fleet',
        'drivers',
        'vehicles',
        'local-bookings',
        'bulk-bookings',
        'parcel',
        'revenue',
        'commission',
        'wallet',
        'reports',
        'complaints',
        'operations',
      ],
      totals: {
        vehicles,
        onlineDrivers,
        activeTrips,
        completedTrips,
        revenuePaise,
        revenueRupees: revenuePaise / 100,
        parcels: parcelVolume,
        complaints,
        fleets,
      },
      fleetPerformance: {
        completionRate: settled === 0 ? null : Math.round((completedTrips / settled) * 1000) / 10,
        activeTripShare: vehicles === 0 ? null : Math.round((activeTrips / vehicles) * 1000) / 10,
        cancelledTrips,
      },
    };
  }

  async fleet(actor: Actor) {
    this.gate(actor);
    const rows = await this.prisma.fleetOwner.findMany({
      where: this.scopes.fleetWhere(actor),
      include: {
        user: { select: { name: true, email: true, phone: true } },
        vehicles: { select: { districtId: true, district: { select: { stateId: true } } } },
        drivers: { select: { id: true, online: true } },
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
        onlineDrivers: row.drivers.filter((item) => item.online).length,
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

  async bulk(actor: Actor) {
    this.gate(actor);
    return this.bulkSvc.list(actor);
  }

  async bulkOne(actor: Actor, id: bigint) {
    this.gate(actor);
    return this.bulkSvc.one(actor, id);
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
      select: { quotePaise: true },
    });
    const totalPaise = rows.reduce((sum, row) => sum + Number(row.quotePaise ?? 0), 0);
    return {
      scope: { role: actor.role, districtId: actor.districtId },
      bookingCount: rows.length,
      totalPaise,
      totalRupees: totalPaise / 100,
    };
  }

  async commission(actor: Actor) {
    this.gate(actor);
    const [rule, seat] = await Promise.all([
      this.prisma.commissionRule.findFirst({ where: { active: true, name: 'default' } }),
      actor.districtId
        ? this.prisma.franchise.findFirst({
            where: { districtId: actor.districtId, status: FranchiseStatus.ACTIVE },
            select: { id: true, tradeName: true, commissionPercent: true, kind: true },
          })
        : Promise.resolve(null),
    ]);
    return {
      platformRule: serializeCommissionPolicy(rule),
      districtSeat: seat
        ? {
            id: seat.id.toString(),
            kind: seat.kind,
            tradeName: seat.tradeName,
            commissionPercent: Number(seat.commissionPercent),
          }
        : null,
    };
  }

  async wallet(actor: Actor) {
    this.gate(actor);
    const own = await this.wallets.mine(actor.userId, actor.role);
    const territory = await this.prisma.wallet.findMany({
      where: this.scopes.walletWhere(actor),
      include: { owner: { select: { id: true, name: true, role: true, districtId: true } } },
      take: 200,
    });
    return {
      mine: own.wallets,
      territory: territory.map((row) => ({
        id: row.id.toString(),
        ownerType: row.ownerType,
        ownerUserId: row.ownerUserId.toString(),
        ownerName: row.owner.name,
        ownerRole: row.owner.role,
        districtId: row.owner.districtId,
        balancePaise: row.balancePaise.toString(),
        balanceRupees: Number(row.balancePaise) / 100,
      })),
    };
  }

  async walletOne(actor: Actor, id: bigint) {
    this.gate(actor);
    return this.wallets.one(actor, id);
  }

  async reports(actor: Actor) {
    const [dash, revenue, fleet] = await Promise.all([this.dashboard(actor), this.revenue(actor), this.fleet(actor)]);
    return {
      type: 'district-summary',
      scope: dash.scope,
      totals: dash.totals,
      fleetPerformance: dash.fleetPerformance,
      revenue,
      fleetCount: fleet.fleets.length,
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
    const [searching, live, onlineDrivers, bulkOpen] = await Promise.all([
      this.prisma.booking.count({
        where: { AND: [bookingWhere, { status: { in: [BookingStatus.REQUESTED, BookingStatus.DRIVER_SEARCHING] } }] },
      }),
      this.prisma.booking.count({ where: { AND: [bookingWhere, { status: { in: LIVE_STATUSES } }] } }),
      this.prisma.driver.count({ where: { AND: [driverWhere, { online: true }] } }),
      this.prisma.bulkBooking.count({
        where: {
          AND: [
            this.scopes.bulkWhere(actor),
            { status: { notIn: ['completed', 'cancelled'] } },
          ],
        },
      }),
    ]);
    return {
      scope: { role: actor.role, districtId: actor.districtId },
      searchingTrips: searching,
      activeTrips: live,
      onlineDrivers,
      openBulkJobs: bulkOpen,
    };
  }
}
