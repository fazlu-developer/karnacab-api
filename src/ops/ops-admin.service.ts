import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import {
  BookingStatus,
  LedgerDirection,
  Prisma,
  UserRole,
  UserStatus,
  WalletOwnerType,
} from '@prisma/client';
import { hash } from 'bcrypt';
import { PrismaService } from '../prisma/prisma.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';
import { AuditService } from '../common/audit.service';
import { LIVE_STATUSES, adminTripTrack } from '../ride-engine/booking-lifecycle';
import { BookingsService } from '../bookings/bookings.service';
import { ROLE_PERMISSIONS } from '../access/permissions';
import {
  CreateOpsUserDto,
  OpsBookingQueryDto,
  OpsUserQueryDto,
  PatchOpsDriverDto,
  PatchOpsUserDto,
  PatchSettingDto,
  UpsertCouponDto,
} from './dto/ops-admin.dto';

const SENSITIVE_SETTING = /secret|password|token|private/i;
const EXPIRY_WINDOW_MS = 30 * 24 * 3600 * 1000;

@Injectable()
export class OpsAdminService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly scopes: ScopeService,
    private readonly audit: AuditService,
    private readonly bookings: BookingsService,
  ) {}

  async dashboard(actor: Actor) {
    const bookingWhere = this.scopes.bookingWhere(actor);
    const driverWhere = this.scopes.driverWhere(actor);
    const userWhere = this.scopes.userWhere(actor);
    const now = new Date();
    const startToday = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
    const startMonth = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1));
    const expiryUntil = new Date(now.getTime() + EXPIRY_WINDOW_MS);

    const completedWhere: Prisma.BookingWhereInput = {
      AND: [bookingWhere, { status: BookingStatus.COMPLETED }],
    };

    const [
      totalUsers,
      activeUsers,
      totalDrivers,
      onlineDrivers,
      activeRides,
      completedRides,
      cancelledRides,
      todayRevenue,
      monthlyRevenue,
      driverEarnings,
      commissionEarned,
      parcelOrders,
      corporateBookings,
      bulkBookings,
      activeFleet,
      pendingKyc,
      pendingComplaints,
      expiringDocuments,
      activeAdvertisements,
    ] = await Promise.all([
      this.prisma.user.count({ where: userWhere }),
      this.prisma.user.count({ where: { AND: [userWhere, { status: UserStatus.ACTIVE }] } }),
      this.prisma.driver.count({ where: driverWhere }),
      this.prisma.driver.count({ where: { AND: [driverWhere, { online: true }] } }),
      this.prisma.booking.count({
        where: { AND: [bookingWhere, { status: { in: LIVE_STATUSES } }] },
      }),
      this.prisma.booking.count({ where: completedWhere }),
      this.prisma.booking.count({
        where: { AND: [bookingWhere, { status: BookingStatus.CANCELLED }] },
      }),
      this.prisma.booking.aggregate({
        where: { AND: [completedWhere, { createdAt: { gte: startToday } }] },
        _sum: { quotePaise: true },
      }),
      this.prisma.booking.aggregate({
        where: { AND: [completedWhere, { createdAt: { gte: startMonth } }] },
        _sum: { quotePaise: true },
      }),
      this.prisma.walletLedger.aggregate({
        where: {
          account: WalletOwnerType.DRIVER,
          direction: LedgerDirection.CREDIT,
          booking: bookingWhere,
        },
        _sum: { amountPaise: true },
      }),
      this.prisma.walletLedger.aggregate({
        where: { booking: bookingWhere },
        _sum: { commissionPaise: true },
      }),
      this.prisma.parcelShipment.count({ where: this.scopes.parcelWhere(actor) }),
      this.prisma.booking.count({
        where: { AND: [bookingWhere, { corporateAccountId: { not: null } }] },
      }),
      this.prisma.bulkBooking.count({
        where: actor.unrestricted ? {} : { customer: this.scopes.userWhere(actor) },
      }),
      this.prisma.fleetOwner.count({ where: this.scopes.fleetWhere(actor) }),
      this.prisma.driver.count({
        where: {
          AND: [driverWhere, { kycStatus: { in: ['pending', 'under_review'] } }],
        },
      }),
      this.prisma.supportTicket.count({
        where: {
          status: { notIn: ['resolved', 'closed'] },
          ...(actor.unrestricted
            ? {}
            : actor.districtId
              ? { districtId: actor.districtId }
              : actor.stateId
                ? { district: { stateId: actor.stateId } }
                : { id: { in: [] } }),
        },
      }),
      this.prisma.driverDocument.count({
        where: {
          expiresAt: { not: null, lte: expiryUntil },
          driver: driverWhere,
        },
      }),
      this.prisma.adCampaign.count({
        where: {
          status: 'published',
          startsOn: { lte: now },
          endsOn: { gte: now },
          ...(actor.unrestricted
            ? {}
            : actor.districtId
              ? { districtId: actor.districtId }
              : actor.stateId
                ? { district: { stateId: actor.stateId } }
                : { id: { in: [] } }),
        },
      }),
    ]);

    const todayPaise = Number(todayRevenue._sum.quotePaise ?? 0);
    const monthPaise = Number(monthlyRevenue._sum.quotePaise ?? 0);
    const earnPaise = Number(driverEarnings._sum.amountPaise ?? 0);
    const commissionPaise = Number(commissionEarned._sum.commissionPaise ?? 0);

    return {
      kpis: {
        totalUsers,
        activeUsers,
        totalDrivers,
        onlineDrivers,
        activeRides,
        completedRides,
        cancelledRides,
        todayRevenuePaise: todayPaise,
        monthlyRevenuePaise: monthPaise,
        todayRevenueRupees: todayPaise / 100,
        monthlyRevenueRupees: monthPaise / 100,
        driverEarningsPaise: earnPaise,
        driverEarningsRupees: earnPaise / 100,
        commissionEarnedPaise: commissionPaise,
        commissionEarnedRupees: commissionPaise / 100,
        parcelOrders,
        corporateBookings,
        bulkBookings,
        activeFleet,
        pendingKyc,
        pendingComplaints,
        expiringDocuments,
        activeAdvertisements,
      },
      scope: {
        role: actor.role,
        districtId: actor.districtId,
        stateId: actor.stateId,
        unrestricted: actor.unrestricted,
      },
    };
  }

  async listUsers(actor: Actor, query: OpsUserQueryDto) {
    const where: Prisma.UserWhereInput = {
      AND: [
        this.scopes.userWhere(actor),
        query.role ? { role: query.role } : {},
        query.status ? { status: query.status } : {},
        query.q
          ? {
              OR: [
                { name: { contains: query.q } },
                { email: { contains: query.q } },
                { phone: { contains: query.q } },
              ],
            }
          : {},
      ],
    };
    const rows = await this.prisma.user.findMany({
      where,
      orderBy: { createdAt: 'desc' },
      take: 200,
      select: this.userSelect(),
    });
    return { users: rows.map((row) => this.presentUser(row)) };
  }

  async getUser(actor: Actor, id: bigint) {
    const user = await this.prisma.user.findUnique({
      where: { id },
      select: {
        ...this.userSelect(),
        driver: {
          select: {
            id: true,
            kycStatus: true,
            online: true,
            ratingAvg: true,
            fleetOwnerId: true,
            licenseNo: true,
          },
        },
        wallets: { select: { id: true, ownerType: true, balancePaise: true } },
        bookings: {
          orderBy: { createdAt: 'desc' },
          take: 30,
          select: {
            id: true,
            publicRef: true,
            product: true,
            status: true,
            quotePaise: true,
            createdAt: true,
          },
        },
        payments: {
          orderBy: { createdAt: 'desc' },
          take: 30,
          select: {
            id: true,
            publicRef: true,
            method: true,
            kind: true,
            amountPaise: true,
            status: true,
            createdAt: true,
          },
        },
        supportTickets: {
          orderBy: { createdAt: 'desc' },
          take: 20,
          select: { id: true, publicRef: true, kind: true, status: true, subject: true },
        },
        pushDevices: {
          select: { id: true, platform: true, token: true, updatedAt: true },
        },
      },
    });
    if (!user) {
      throw new NotFoundException('User not found');
    }
    this.assertUserInScope(actor, user);
    const ratings = await this.prisma.bookingRating.findMany({
      where: { booking: { customerId: id } },
      take: 20,
      orderBy: { createdAt: 'desc' },
    });
    const loginActivity = await this.prisma.platformAuditEvent.findMany({
      where: { entityType: 'user', entityId: id.toString(), action: 'login' },
      orderBy: { createdAt: 'desc' },
      take: 20,
    });
    return {
      ...this.presentUser(user),
      driver: user.driver
        ? {
            id: user.driver.id.toString(),
            kycStatus: user.driver.kycStatus,
            online: user.driver.online,
            ratingAvg: Number(user.driver.ratingAvg),
            fleetOwnerId: user.driver.fleetOwnerId?.toString() ?? null,
            licenseNo: user.driver.licenseNo,
          }
        : null,
      wallets: user.wallets.map((row) => ({
        id: row.id.toString(),
        ownerType: row.ownerType,
        balancePaise: Number(row.balancePaise),
        balanceRupees: Number(row.balancePaise) / 100,
      })),
      bookings: user.bookings.map((row) => ({
        id: row.id.toString(),
        publicRef: row.publicRef,
        product: row.product,
        status: row.status,
        quotePaise: row.quotePaise == null ? null : Number(row.quotePaise),
        createdAt: row.createdAt.toISOString(),
      })),
      payments: user.payments.map((row) => ({
        id: row.id.toString(),
        publicRef: row.publicRef,
        method: row.method,
        kind: row.kind,
        amountPaise: Number(row.amountPaise),
        status: row.status,
        createdAt: row.createdAt.toISOString(),
      })),
      complaints: user.supportTickets.map((row) => ({
        id: row.id.toString(),
        publicRef: row.publicRef,
        kind: row.kind,
        status: row.status,
        subject: row.subject,
      })),
      ratings: ratings.map((row) => ({
        id: row.id.toString(),
        bookingId: row.bookingId.toString(),
        fromRole: row.fromRole,
        stars: row.stars,
        comment: row.comment,
      })),
      devices: user.pushDevices.map((row) => ({
        id: row.id.toString(),
        platform: row.platform,
        tokenHint: this.maskToken(row.token),
        updatedAt: row.updatedAt.toISOString(),
      })),
      loginActivity: loginActivity.map((row) => ({
        id: row.id.toString(),
        action: row.action,
        createdAt: row.createdAt.toISOString(),
      })),
    };
  }

  async createUser(actor: Actor, dto: CreateOpsUserDto) {
    this.assertWrite(actor);
    const email = dto.email.toLowerCase();
    const exists = await this.prisma.user.findUnique({ where: { email } });
    if (exists) {
      throw new ConflictException('Email already registered');
    }
    const role = dto.role ?? UserRole.CUSTOMER;
    const user = await this.prisma.user.create({
      data: {
        role,
        name: dto.name,
        email,
        phone: dto.phone,
        passwordHash: await hash(dto.password, 10),
        stateId: dto.stateId,
        districtId: dto.districtId,
        wallets:
          role === UserRole.CUSTOMER
            ? { create: { ownerType: WalletOwnerType.CUSTOMER, balancePaise: 0 } }
            : undefined,
      },
      select: this.userSelect(),
    });
    await this.audit.record({
      actorUserId: actor.userId,
      domain: 'users',
      action: 'created',
      entityType: 'user',
      entityId: user.id.toString(),
      payload: { role: user.role },
    });
    return this.presentUser(user);
  }

  async patchUser(actor: Actor, id: bigint, dto: PatchOpsUserDto) {
    this.assertWrite(actor);
    const existing = await this.prisma.user.findUnique({ where: { id } });
    if (!existing) {
      throw new NotFoundException('User not found');
    }
    this.assertUserInScope(actor, existing);
    if (dto.role && !actor.unrestricted) {
      throw new ForbiddenException('Only platform admins can change role');
    }
    const user = await this.prisma.user.update({
      where: { id },
      data: {
        name: dto.name,
        phone: dto.phone,
        role: dto.role,
        stateId: dto.stateId,
        districtId: dto.districtId,
        emergencyName: dto.emergencyName,
        emergencyPhone: dto.emergencyPhone,
      },
      select: this.userSelect(),
    });
    await this.audit.record({
      actorUserId: actor.userId,
      domain: 'users',
      action: 'updated',
      entityType: 'user',
      entityId: id.toString(),
    });
    return this.presentUser(user);
  }

  async patchUserStatus(actor: Actor, id: bigint, status: UserStatus) {
    this.assertWrite(actor);
    const existing = await this.prisma.user.findUnique({ where: { id } });
    if (!existing) {
      throw new NotFoundException('User not found');
    }
    this.assertUserInScope(actor, existing);
    const user = await this.prisma.user.update({
      where: { id },
      data: { status },
      select: this.userSelect(),
    });
    await this.audit.record({
      actorUserId: actor.userId,
      domain: 'users',
      action: status === UserStatus.SUSPENDED ? 'blocked' : 'status',
      entityType: 'user',
      entityId: id.toString(),
      payload: { status },
    });
    return this.presentUser(user);
  }

  async searchBookings(actor: Actor, query: OpsBookingQueryDto) {
    const extra: Prisma.BookingWhereInput[] = [];
    const q = query.q?.trim() || query.publicRef?.trim();
    if (q) {
      extra.push({
        OR: [
          { publicRef: { contains: q } },
          { passengerName: { contains: q } },
          { passengerPhone: { contains: q } },
          { customer: { name: { contains: q } } },
          { customer: { email: { contains: q } } },
          { customer: { phone: { contains: q } } },
        ],
      });
    }
    if (query.customer) {
      extra.push({
        OR: [
          { customer: { name: { contains: query.customer } } },
          { customer: { email: { contains: query.customer } } },
          { customer: { phone: { contains: query.customer } } },
        ],
      });
    }
    if (query.driver) {
      extra.push({
        driver: {
          user: {
            OR: [
              { name: { contains: query.driver } },
              { phone: { contains: query.driver } },
              { email: { contains: query.driver } },
            ],
          },
        },
      });
    }
    if (query.vehicle) {
      extra.push({ vehicle: { registrationNo: { contains: query.vehicle } } });
    }
    if (query.product) {
      extra.push({ product: query.product as never });
    }
    if (query.status) {
      extra.push({ status: query.status as BookingStatus });
    }
    if (query.districtId) {
      extra.push({ districtId: query.districtId });
    }
    if (query.stateId) {
      extra.push({ district: { stateId: query.stateId } });
    }
    if (query.fleetId) {
      extra.push({ vehicle: { fleetOwnerId: BigInt(query.fleetId) } });
    }
    if (query.franchiseId) {
      const franchise = await this.prisma.franchise.findUnique({
        where: { id: BigInt(query.franchiseId) },
        select: { districtId: true },
      });
      extra.push({ districtId: franchise?.districtId ?? -1 });
    }
    if (query.from || query.to) {
      extra.push({
        createdAt: {
          gte: query.from ? new Date(query.from) : undefined,
          lte: query.to ? new Date(query.to) : undefined,
        },
      });
    }
    if (query.paymentStatus) {
      extra.push({ payments: { some: { status: query.paymentStatus } } });
    }
    const rows = await this.prisma.booking.findMany({
      where: { AND: [this.scopes.bookingWhere(actor), ...extra] },
      orderBy: { createdAt: 'desc' },
      take: 100,
      include: {
        customer: { select: { name: true, phone: true, email: true } },
        driver: { include: { user: { select: { name: true, phone: true } } } },
        vehicle: { select: { registrationNo: true, fleetOwnerId: true } },
        district: { select: { id: true, name: true, stateId: true, state: { select: { name: true } } } },
        payments: { select: { status: true, method: true, amountPaise: true }, take: 5 },
      },
    });
    return {
      bookings: rows.map((row) => ({
        id: row.id.toString(),
        publicRef: row.publicRef,
        product: row.product,
        status: row.status,
        track: adminTripTrack(row.status),
        customer: row.customer.name,
        customerPhone: row.customer.phone,
        driver: row.driver?.user.name ?? null,
        vehicle: row.vehicle?.registrationNo ?? null,
        fleetId: row.vehicle?.fleetOwnerId?.toString() ?? null,
        districtId: row.districtId,
        district: row.district?.name ?? null,
        state: row.district?.state?.name ?? null,
        quotePaise: row.quotePaise == null ? null : Number(row.quotePaise),
        paymentStatus: row.payments[0]?.status ?? null,
        createdAt: row.createdAt.toISOString(),
      })),
    };
  }

  async bookingDetail(actor: Actor, id: bigint) {
    const payload = await this.bookings.one(actor, id);
    const audit = await this.prisma.platformAuditEvent.findMany({
      where: { entityType: 'booking', entityId: id.toString() },
      orderBy: { createdAt: 'asc' },
      take: 80,
    });
    return {
      ...payload,
      track: adminTripTrack(String((payload as { status?: string }).status ?? '')),
      statusLog: audit.map((row) => ({
        action: row.action,
        lifecycle: (row.payload as { lifecycle?: string } | null)?.lifecycle ?? null,
        createdAt: row.createdAt.toISOString(),
      })),
    };
  }

  async patchDriver(actor: Actor, driverId: bigint, dto: PatchOpsDriverDto) {
    this.assertWrite(actor);
    const driver = await this.prisma.driver.findUnique({
      where: { id: driverId },
      include: { user: true, vehicles: true },
    });
    if (!driver) {
      throw new NotFoundException('Driver not found');
    }
    this.scopes.assertDriver(actor, {
      id: driver.id,
      userId: driver.userId,
      fleetOwnerId: driver.fleetOwnerId,
      userDistrictId: driver.user.districtId,
      userStateId: driver.user.stateId,
      vehicleDistrictIds: driver.vehicles.map((row) => row.districtId),
      vehicleStateIds: [],
    });
    if (dto.action === 'suspend' || dto.action === 'reject') {
      await this.prisma.user.update({
        where: { id: driver.userId },
        data: { status: UserStatus.SUSPENDED },
      });
      if (dto.action === 'reject') {
        await this.prisma.driver.update({
          where: { id: driverId },
          data: { kycStatus: 'rejected', kycRejectedReason: dto.reason ?? 'Rejected by ops', online: false },
        });
      }
    }
    if (dto.action === 'activate' || dto.action === 'approve') {
      await this.prisma.user.update({
        where: { id: driver.userId },
        data: { status: UserStatus.ACTIVE },
      });
      if (dto.action === 'approve') {
        await this.prisma.driver.update({
          where: { id: driverId },
          data: { kycStatus: 'verified', kycRejectedReason: null },
        });
      }
    }
    if (dto.fleetOwnerId) {
      await this.prisma.driver.update({
        where: { id: driverId },
        data: { fleetOwnerId: BigInt(dto.fleetOwnerId) },
      });
    }
    if (dto.vehicleId) {
      await this.prisma.vehicle.update({
        where: { id: BigInt(dto.vehicleId) },
        data: { driverId },
      });
    }
    await this.audit.record({
      actorUserId: actor.userId,
      domain: 'drivers',
      action: dto.action ?? 'updated',
      entityType: 'driver',
      entityId: driverId.toString(),
    });
    return { ok: true, driverId: driverId.toString() };
  }

  async payments(actor: Actor) {
    const rows = await this.prisma.payment.findMany({
      where: actor.unrestricted ? {} : { booking: this.scopes.bookingWhere(actor) },
      orderBy: { createdAt: 'desc' },
      take: 100,
      select: {
        id: true,
        publicRef: true,
        method: true,
        kind: true,
        amountPaise: true,
        status: true,
        bookingId: true,
        createdAt: true,
      },
    });
    return {
      payments: rows.map((row) => ({
        id: row.id.toString(),
        publicRef: row.publicRef,
        method: row.method,
        kind: row.kind,
        amountPaise: Number(row.amountPaise),
        status: row.status,
        bookingId: row.bookingId?.toString() ?? null,
        createdAt: row.createdAt.toISOString(),
      })),
    };
  }

  async wallets(actor: Actor) {
    const rows = await this.prisma.wallet.findMany({
      where: actor.unrestricted ? {} : { owner: this.scopes.userWhere(actor) },
      take: 100,
      orderBy: { updatedAt: 'desc' },
      include: { owner: { select: { name: true, email: true, role: true } } },
    });
    return {
      wallets: rows.map((row) => ({
        id: row.id.toString(),
        ownerType: row.ownerType,
        ownerName: row.owner.name,
        ownerEmail: row.owner.email,
        role: row.owner.role,
        balancePaise: Number(row.balancePaise),
        balanceRupees: Number(row.balancePaise) / 100,
      })),
    };
  }

  async ratings(actor: Actor) {
    const rows = await this.prisma.bookingRating.findMany({
      where: { booking: this.scopes.bookingWhere(actor) },
      orderBy: { createdAt: 'desc' },
      take: 100,
      include: { booking: { select: { publicRef: true, product: true } } },
    });
    return {
      ratings: rows.map((row) => ({
        id: row.id.toString(),
        bookingRef: row.booking.publicRef,
        product: row.booking.product,
        fromRole: row.fromRole,
        stars: row.stars,
        comment: row.comment,
        createdAt: row.createdAt.toISOString(),
      })),
    };
  }

  async corporateAccounts(actor: Actor) {
    const rows = await this.prisma.corporateAccount.findMany({
      where: actor.unrestricted
        ? {}
        : actor.districtId
          ? { districtId: actor.districtId }
          : { ownerUserId: actor.userId },
      take: 100,
      include: { owner: { select: { name: true, email: true } } },
    });
    return {
      accounts: rows.map((row) => ({
        id: row.id.toString(),
        companyName: row.companyName,
        gstin: row.gstin,
        status: row.status,
        owner: row.owner.name,
        email: row.owner.email,
      })),
    };
  }

  async coupons() {
    const rows = await this.prisma.coupon.findMany({ orderBy: { createdAt: 'desc' }, take: 200 });
    return {
      coupons: rows.map((row) => ({
        id: row.id.toString(),
        code: row.code,
        title: row.title,
        percent: row.percent,
        amountPaise: row.amountPaise,
        maxDiscountPaise: row.maxDiscountPaise,
        kind: row.kind,
        audience: row.audience,
        product: row.product,
        active: row.active,
        startsOn: row.startsOn.toISOString(),
        endsOn: row.endsOn.toISOString(),
        districtId: row.districtId,
      })),
    };
  }

  async upsertCoupon(actor: Actor, dto: UpsertCouponDto) {
    this.assertAdmin(actor);
    const row = await this.prisma.coupon.upsert({
      where: { code: dto.code.toUpperCase() },
      update: {
        title: dto.title,
        subtitle: dto.subtitle,
        kind: dto.kind ?? ((dto.percent ?? 0) > 0 ? 'percent' : 'fixed'),
        percent: dto.percent ?? 0,
        amountPaise: dto.amountPaise ?? 0,
        maxDiscountPaise: dto.maxDiscountPaise ?? 0,
        minFarePaise: dto.minFarePaise ?? 0,
        product: dto.product,
        stateId: dto.stateId,
        startsOn: new Date(dto.startsOn),
        endsOn: new Date(dto.endsOn),
        active: dto.active ?? true,
        districtId: dto.districtId,
        audience: dto.audience ?? 'all',
        usageLimit: dto.usageLimit ?? 0,
        userLimit: dto.userLimit ?? 0,
      },
      create: {
        code: dto.code.toUpperCase(),
        title: dto.title,
        subtitle: dto.subtitle,
        kind: dto.kind ?? ((dto.percent ?? 0) > 0 ? 'percent' : 'fixed'),
        percent: dto.percent ?? 0,
        amountPaise: dto.amountPaise ?? 0,
        maxDiscountPaise: dto.maxDiscountPaise ?? 0,
        minFarePaise: dto.minFarePaise ?? 0,
        product: dto.product,
        stateId: dto.stateId,
        startsOn: new Date(dto.startsOn),
        endsOn: new Date(dto.endsOn),
        active: dto.active ?? true,
        districtId: dto.districtId,
        audience: dto.audience ?? 'all',
        usageLimit: dto.usageLimit ?? 0,
        userLimit: dto.userLimit ?? 0,
      },
    });
    return { id: row.id.toString(), code: row.code, active: row.active };
  }

  async locations() {
    const states = await this.prisma.state.findMany({
      orderBy: { name: 'asc' },
      include: { districts: { orderBy: { name: 'asc' }, select: { id: true, name: true } } },
    });
    return {
      states: states.map((state) => ({
        id: state.id,
        name: state.name,
        districts: state.districts,
      })),
    };
  }

  async settings() {
    const rows = await this.prisma.systemSetting.findMany({ orderBy: { key: 'asc' } });
    return {
      settings: rows
        .filter((row) => !SENSITIVE_SETTING.test(row.key))
        .map((row) => ({ key: row.key, value: row.value, updatedAt: row.updatedAt.toISOString() })),
    };
  }

  async patchSetting(actor: Actor, dto: PatchSettingDto) {
    this.assertAdmin(actor);
    if (SENSITIVE_SETTING.test(dto.key)) {
      throw new BadRequestException('This setting cannot be edited here');
    }
    const row = await this.prisma.systemSetting.upsert({
      where: { key: dto.key },
      update: { value: dto.value },
      create: { key: dto.key, value: dto.value },
    });
    await this.audit.record({
      actorUserId: actor.userId,
      domain: 'platform',
      action: 'settings',
      entityType: 'system_setting',
      entityId: dto.key,
    });
    return { key: row.key, value: row.value };
  }

  async auditLog(actor: Actor) {
    this.assertAdmin(actor);
    const rows = await this.prisma.platformAuditEvent.findMany({
      orderBy: { createdAt: 'desc' },
      take: 200,
    });
    return {
      events: rows.map((row) => ({
        id: row.id.toString(),
        actorUserId: row.actorUserId?.toString() ?? null,
        domain: row.domain,
        action: row.action,
        entityType: row.entityType,
        entityId: row.entityId,
        createdAt: row.createdAt.toISOString(),
      })),
    };
  }

  rolesCatalog() {
    return {
      roles: Object.entries(ROLE_PERMISSIONS).map(([role, permissions]) => ({
        role,
        permissions,
      })),
      note: 'Permissions follow users.role. Territory is enforced in Nest, not the browser.',
    };
  }

  private userSelect() {
    return {
      id: true,
      role: true,
      status: true,
      name: true,
      email: true,
      phone: true,
      gender: true,
      lastAddress: true,
      stateId: true,
      districtId: true,
      emergencyName: true,
      createdAt: true,
      updatedAt: true,
    } as const;
  }

  private presentUser(row: {
    id: bigint;
    role: UserRole;
    status: UserStatus;
    name: string;
    email: string;
    phone: string | null;
    gender?: unknown;
    lastAddress?: string | null;
    stateId: number | null;
    districtId: number | null;
    emergencyName?: string | null;
    createdAt: Date;
    updatedAt: Date;
  }) {
    return {
      id: row.id.toString(),
      role: row.role,
      status: row.status,
      name: row.name,
      email: row.email,
      phone: row.phone,
      gender: row.gender ?? null,
      lastAddress: row.lastAddress ?? null,
      stateId: row.stateId,
      districtId: row.districtId,
      emergencyName: row.emergencyName ?? null,
      createdAt: row.createdAt.toISOString(),
      updatedAt: row.updatedAt.toISOString(),
    };
  }

  private maskToken(token: string) {
    if (token.length < 12) {
      return '••••';
    }
    return `${token.slice(0, 6)}…${token.slice(-4)}`;
  }

  private assertUserInScope(actor: Actor, user: { districtId: number | null; stateId: number | null; id: bigint }) {
    if (actor.unrestricted) {
      return;
    }
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null && user.stateId === actor.stateId) {
      return;
    }
    if (
      (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) &&
      actor.districtId != null &&
      user.districtId === actor.districtId
    ) {
      return;
    }
    if (user.id === actor.userId) {
      return;
    }
    throw new ForbiddenException('User is outside your territory');
  }

  private assertWrite(actor: Actor) {
    if (
      !actor.unrestricted &&
      actor.role !== UserRole.STATE_HEAD &&
      actor.role !== UserRole.DISTRICT_HEAD
    ) {
      throw new ForbiddenException('Operator write access required');
    }
  }

  private assertAdmin(actor: Actor) {
    if (!actor.unrestricted) {
      throw new ForbiddenException('Platform admin required');
    }
  }
}
