import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { randomInt } from 'crypto';
import { ConfigService } from '@nestjs/config';
import { BookingStatus, Prisma, RideProduct, UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { QuotesService } from '../quotes/quotes.service';
import { DemoDriverService } from '../drivers/demo-driver.service';
import { DomainEvents } from '../common/domain-events.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';
import { CreateBookingDto } from './dto/create-booking.dto';
import { BookingLifecycleDto } from './dto/booking-lifecycle.dto';
import { persistFromDuty } from '../drivers/driver-duty';
import { driverCanReceiveOffers } from '../drivers/driver-offer-gate';
import {
  driverHasActiveJob,
  loadDriverOfferProfile,
  lockBookingRow,
  lockDriverRow,
  recordDecline,
} from '../drivers/driver-offer-profile';
import { presentRideOffer } from '../drivers/driver-request.presenter';
import { vehicleMatches, withinOfferRadius } from '../drivers/offer-eligibility';
import {
  nextLifecycle,
  OFFER_STATUSES,
  toDbStatus,
  toLifecycle,
} from '../ride-engine/booking-lifecycle';
import { presentBooking } from '../ride-engine/booking-presenter';
import { FareEngine } from '../ride-engine/fare.engine';
import { LocationService } from '../location/location.service';
import { WalletSettlementService } from '../wallets/wallet-settlement.service';
import { PaymentsService } from '../payments/payments.service';
import { ExperienceService } from '../experience/experience.service';
import { driverFacingPeople, resolveRidePeople } from '../experience/ride-people';
import { indianMobile } from '../safety/safety.privacy';
import { assertTripPin } from '../ride-engine/trip-otp';
import { withTripView } from '../ride-engine/trip.presenter';
import { RateBookingDto } from './dto/rate-booking.dto';

@Injectable()
export class BookingsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly quotes: QuotesService,
    private readonly demoDriver: DemoDriverService,
    private readonly config: ConfigService,
    private readonly events: DomainEvents,
    private readonly scopes: ScopeService,
    private readonly fares: FareEngine,
    private readonly locations: LocationService,
    private readonly walletSettlement: WalletSettlementService,
    private readonly payments: PaymentsService,
    private readonly experience: ExperienceService,
  ) {}

  async create(actor: Actor, dto: CreateBookingDto) {
    if (actor.role !== UserRole.CUSTOMER && actor.role !== UserRole.CORPORATE) {
      throw new ForbiddenException('Only customers can request a ride');
    }
    if (dto.product === RideProduct.ROUND_WAY && !dto.returnAt) {
      throw new BadRequestException('Round Way requires a return date and time');
    }
    if (dto.product === RideProduct.AIRPORT) {
      if (!dto.flightNumber?.trim()) {
        throw new BadRequestException('Airport transfer requires a flight number');
      }
      this.assertFuturePickup(dto.scheduledAt, 'Airport transfer requires pickup date and time');
    }
    if (dto.product === RideProduct.RAILWAY) {
      if (!dto.trainNumber?.trim()) {
        throw new BadRequestException('Railway transfer requires a train number');
      }
      this.assertFuturePickup(dto.scheduledAt, 'Railway transfer requires pickup date and time');
    }
    if (dto.product === RideProduct.MULTI_STOP) {
      const count = dto.stops?.length ?? 0;
      if (count < 1 || count > 3) {
        throw new BadRequestException('Multi-stop needs 1 to 3 stops plus destination');
      }
    }
    if (dto.product === RideProduct.SCHEDULE) {
      this.assertFuturePickup(dto.scheduledAt, 'Schedule ride requires a future pickup time');
    }
    if (dto.couponDiscountPaise != null || dto.discountPaise != null) {
      throw new BadRequestException(
        'Discount amounts from the application are not accepted. Send couponCode only; the server quotes the discount.',
      );
    }
    const customerId = actor.userId;
    const route = await this.quotes.resolveRoute({
      product: dto.product,
      distanceKm: dto.distanceKm ?? 1,
      pickupLat: dto.pickupLat,
      pickupLng: dto.pickupLng,
      dropLat: dto.dropLat,
      dropLng: dto.dropLng,
      polyline: dto.polyline,
      stops: dto.stops,
    });
    const distanceKm = route.distanceKm;
    const quote = await this.fares.quote({
          product: dto.product,
          category: dto.category,
          distanceKm,
          districtId: dto.districtId,
          waitMinutes: dto.waitMinutes,
          night: dto.night,
          tollPaise: dto.tollPaise,
          parkingPaise: dto.parkingPaise,
          hours: dto.hours,
          extraHours: dto.extraHours,
          stopCount: dto.stops?.length ?? dto.stopCount,
          roundTrip: dto.roundTrip,
          nightStayNights: dto.nightStayNights,
        });
    const coupon = await this.experience.quoteCoupon(actor, dto.couponCode, quote?.totalPaise ?? 0, {
      product: dto.product,
      districtId: dto.districtId,
    });
    const payablePaise = Math.max(0, (quote?.totalPaise ?? 0) - coupon.discountPaise);
    const quoted = quote
      ? {
          ...quote,
          totalPaise: payablePaise,
          totalRupees: payablePaise / 100,
          couponCode: coupon.code,
          couponDiscountPaise: coupon.discountPaise,
        }
      : null;
    const passenger = await this.resolvePassenger(actor, dto);

    const initialStatus = this.initialStatus(dto);

    const booking = await this.prisma.booking.create({
      data: {
        publicRef: `KC${Date.now().toString(36)}${randomInt(100, 999)}`.toUpperCase(),
        customerId,
        districtId: dto.districtId,
        product: dto.product,
        category: dto.category,
        pickupText: dto.pickupText,
        dropText: dto.dropText,
        pickupLat: dto.pickupLat,
        pickupLng: dto.pickupLng,
        dropLat: dto.dropLat,
        dropLng: dto.dropLng,
        polyline: route.polyline ?? dto.polyline,
        distanceKm,
        scheduledAt: dto.scheduledAt ? new Date(dto.scheduledAt) : undefined,
        returnAt: dto.returnAt ? new Date(dto.returnAt) : undefined,
        flightNumber: dto.flightNumber?.trim() || undefined,
        trainNumber: dto.trainNumber?.trim() || undefined,
        terminal: dto.terminal?.trim() || undefined,
        passengerName: passenger.name,
        passengerPhone: passenger.phone,
        instructions: passenger.instructions,
        bookedForOther: passenger.bookedForOther,
        familyMemberId: passenger.familyMemberId,
        couponId: coupon.couponId,
        couponDiscountPaise: coupon.discountPaise,
        quotePaise: quoted ? BigInt(quoted.totalPaise) : null,
        quoteSnapshot: quoted ? (quoted as Prisma.InputJsonValue) : undefined,
        status: initialStatus,
        startOtp: String(randomInt(1000, 9999)),
        endOtp: String(randomInt(1000, 9999)),
        stops: dto.stops?.length
          ? {
              create: dto.stops.map((stop, index) => ({
                seq: index + 1,
                label: stop.label,
                lat: stop.lat,
                lng: stop.lng,
              })),
            }
          : undefined,
      },
    });

    const demoAssign =
      this.config.get<boolean>('demo.autoAssign') === true &&
      initialStatus === BookingStatus.REQUESTED;
    const nearby = demoAssign
      ? await this.demoDriver.placeNearby(
          dto.pickupLat ?? 25.5941,
          dto.pickupLng ?? 85.1376,
          dto.districtId,
        )
      : null;

    if (nearby) {
      await this.prisma.booking.update({
        where: { id: booking.id },
        data: {
          status: BookingStatus.ASSIGNED,
          driverId: nearby.driverId,
          vehicleId: nearby.vehicleId,
        },
      });
    }

    const assigned = await this.prisma.booking.findUnique({
      where: { id: booking.id },
      include: {
        customer: { select: { name: true, phone: true } },
        driver: { include: { user: { select: { name: true, phone: true, lastLat: true, lastLng: true } } } },
        stops: { orderBy: { seq: 'asc' } },
      },
    });

    const presented = assigned
      ? this.present(
          assigned,
          assigned.customer,
          assigned.driver?.user,
          assigned.driver?.user
            ? { lat: assigned.driver.user.lastLat, lng: assigned.driver.user.lastLng }
            : nearby,
          quoted,
          actor.role,
        )
      : this.present(booking, undefined, undefined, nearby, quoted, actor.role);

    const result = await this.decorate(presented, assigned ?? booking, actor.role);
    if (coupon.couponId) {
      await this.experience.redeemCoupon(actor.userId, coupon.couponId, booking.id, coupon.discountPaise);
    }
    this.events.emit('booking.created', {
      bookingId: result.id,
      customerId: customerId.toString(),
      product: dto.product,
      districtId: dto.districtId ?? null,
    });
    return {
      ...result,
      fareQuote: quote,
      notifiedDrivers: nearby ? 1 : 0,
      note: nearby
        ? 'A dummy driver is nearby and was assigned for this demo ride.'
        : dto.product === RideProduct.SCHEDULE ||
            dto.product === RideProduct.AIRPORT ||
            dto.product === RideProduct.RAILWAY
          ? 'Confirmed. A driver will be assigned closer to pickup.'
          : 'Looking for a nearby driver.',
    };
  }

  async list(actor: Actor) {
    const rows = await this.prisma.booking.findMany({
      where: this.scopes.bookingWhere(actor),
      orderBy: { createdAt: 'desc' },
      take: 50,
      include: {
        stops: { orderBy: { seq: 'asc' } },
        customer: { select: { name: true, phone: true } },
        ratings: true,
      },
    });
    return Promise.all(
      rows.map((row) =>
        this.decorate(this.present(row, row.customer, undefined, null, null, actor.role) as Record<string, unknown>, row, actor.role),
      ),
    );
  }

  async one(actor: Actor, bookingId: bigint) {
    const booking = await this.loadScoped(actor, bookingId);
    const payload = await this.decorate(
      this.present(
        booking,
        booking.customer,
        booking.driver?.user,
        booking.driver?.user
          ? { lat: booking.driver.user.lastLat, lng: booking.driver.user.lastLng }
          : null,
        null,
        actor.role,
      ),
      booking,
      actor.role,
    );
    const live = await this.locations.forBooking(actor, bookingId);
    const current = payload as { driverLat?: number | null; driverLng?: number | null };
    return {
      ...payload,
      driverLat: live.location?.lat ?? current.driverLat,
      driverLng: live.location?.lng ?? current.driverLng,
      driverHeading: live.location?.heading ?? null,
      driverSpeed: live.location?.speed ?? null,
      locationAt: live.location?.recordedAt ?? null,
      locationStale: live.location?.stale ?? true,
      locationVisible: live.visible,
    };
  }

  async live(actor: Actor, bookingId: bigint) {
    return this.locations.forBooking(actor, bookingId);
  }

  async offersForDriver(actor: Actor) {
    if (actor.role !== UserRole.DRIVER) {
      throw new ForbiddenException('Driver role required');
    }
    if (!(await driverCanReceiveOffers(this.prisma, actor.userId))) {
      return { offers: [] };
    }
    const profile = await loadDriverOfferProfile(this.prisma, actor.userId);
    if (!profile) {
      return { offers: [] };
    }
    const since = new Date(Date.now() - 15 * 60 * 1000);
    const districtIds = [
      ...new Set(
        [actor.districtId, ...profile.districtIds].filter((id): id is number => id != null),
      ),
    ];
    const rows = await this.prisma.booking.findMany({
      where: {
        status: { in: OFFER_STATUSES },
        driverId: null,
        createdAt: { gte: since },
        ...(profile.declinedRideIds.length
          ? { id: { notIn: profile.declinedRideIds } }
          : {}),
        ...(districtIds.length > 0
          ? { OR: [{ districtId: { in: districtIds } }, { districtId: null }] }
          : {}),
      },
      include: {
        customer: { select: { name: true, phone: true } },
        payments: { orderBy: { createdAt: 'desc' }, take: 1, select: { method: true } },
      },
      orderBy: { createdAt: 'desc' },
      take: 40,
    });
    return {
      offers: rows
        .filter((row) => this.rideEligible(profile, row))
        .slice(0, 20)
        .map((row) => {
          const people = this.ridePeople(row, row.customer);
          return presentRideOffer(this.present(row, row.customer, undefined, null, null, actor.role), {
            customerName: people.passenger.name,
            customerPhone: null,
            paymentMethod: row.payments[0]?.method,
            assigned: false,
          });
        }),
    };
  }

  async accept(userId: bigint, bookingId: bigint) {
    const profile = await loadDriverOfferProfile(this.prisma, userId);
    if (!profile) {
      throw new ForbiddenException('Driver profile required');
    }
    if (!(await driverCanReceiveOffers(this.prisma, userId))) {
      throw new ForbiddenException('Not available for trip requests');
    }

    const booking = await this.prisma.$transaction(
      async (tx) => {
        await lockDriverRow(tx, profile.driverId);
        if (await driverHasActiveJob(tx, profile.driverId)) {
          throw new ConflictException('Finish your current job before accepting another');
        }
        const locked = await lockBookingRow(tx, bookingId);
        if (!locked) {
          throw new NotFoundException('Booking not found');
        }
        if (locked.driver_id != null) {
          throw new ConflictException('This ride was taken by another driver');
        }
        const row = await tx.booking.findUnique({
          where: { id: bookingId },
          include: {
            customer: { select: { name: true, phone: true } },
            payments: { orderBy: { createdAt: 'desc' }, take: 1, select: { method: true } },
          },
        });
        if (!row || !OFFER_STATUSES.includes(row.status)) {
          throw new ConflictException('This ride is no longer available');
        }
        if (!this.rideEligible(profile, row)) {
          throw new ForbiddenException('You are not eligible for this ride');
        }
        const vehicleId =
          profile.vehicleIdsByCategory.get(String(row.category)) ??
          (profile.categories.length ? null : profile.firstVehicleId);
        const updated = await tx.booking.update({
          where: { id: bookingId },
          data: {
            status: BookingStatus.ASSIGNED,
            driverId: profile.driverId,
            vehicleId: vehicleId ?? undefined,
          },
          include: { customer: { select: { name: true, phone: true } } },
        });
        await tx.driver.update({
          where: { id: profile.driverId },
          data: persistFromDuty('on_trip'),
        });
        return updated;
      },
      { isolationLevel: Prisma.TransactionIsolationLevel.ReadCommitted },
    );

    const people = this.ridePeople(booking, booking.customer);
    this.events.emit('booking.lifecycle', {
      bookingId: booking.id.toString(),
      action: 'assign',
      lifecycle: 'driver_assigned',
    });
    return this.decorate(
      presentRideOffer(
        this.present(booking, booking.customer, { name: profile.name, phone: profile.phone }, null, null, UserRole.DRIVER),
        {
          customerName: people.passenger.name,
          customerPhone: people.passenger.phone,
          assigned: true,
        },
      ),
      booking,
      UserRole.DRIVER,
    );
  }

  async reject(userId: bigint, bookingId: bigint) {
    const driver = await this.prisma.driver.findUnique({ where: { userId } });
    if (!driver) {
      throw new ForbiddenException('Driver profile required');
    }
    const booking = await this.prisma.booking.findUnique({
      where: { id: bookingId },
      include: { customer: { select: { name: true, phone: true } } },
    });
    if (!booking) {
      throw new NotFoundException('Booking not found');
    }
    if (booking.driverId == null) {
      await recordDecline(this.prisma, driver.id, 'ride', bookingId);
    }
    return presentRideOffer(this.present(booking, booking.customer, undefined, null, null, UserRole.DRIVER), {
      customerName: this.ridePeople(booking, booking.customer).passenger.name,
      customerPhone: this.ridePeople(booking, booking.customer).passenger.phone,
      assigned: false,
    });
  }

  private rideEligible(
    profile: Awaited<ReturnType<typeof loadDriverOfferProfile>>,
    row: {
      category: string;
      pickupLat?: unknown;
      pickupLng?: unknown;
    },
  ) {
    if (!profile) {
      return false;
    }
    return (
      vehicleMatches(profile.categories, String(row.category)) &&
      withinOfferRadius({
        driverLat: profile.lat,
        driverLng: profile.lng,
        pickupLat: row.pickupLat == null ? null : Number(row.pickupLat),
        pickupLng: row.pickupLng == null ? null : Number(row.pickupLng),
        radiusKm: profile.radiusKm,
      })
    );
  }

  async reschedule(actor: Actor, bookingId: bigint, scheduledAtIso: string) {
    const booking = await this.loadScoped(actor, bookingId);
    if (booking.product !== RideProduct.SCHEDULE) {
      throw new BadRequestException('Only scheduled rides can be rescheduled');
    }
    const lifecycle = toLifecycle(booking.status);
    if (!['draft', 'pending', 'confirmed', 'driver_searching'].includes(lifecycle)) {
      throw new BadRequestException('This ride can no longer be rescheduled');
    }
    const scheduledAt = new Date(scheduledAtIso);
    if (Number.isNaN(scheduledAt.getTime()) || scheduledAt.getTime() <= Date.now()) {
      throw new BadRequestException('Pickup must be in the future');
    }
    const updated = await this.prisma.booking.update({
      where: { id: bookingId },
      data: { scheduledAt, status: BookingStatus.CONFIRMED },
      include: {
        customer: { select: { name: true, phone: true } },
        driver: { include: { user: { select: { name: true, phone: true, lastLat: true, lastLng: true } } } },
      },
    });
    this.events.emit('booking.lifecycle', {
      bookingId: bookingId.toString(),
      action: 'reschedule',
      lifecycle: 'confirmed',
    });
    return this.decorate(this.present(
      updated,
      updated.customer,
      updated.driver?.user,
      updated.driver?.user
        ? { lat: updated.driver.user.lastLat, lng: updated.driver.user.lastLng }
        : null,
      null,
      actor.role,
    ), updated, actor.role);
  }

  async transition(actor: Actor, bookingId: bigint, dto: BookingLifecycleDto) {
    const booking = await this.loadScoped(actor, bookingId);
    let next: ReturnType<typeof nextLifecycle>;
    try {
      next = nextLifecycle(booking.status, dto.action);
    } catch (err) {
      throw new BadRequestException(err instanceof Error ? err.message : 'Invalid transition');
    }

    if (dto.action === 'start') {
      assertTripPin('start', booking.startOtp, dto.otp);
    }
    if (dto.action === 'complete') {
      assertTripPin('end', booking.endOtp, dto.otp);
    }

    if (actor.role === UserRole.DRIVER) {
      if (!booking.driverId) {
        throw new ForbiddenException('Booking is not assigned to a driver');
      }
      if (actor.driverId !== booking.driverId) {
        throw new ForbiddenException('This booking is assigned to another driver');
      }
    }

    const now = new Date();
    const updated = await this.prisma.$transaction(async (tx) => {
      const data: Prisma.BookingUpdateInput = { status: toDbStatus(next) };
      if (dto.action === 'start') {
        data.tripStartedAt = now;
        if (!booking.endOtp) {
          data.endOtp = String(randomInt(1000, 9999));
        }
      }
      if (dto.action === 'complete') {
        data.tripEndedAt = now;
      }
      const row = await tx.booking.update({
        where: { id: bookingId },
        data,
        include: {
          customer: { select: { name: true, phone: true } },
          driver: { include: { user: { select: { name: true, phone: true, lastLat: true, lastLng: true } } } },
          ratings: true,
        },
      });
      if (row.driverId && (next === 'completed' || next === 'cancelled')) {
        await tx.driver.update({
          where: { id: row.driverId },
          data: persistFromDuty('online'),
        });
      } else if (row.driverId) {
        await tx.driver.update({
          where: { id: row.driverId },
          data: persistFromDuty('on_trip'),
        });
      }
      if (next === 'completed' && row.driverId) {
        await this.walletSettlement.settleCompletedTrip(tx, row);
        await this.payments.issueRideInvoice(tx, row);
      }
      if (next === 'cancelled') {
        await this.payments.levyCancellation(tx, row);
      }
      return row;
    });

    this.events.emit('booking.lifecycle', {
      bookingId: bookingId.toString(),
      action: dto.action,
      lifecycle: next,
    });

    return this.decorate(this.present(
      updated,
      updated.customer,
      updated.driver?.user,
      updated.driver?.user
        ? { lat: updated.driver.user.lastLat, lng: updated.driver.user.lastLng }
        : null,
      null,
      actor.role,
    ), updated, actor.role);
  }

  async rate(actor: Actor, bookingId: bigint, dto: RateBookingDto) {
    const booking = await this.loadScoped(actor, bookingId);
    if (toLifecycle(booking.status) !== 'completed') {
      throw new BadRequestException('Rate the trip after it is completed');
    }
    if (actor.role === UserRole.DRIVER && actor.driverId !== booking.driverId) {
      throw new ForbiddenException('This trip is assigned to another driver');
    }
    if (actor.role === UserRole.CUSTOMER && actor.userId !== booking.customerId) {
      throw new ForbiddenException('This trip belongs to another customer');
    }
    const fromRole = actor.role === UserRole.DRIVER ? 'DRIVER' : 'CUSTOMER';
    await this.prisma.bookingRating.upsert({
      where: { bookingId_fromRole: { bookingId, fromRole } },
      update: { stars: dto.stars, comment: dto.comment?.trim() || null },
      create: {
        bookingId,
        fromRole,
        stars: dto.stars,
        comment: dto.comment?.trim() || null,
      },
    });
    if (fromRole === 'CUSTOMER') {
      await this.experience.notify(
        booking.customerId,
        'Thanks for the rating',
        dto.comment?.trim() ? 'Review saved for this trip.' : `${dto.stars} star rating saved.`,
        'review',
        { type: 'booking', id: bookingId.toString() },
      );
    }
    return this.one(actor, bookingId);
  }

  private initialStatus(dto: CreateBookingDto): BookingStatus {
    if (dto.draft) {
      return BookingStatus.DRAFT;
    }
    if (
      (dto.product === RideProduct.SCHEDULE ||
        dto.product === RideProduct.AIRPORT ||
        dto.product === RideProduct.RAILWAY) &&
      dto.scheduledAt
    ) {
      return BookingStatus.CONFIRMED;
    }
    return BookingStatus.REQUESTED;
  }

  private assertFuturePickup(iso: string | undefined, message: string) {
    if (!iso) {
      throw new BadRequestException(message);
    }
    const when = new Date(iso);
    if (Number.isNaN(when.getTime()) || when.getTime() <= Date.now()) {
      throw new BadRequestException('Pickup must be in the future');
    }
  }

  private async loadScoped(actor: Actor, bookingId: bigint) {
    const booking = await this.prisma.booking.findUnique({
      where: { id: bookingId },
      include: {
        customer: { select: { name: true, phone: true } },
        district: { select: { id: true, stateId: true } },
        vehicle: { select: { fleetOwnerId: true } },
        driver: { include: { user: { select: { name: true, phone: true, lastLat: true, lastLng: true } } } },
        stops: { orderBy: { seq: 'asc' } },
        ratings: true,
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
    return booking;
  }

  private present(
    row: Parameters<typeof presentBooking>[0],
    customer?: { name: string; phone?: string | null },
    driverUser?: { name: string; phone?: string | null },
    nearby?: { lat?: unknown; lng?: unknown } | null,
    quote?: unknown,
    role?: UserRole,
  ) {
    return presentBooking(row, {
      customer,
      driverUser,
      nearby,
      quote,
      role,
    });
  }

  private async decorate(
    payload: Record<string, unknown>,
    row: {
      product: string;
      status: string;
      scheduledAt?: Date | null;
      startOtp?: string | null;
      endOtp?: string | null;
      tripStartedAt?: Date | null;
      tripEndedAt?: Date | null;
      pickupLat?: unknown;
      pickupLng?: unknown;
      dropLat?: unknown;
      dropLng?: unknown;
      bookedForOther?: boolean;
      passengerName?: string | null;
      passengerPhone?: string | null;
      instructions?: string | null;
      couponDiscountPaise?: number;
      customer?: { name: string; phone?: string | null };
      ratings?: Array<{ fromRole: string; stars: number; comment?: string | null }>;
      driver?: { user?: { lastLat?: unknown; lastLng?: unknown } } | null;
    },
    role?: UserRole,
  ) {
    const safety = await this.safetySettings();
    const peoplePayload = this.withPeople(payload, row, role ?? UserRole.CUSTOMER, row.customer);
    const trip = withTripView({
      ...peoplePayload,
      tripBucket: this.experience.bucket(row.status, row.product, row.scheduledAt),
    }, {
      role,
      startOtp: row.startOtp,
      endOtp: row.endOtp,
      tripStartedAt: row.tripStartedAt,
      tripEndedAt: row.tripEndedAt,
      pickupLat: row.pickupLat == null ? null : Number(row.pickupLat),
      pickupLng: row.pickupLng == null ? null : Number(row.pickupLng),
      dropLat: row.dropLat == null ? null : Number(row.dropLat),
      dropLng: row.dropLng == null ? null : Number(row.dropLng),
      driverLat: row.driver?.user?.lastLat == null ? null : Number(row.driver.user.lastLat),
      driverLng: row.driver?.user?.lastLng == null ? null : Number(row.driver.user.lastLng),
      safety,
      ratings: row.ratings,
    });
    if (row.product !== RideProduct.SCHEDULE) {
      return trip;
    }
    const policy = await this.fares.schedulePolicy();
    const reminderAt =
      row.scheduledAt != null
        ? new Date(row.scheduledAt.getTime() - policy.reminderMinutes * 60_000)
        : null;
    const assignAt =
      row.scheduledAt != null
        ? new Date(row.scheduledAt.getTime() - policy.assignLeadMinutes * 60_000)
        : null;
    const lifecycle = toLifecycle(row.status);
    return {
      ...trip,
      reminderAt,
      assignAt,
      confirmed: lifecycle === 'confirmed' || ['driver_searching', 'driver_assigned', 'driver_arriving', 'driver_arrived', 'started', 'completed'].includes(lifecycle),
      schedule: {
        ...policy,
        canReschedule: ['draft', 'pending', 'confirmed', 'driver_searching'].includes(lifecycle),
        canCancel: lifecycle !== 'completed' && lifecycle !== 'cancelled',
      },
    };
  }

  private async safetySettings() {
    const [sos, helpline] = await Promise.all([
      this.prisma.systemSetting.findUnique({ where: { key: 'driver_safety_sos' } }),
      this.prisma.systemSetting.findUnique({ where: { key: 'driver_safety_helpline' } }),
    ]);
    return {
      sos: sos?.value?.trim() || '112',
      helpline: helpline?.value?.trim() || '112',
    };
  }

  private async resolvePassenger(actor: Actor, dto: CreateBookingDto) {
    let name = dto.passengerName?.trim() || '';
    let phone = indianMobile(dto.passengerPhone);
    let familyMemberId: bigint | null = null;
    if (dto.familyMemberId) {
      const member = await this.experience.loadFamilyMember(actor, BigInt(dto.familyMemberId));
      name = member.name;
      phone = member.phone;
      familyMemberId = member.id;
    }
    const booker = await this.prisma.user.findUnique({
      where: { id: actor.userId },
      select: { name: true, phone: true },
    });
    const bookedForOther = Boolean(dto.bookForOther || familyMemberId || (phone && phone !== indianMobile(booker?.phone)));
    if (bookedForOther && (!name || !phone)) {
      throw new BadRequestException('Passenger name and mobile are required when booking for another person');
    }
    return {
      name: bookedForOther ? name : null,
      phone: bookedForOther ? phone : null,
      instructions: dto.instructions?.trim() || null,
      bookedForOther,
      familyMemberId,
    };
  }

  private ridePeople(
    row: {
      bookedForOther?: boolean | null;
      passengerName?: string | null;
      passengerPhone?: string | null;
      instructions?: string | null;
    },
    booker?: { name: string; phone?: string | null },
  ) {
    return resolveRidePeople({
      bookedForOther: row.bookedForOther,
      passengerName: row.passengerName,
      passengerPhone: row.passengerPhone,
      instructions: row.instructions,
      bookerName: booker?.name ?? 'Customer',
      bookerPhone: booker?.phone ?? null,
    });
  }

  private withPeople(
    presented: Record<string, unknown>,
    row: {
      bookedForOther?: boolean | null;
      passengerName?: string | null;
      passengerPhone?: string | null;
      instructions?: string | null;
      couponDiscountPaise?: number;
    },
    role: UserRole,
    booker?: { name: string; phone?: string | null },
  ) {
    const people = this.ridePeople(row, booker);
    const assigned = Boolean(presented.driverId);
    const facing = role === UserRole.DRIVER ? driverFacingPeople(people, assigned) : people;
    return {
      ...presented,
      bookedForOther: facing.bookedForOther,
      passenger: facing.passenger,
      booker: facing.booker,
      couponDiscountPaise: row.couponDiscountPaise ?? 0,
      customerName: role === UserRole.DRIVER ? facing.passenger?.name : presented.customerName,
      customerPhone: role === UserRole.DRIVER ? facing.passenger?.phone : presented.customerPhone,
      customer: role === UserRole.DRIVER ? facing.passenger : presented.customer,
    };
  }

}
