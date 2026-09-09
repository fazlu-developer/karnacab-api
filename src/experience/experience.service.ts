import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { Actor } from '../access/territory';
import { WalletsService } from '../wallets/wallets.service';
import { PaymentsService } from '../payments/payments.service';
import { toLifecycle } from '../ride-engine/booking-lifecycle';
import { indianMobile } from '../safety/safety.privacy';
import { FamilyMemberDto } from './dto/experience.dto';
import { NotificationsService } from '../notify/notifications.service';
import { DomainEvents } from '../common/domain-events.service';
import { couponMessage, quoteCoupon } from './coupon.engine';

@Injectable()
export class ExperienceService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly wallets: WalletsService,
    private readonly payments: PaymentsService,
    private readonly inbox: NotificationsService,
    private readonly events: DomainEvents,
  ) {}

  catalog() {
    return {
      tools: [
        'profile',
        'booking_history',
        'upcoming_rides',
        'scheduled_rides',
        'active_ride',
        'parcel_history',
        'travel_bookings',
        'bulk_bookings',
        'corporate_bookings',
        'wallet',
        'coupons',
        'offers',
        'notifications',
        'ratings',
        'complaints',
        'invoices',
        'saved_locations',
        'emergency_contacts',
        'family_booking',
        'book_for_another_person',
      ],
      passengerFields: ['passengerName', 'passengerMobile', 'pickup', 'destination', 'instructions'],
      note: 'The booker pays. The passenger is who the driver meets. Drivers never receive the booker phone when booking for someone else.',
    };
  }

  async overview(actor: Actor) {
    this.assertCustomer(actor);
    const now = new Date();
    const [
      wallet,
      coupons,
      notifications,
      family,
      invoices,
      user,
      bookings,
      parcels,
      travel,
      bulk,
      places,
      ratings,
      tickets,
    ] = await Promise.all([
      this.wallets.mine(actor.userId, actor.role),
      this.coupons(actor),
      this.notifications(actor),
      this.family(actor),
      this.payments.invoices(actor),
      this.prisma.user.findUnique({
        where: { id: actor.userId },
        select: {
          id: true,
          name: true,
          email: true,
          phone: true,
          lastAddress: true,
          stateId: true,
          districtId: true,
          emergencyName: true,
          emergencyPhone: true,
          role: true,
        },
      }),
      this.prisma.booking.findMany({
        where: { customerId: actor.userId },
        orderBy: { createdAt: 'desc' },
        take: 80,
      }),
      this.prisma.parcelShipment.findMany({
        where: { customerId: actor.userId },
        orderBy: { createdAt: 'desc' },
        take: 40,
      }),
      this.prisma.travelBooking.findMany({
        where: { customerId: actor.userId },
        orderBy: { createdAt: 'desc' },
        take: 40,
      }),
      this.prisma.bulkBooking.findMany({
        where: { customerId: actor.userId },
        orderBy: { createdAt: 'desc' },
        take: 40,
      }),
      this.prisma.userPlace.findMany({
        where: { userId: actor.userId, kind: 'SAVED' },
        orderBy: { createdAt: 'desc' },
        take: 20,
      }),
      this.prisma.bookingRating.findMany({
        where: { booking: { customerId: actor.userId } },
        include: { booking: { select: { publicRef: true } } },
        orderBy: { id: 'desc' },
        take: 40,
      }),
      this.prisma.supportTicket.findMany({
        where: { userId: actor.userId },
        orderBy: { createdAt: 'desc' },
        take: 40,
      }),
    ]);
    const presentRide = (row: (typeof bookings)[number]) => ({
      id: row.id.toString(),
      publicRef: row.publicRef,
      status: row.status,
      product: row.product,
      pickup: row.pickupText,
      drop: row.dropText,
      bucket: this.bucket(row.status, row.product, row.scheduledAt),
      bookedForOther: row.bookedForOther,
      passengerName: row.passengerName,
      corporate: row.corporateAccountId != null,
    });
    const grouped = {
      history: [] as ReturnType<typeof presentRide>[],
      upcoming: [] as ReturnType<typeof presentRide>[],
      scheduled: [] as ReturnType<typeof presentRide>[],
      active: [] as ReturnType<typeof presentRide>[],
      corporate: [] as ReturnType<typeof presentRide>[],
    };
    for (const row of bookings) {
      const view = presentRide(row);
      grouped[view.bucket as 'history' | 'upcoming' | 'scheduled' | 'active'].push(view);
      if (row.corporateAccountId) {
        grouped.corporate.push(view);
      }
    }
    return {
      catalog: this.catalog(),
      profile: user
        ? {
            id: user.id.toString(),
            name: user.name,
            email: user.email,
            phone: user.phone,
            address: user.lastAddress,
            stateId: user.stateId,
            districtId: user.districtId,
            role: user.role,
          }
        : null,
      emergency: { name: user?.emergencyName ?? null, phone: user?.emergencyPhone ?? null },
      wallet: wallet.wallets[0] ?? null,
      coupons: coupons.coupons,
      offers: coupons.offers,
      notifications: notifications.notifications.slice(0, 10),
      unreadNotifications: notifications.unread,
      family: family.members,
      invoices: invoices.invoices.slice(0, 10),
      activeRide: grouped.active[0] ?? null,
      upcoming: grouped.upcoming,
      scheduled: grouped.scheduled,
      history: grouped.history,
      corporate: grouped.corporate,
      guest: bookings.filter((row) => row.bookedForOther).map(presentRide),
      parcels: parcels.map((row) => ({
        id: row.id.toString(),
        publicRef: row.publicRef,
        status: row.status,
        pickup: row.pickupText,
        drop: row.dropText,
      })),
      travel: travel.map((row) => ({
        id: row.id.toString(),
        publicRef: row.publicRef,
        status: row.status,
      })),
      bulk: bulk.map((row) => ({
        id: row.id.toString(),
        publicRef: row.publicRef,
        status: row.status,
      })),
      places: places.map((row) => ({
        id: row.id.toString(),
        title: row.title,
        address: row.address,
        kind: row.kind,
      })),
      ratings: ratings.map((row) => ({
        id: row.id.toString(),
        bookingId: row.bookingId.toString(),
        publicRef: row.booking.publicRef,
        stars: row.stars,
        comment: row.comment,
      })),
      complaints: tickets.map((row) => ({
        id: row.id.toString(),
        publicRef: row.publicRef,
        kind: row.kind,
        status: row.status,
        subject: row.subject,
      })),
      now: now.toISOString(),
    };
  }

  async coupons(actor: Actor) {
    this.assertCustomer(actor);
    const now = new Date();
    const rows = await this.prisma.coupon.findMany({
      where: {
        active: true,
        startsOn: { lte: now },
        endsOn: { gte: now },
        OR: [{ districtId: null }, ...(actor.districtId ? [{ districtId: actor.districtId }] : [])],
      },
      orderBy: { code: 'asc' },
    });
    const coupons = rows.map((row) => this.presentCoupon(row));
    return {
      coupons,
      offers: coupons.map((row) => ({
        id: row.id,
        title: row.title,
        subtitle: row.subtitle,
        cta: `Use ${row.code}`,
        code: row.code,
      })),
    };
  }

  async previewCoupon(actor: Actor, code: string, farePaise = 0, clientDiscount?: number) {
    this.assertCustomer(actor);
    if (clientDiscount != null) {
      throw new BadRequestException('Discount amounts from the application are not accepted. Coupons are quoted on the server.');
    }
    const applied = await this.quoteCoupon(actor, code, farePaise);
    return { ...applied, payablePaise: Math.max(0, farePaise - applied.discountPaise), clientDiscountIgnored: true };
  }

  async quoteCoupon(
    actor: Actor,
    code: string | undefined,
    farePaise: number,
    extras: { product?: string | null; stateId?: number | null; districtId?: number | null } = {},
  ) {
    const trimmed = (code ?? '').trim().toUpperCase();
    if (!trimmed) {
      return { couponId: null as bigint | null, code: null as string | null, discountPaise: 0, title: null as string | null };
    }
    const now = new Date();
    const row = await this.prisma.coupon.findFirst({
      where: { code: trimmed },
    });
    if (!row) {
      throw new BadRequestException('This coupon is not valid');
    }
    const user = await this.prisma.user.findUnique({
      where: { id: actor.userId },
      select: { role: true, stateId: true, districtId: true },
    });
    const [bookingCount, completedRides, usageCount, userUsageCount] = await Promise.all([
      this.prisma.booking.count({ where: { customerId: actor.userId } }),
      this.prisma.booking.count({ where: { customerId: actor.userId, status: 'COMPLETED' } }),
      this.prisma.couponRedemption.count({ where: { couponId: row.id } }),
      this.prisma.couponRedemption.count({ where: { couponId: row.id, userId: actor.userId } }),
    ]);
    const quoted = quoteCoupon(
      {
        code: row.code,
        title: row.title,
        active: row.active,
        kind: row.kind,
        percent: row.percent,
        amountPaise: row.amountPaise,
        maxDiscountPaise: row.maxDiscountPaise,
        minFarePaise: row.minFarePaise,
        product: row.product,
        stateId: row.stateId,
        districtId: row.districtId,
        audience: row.audience,
        usageLimit: row.usageLimit,
        userLimit: row.userLimit,
        startsOn: row.startsOn,
        endsOn: row.endsOn,
      },
      {
        farePaise,
        product: extras.product,
        stateId: extras.stateId ?? actor.stateId ?? user?.stateId ?? null,
        districtId: extras.districtId ?? actor.districtId ?? user?.districtId ?? null,
        role: user?.role ?? actor.role,
        bookingCount,
        completedRides,
        usageCount,
        userUsageCount,
        now,
      },
    );
    if (!quoted.ok) {
      throw new BadRequestException(couponMessage(quoted.reason));
    }
    return { couponId: row.id, code: row.code, discountPaise: quoted.discountPaise, title: row.title };
  }

  async redeemCoupon(userId: bigint, couponId: bigint, bookingId: bigint, discountPaise: number) {
    await this.prisma.couponRedemption.create({
      data: { couponId, userId, bookingId, discountPaise },
    });
    this.events.emit('coupon.applied', {
      userId: userId.toString(),
      bookingId: bookingId.toString(),
      amountPaise: discountPaise,
    });
  }

  async family(actor: Actor) {
    this.assertCustomer(actor);
    const rows = await this.prisma.familyMember.findMany({
      where: { userId: actor.userId },
      orderBy: { createdAt: 'desc' },
    });
    return { members: rows.map((row) => this.presentFamily(row)) };
  }

  async addFamily(actor: Actor, dto: FamilyMemberDto) {
    this.assertCustomer(actor);
    const phone = indianMobile(dto.phone);
    if (!phone) {
      throw new BadRequestException('Enter a valid 10-digit passenger mobile');
    }
    const row = await this.prisma.familyMember.create({
      data: {
        userId: actor.userId,
        name: dto.name.trim(),
        phone,
        relation: dto.relation?.trim() || null,
      },
    });
    return this.presentFamily(row);
  }

  async removeFamily(actor: Actor, id: bigint) {
    this.assertCustomer(actor);
    const row = await this.prisma.familyMember.findUnique({ where: { id } });
    if (!row || row.userId !== actor.userId) {
      throw new NotFoundException('Family member not found');
    }
    await this.prisma.familyMember.delete({ where: { id } });
    return { ok: true };
  }

  async loadFamilyMember(actor: Actor, id: bigint) {
    const row = await this.prisma.familyMember.findUnique({ where: { id } });
    if (!row || row.userId !== actor.userId) {
      throw new BadRequestException('Unknown family member');
    }
    return row;
  }

  async notifications(actor: Actor) {
    this.assertCustomer(actor);
    return this.inbox.list(actor);
  }

  async markRead(actor: Actor, id: bigint) {
    this.assertCustomer(actor);
    return this.inbox.markRead(actor, id);
  }

  async notify(userId: bigint, title: string, body: string, kind = 'info', entity?: { type: string; id: string }) {
    await this.inbox.notify({ userId, title, body, kind, entity });
  }

  bucket(status: string, product: string, scheduledAt?: Date | null) {
    const life = toLifecycle(status);
    if (life === 'completed' || life === 'cancelled') {
      return 'history';
    }
    if (life === 'driver_arriving' || life === 'driver_arrived' || life === 'started') {
      return 'active';
    }
    if (product === 'SCHEDULE' || (scheduledAt != null && scheduledAt.getTime() > Date.now())) {
      return 'scheduled';
    }
    return 'upcoming';
  }

  assertCustomer(actor: Actor) {
    if (actor.role !== UserRole.CUSTOMER && actor.role !== UserRole.CORPORATE && !actor.unrestricted) {
      throw new ForbiddenException('Customer account required');
    }
  }

  private presentFamily(row: { id: bigint; name: string; phone: string; relation: string | null }) {
    return { id: row.id.toString(), name: row.name, phone: row.phone, relation: row.relation };
  }

  private presentCoupon(row: {
    id: bigint;
    code: string;
    title: string;
    subtitle: string | null;
    kind?: string | null;
    percent: number;
    amountPaise: number;
    maxDiscountPaise?: number;
    minFarePaise: number;
    audience?: string | null;
    product?: string | null;
  }) {
    return {
      id: row.id.toString(),
      code: row.code,
      title: row.title,
      subtitle: row.subtitle,
      kind: row.kind ?? ((row.percent ?? 0) > 0 ? 'percent' : 'fixed'),
      percent: row.percent,
      amountRupees: row.amountPaise / 100,
      maxDiscountRupees: (row.maxDiscountPaise ?? 0) / 100,
      minFareRupees: row.minFarePaise / 100,
      audience: row.audience ?? 'all',
      product: row.product ?? null,
    };
  }
}
