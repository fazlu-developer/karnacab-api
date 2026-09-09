import { ForbiddenException, Injectable, NotFoundException } from '@nestjs/common';
import { UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { RedisService } from '../redis/redis.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';
import { DomainEvents } from '../common/domain-events.service';
import { LIVE_STATUSES } from '../ride-engine/booking-lifecycle';
import { DriverLocationDto } from './dto/driver-location.dto';
import {
  isStale,
  liveBookingChannel,
  liveDistrictChannel,
  liveDriverKey,
  LiveFix,
  shouldPersistFix,
  shouldSkipPing,
} from './live-fix';
import { ACTIVE_PARCEL_STATUSES } from '../drivers/driver-duty';
import {
  canUseLiveFleetMap,
  customerMaySeeDriverLocation,
  filterFleetMapVehicles,
} from './location-access';
import {
  emptyFleetMapCounts,
  FLEET_MAP_STATUS_LABELS,
  FleetMapStatus,
  resolveFleetMapStatus,
} from './fleet-map-status';

const REDIS_TTL = 180;

@Injectable()
export class LocationService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly redis: RedisService,
    private readonly scopes: ScopeService,
    private readonly events: DomainEvents,
  ) {}

  async ingest(actor: Actor, dto: DriverLocationDto) {
    if (actor.role !== UserRole.DRIVER || !actor.driverId) {
      throw new ForbiddenException('Driver profile required');
    }
    const recordedAt = dto.recordedAt ? Date.parse(dto.recordedAt) : Date.now();
    const at = Number.isFinite(recordedAt) ? recordedAt : Date.now();
    const prev = await this.readRaw(actor.driverId);
    if (shouldSkipPing(prev, { lat: dto.lat, lng: dto.lng, recordedAt: at })) {
      return this.present(prev!, false);
    }

    const driver = await this.prisma.driver.findUnique({
      where: { id: actor.driverId },
      include: {
        vehicles: { take: 1, orderBy: { updatedAt: 'desc' } },
        user: { select: { districtId: true } },
      },
    });
    if (!driver) {
      throw new ForbiddenException('Driver profile required');
    }

    let bookingId = dto.bookingId ?? null;
    if (!bookingId) {
      const live = await this.prisma.booking.findFirst({
        where: { driverId: driver.id, status: { in: LIVE_STATUSES } },
        select: { id: true },
      });
      bookingId = live?.id.toString() ?? null;
    }

    const vehicle = driver.vehicles[0] ?? null;
    const districtId = vehicle?.districtId ?? driver.user.districtId ?? actor.districtId;
    const fix: LiveFix = {
      driverId: driver.id.toString(),
      vehicleId: vehicle?.id.toString() ?? null,
      bookingId,
      districtId,
      lat: dto.lat,
      lng: dto.lng,
      heading: dto.heading ?? null,
      speed: dto.speed ?? null,
      tripStatus: (dto.tripStatus || 'online').slice(0, 32),
      recordedAt: new Date(at).toISOString(),
    };

    await this.redis.setex(liveDriverKey(driver.id), REDIS_TTL, JSON.stringify(fix));
    if (bookingId) {
      await this.redis.publish(liveBookingChannel(bookingId), JSON.stringify(fix));
    }
    if (districtId != null) {
      await this.redis.publish(liveDistrictChannel(districtId), JSON.stringify(fix));
    }

    const persist = shouldPersistFix(prev, { lat: dto.lat, lng: dto.lng, recordedAt: at });
    if (persist) {
      await this.prisma.user.update({
        where: { id: actor.userId },
        data: {
          lastLat: dto.lat,
          lastLng: dto.lng,
          locationUpdatedAt: new Date(at),
        },
      });
      if (vehicle) {
        await this.prisma.vehicle.update({
          where: { id: vehicle.id },
          data: { lastLat: dto.lat, lastLng: dto.lng, lastFixAt: new Date(at) },
        });
      }
      fix.persistedAt = new Date().toISOString();
      await this.redis.setex(liveDriverKey(driver.id), REDIS_TTL, JSON.stringify(fix));
    }

    this.events.emit('driver.location', {
      driverId: fix.driverId,
      bookingId: fix.bookingId,
      districtId: fix.districtId,
    });
    return this.present(fix, persist);
  }

  async mine(actor: Actor) {
    if (actor.role !== UserRole.DRIVER || !actor.driverId) {
      throw new ForbiddenException('Driver profile required');
    }
    const fix = await this.readWithFallback(actor.driverId, actor.userId);
    return fix ? this.present(fix, false) : { visible: false, lastKnown: null };
  }

  async forBooking(actor: Actor, bookingId: bigint) {
    const booking = await this.prisma.booking.findUnique({
      where: { id: bookingId },
      include: {
        district: { select: { stateId: true } },
        vehicle: { select: { fleetOwnerId: true, districtId: true } },
        driver: { include: { user: { select: { lastLat: true, lastLng: true, locationUpdatedAt: true } } } },
      },
    });
    if (!booking) {
      throw new NotFoundException('Booking not found');
    }
    this.scopes.assertBooking(actor, {
      customerId: booking.customerId,
      driverId: booking.driverId,
      vehicleFleetOwnerId: booking.vehicle?.fleetOwnerId ?? null,
      districtId: booking.districtId,
      districtStateId: booking.district?.stateId ?? null,
      status: booking.status,
    });

    const customerOk = customerMaySeeDriverLocation({
      actor,
      customerId: booking.customerId,
      driverId: booking.driverId,
      status: booking.status,
    });
    const assignedSelf = actor.role === UserRole.DRIVER && actor.driverId === booking.driverId;
    const staff =
      actor.role !== UserRole.CUSTOMER &&
      actor.role !== UserRole.CORPORATE &&
      actor.role !== UserRole.DRIVER;

    if (!customerOk && !assignedSelf && !staff) {
      return { visible: false, location: null };
    }
    if (!booking.driverId) {
      return { visible: false, location: null };
    }

    const fix = await this.readWithFallback(
      booking.driverId,
      booking.driver?.userId,
      booking.driver?.user,
    );
    return {
      visible: Boolean(fix),
      location: fix ? this.present(fix, false) : null,
    };
  }

  async liveVehicles(actor: Actor) {
    if (actor.role === UserRole.CUSTOMER || actor.role === UserRole.DRIVER) {
      throw new ForbiddenException('Operations access required');
    }
    if (actor.role === UserRole.CORPORATE) {
      return { vehicles: [] };
    }
    const map = await this.loadFleetMap(actor);
    return { vehicles: map.vehicles };
  }

  async liveFleetMap(actor: Actor, status?: FleetMapStatus) {
    if (!canUseLiveFleetMap(actor)) {
      throw new ForbiddenException('Live fleet map is limited to admin, state head, district head, and fleet owner');
    }
    const map = await this.loadFleetMap(actor, status);
    return {
      scope: {
        role: actor.role,
        districtId: actor.districtId,
        stateId: actor.stateId,
        fleetOwnerId: actor.fleetOwnerId?.toString() ?? null,
        unrestricted: actor.unrestricted,
      },
      statuses: FLEET_MAP_STATUS_LABELS,
      counts: map.counts,
      vehicles: map.vehicles,
    };
  }

  private async loadFleetMap(actor: Actor, status?: FleetMapStatus) {
    const vehicles = await this.prisma.vehicle.findMany({
      where: this.scopes.vehicleWhere(actor),
      include: {
        district: { select: { stateId: true } },
        driver: {
          include: {
            user: { select: { lastLat: true, lastLng: true, locationUpdatedAt: true, name: true } },
          },
        },
      },
      take: 400,
    });
    const allowed = filterFleetMapVehicles(
      actor,
      vehicles.map((row) => ({
        ...row,
        districtStateId: row.district.stateId,
      })),
    );
    const vehicleIds = allowed.map((row) => row.id);
    const driverIds = allowed.map((row) => row.driverId).filter((id): id is bigint => id != null);

    const [trips, parcels] = await Promise.all([
      vehicleIds.length
        ? this.prisma.booking.findMany({
            where: {
              status: { in: LIVE_STATUSES },
              OR: [
                { vehicleId: { in: vehicleIds } },
                ...(driverIds.length ? [{ driverId: { in: driverIds } }] : []),
              ],
            },
            select: { id: true, vehicleId: true, driverId: true },
          })
        : Promise.resolve([]),
      vehicleIds.length
        ? this.prisma.parcelShipment.findMany({
            where: {
              status: { in: [...ACTIVE_PARCEL_STATUSES] },
              OR: [
                { vehicleId: { in: vehicleIds } },
                ...(driverIds.length ? [{ driverId: { in: driverIds } }] : []),
              ],
            },
            select: { vehicleId: true, driverId: true },
          })
        : Promise.resolve([]),
    ]);

    const tripByVehicle = new Set<string>();
    const tripByDriver = new Set<string>();
    for (const trip of trips) {
      if (trip.vehicleId != null) {
        tripByVehicle.add(trip.vehicleId.toString());
      }
      if (trip.driverId != null) {
        tripByDriver.add(trip.driverId.toString());
      }
    }
    const deliveryByVehicle = new Set<string>();
    const deliveryByDriver = new Set<string>();
    for (const parcel of parcels) {
      if (parcel.vehicleId != null) {
        deliveryByVehicle.add(parcel.vehicleId.toString());
      }
      if (parcel.driverId != null) {
        deliveryByDriver.add(parcel.driverId.toString());
      }
    }

    const keys = driverIds.map((id) => liveDriverKey(id));
    const raw = keys.length ? await this.redis.mget(keys) : [];
    let redisIndex = 0;
    const counts = emptyFleetMapCounts();
    const list = allowed.map((row) => {
      let fix: LiveFix | null = null;
      if (row.driverId != null) {
        fix = this.parse(raw[redisIndex++] ?? null);
      }
      if (!fix) {
        const userLat = row.driver?.user.lastLat;
        const userLng = row.driver?.user.lastLng;
        const lat = userLat != null ? Number(userLat) : row.lastLat != null ? Number(row.lastLat) : null;
        const lng = userLng != null ? Number(userLng) : row.lastLng != null ? Number(row.lastLng) : null;
        if (lat != null && lng != null) {
          fix = {
            driverId: row.driverId?.toString() ?? '',
            vehicleId: row.id.toString(),
            bookingId: null,
            districtId: row.districtId,
            lat,
            lng,
            heading: null,
            speed: null,
            tripStatus: row.status,
            recordedAt: (
              row.lastFixAt ??
              row.driver?.user.locationUpdatedAt ??
              new Date(0)
            ).toISOString(),
          };
        }
      }

      const hasActiveTrip =
        tripByVehicle.has(row.id.toString()) ||
        (row.driverId != null && tripByDriver.has(row.driverId.toString()));
      const hasActiveDelivery =
        deliveryByVehicle.has(row.id.toString()) ||
        (row.driverId != null && deliveryByDriver.has(row.driverId.toString()));
      const resolved = resolveFleetMapStatus({
        stored: row.status,
        driverOnline: row.driver?.online,
        driverDuty: row.driver?.dutyStatus,
        hasActiveTrip,
        hasActiveDelivery,
      });
      counts[resolved.status] += 1;
      const presented = fix ? this.present(fix, false) : null;
      const lastUpdate = presented?.recordedAt ?? row.lastFixAt?.toISOString() ?? null;
      return {
        vehicleId: row.id.toString(),
        vehicleNumber: row.registrationNo,
        registrationNo: row.registrationNo,
        driverName: row.driver?.user.name ?? null,
        driverId: row.driverId?.toString() ?? null,
        vehicleType: row.category,
        category: row.category,
        status: resolved.status,
        statusLabel: resolved.label,
        districtId: row.districtId,
        currentLocation: presented
          ? {
              visible: true as const,
              lat: presented.lat,
              lng: presented.lng,
              heading: presented.heading,
              speed: presented.speed,
              stale: presented.stale,
            }
          : null,
        lastUpdate,
        location: presented,
      };
    });
    const vehiclesOut = status ? list.filter((row) => row.status === status) : list;
    return { vehicles: vehiclesOut, counts };
  }

  private async readRaw(driverId: bigint) {
    return this.parse(await this.redis.get(liveDriverKey(driverId)));
  }

  private async readWithFallback(
    driverId: bigint,
    userId?: bigint,
    user?: { lastLat?: unknown; lastLng?: unknown; locationUpdatedAt?: Date | null },
  ) {
    const hot = await this.readRaw(driverId);
    if (hot) {
      return hot;
    }
    const row =
      user ??
      (userId
        ? await this.prisma.user.findUnique({
            where: { id: userId },
            select: { lastLat: true, lastLng: true, locationUpdatedAt: true },
          })
        : null);
    if (row?.lastLat == null || row.lastLng == null) {
      return null;
    }
    return {
      driverId: driverId.toString(),
      vehicleId: null,
      bookingId: null,
      districtId: null,
      lat: Number(row.lastLat),
      lng: Number(row.lastLng),
      heading: null,
      speed: null,
      tripStatus: 'last_known',
      recordedAt: (row.locationUpdatedAt ?? new Date(0)).toISOString(),
    } satisfies LiveFix;
  }

  private parse(raw: string | null): LiveFix | null {
    if (!raw) {
      return null;
    }
    try {
      return JSON.parse(raw) as LiveFix;
    } catch {
      return null;
    }
  }

  private present(fix: LiveFix, persisted: boolean) {
    return {
      visible: true,
      lat: fix.lat,
      lng: fix.lng,
      heading: fix.heading,
      speed: fix.speed,
      tripStatus: fix.tripStatus,
      recordedAt: fix.recordedAt,
      timestamp: fix.recordedAt,
      lastKnown: true,
      stale: isStale(fix.recordedAt),
      persisted,
      driverId: fix.driverId,
      vehicleId: fix.vehicleId,
      bookingId: fix.bookingId,
    };
  }
}
