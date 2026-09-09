import { ForbiddenException, Injectable, NotFoundException } from '@nestjs/common';
import { BookingStatus, LedgerDirection, WalletOwnerType } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { KycService } from '../kyc/kyc.service';
import { WalletsService } from '../wallets/wallets.service';
import { alertsForDocument } from './driver-document-alerts';
import { DriverCareService } from './driver-care.service';
import { OFFER_STATUSES } from '../ride-engine/booking-lifecycle';
import {
  ACTIVE_PARCEL_STATUSES,
  ACTIVE_RIDE_STATUSES,
  DUTY_LABELS,
  DutyStatus,
  canReceiveOffers,
  offerBlockReason,
  persistFromDuty,
  resolveDutyStatus,
} from './driver-duty';

@Injectable()
export class DriverDashboardService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly kyc: KycService,
    private readonly wallets: WalletsService,
    private readonly care: DriverCareService,
  ) {}

  async summary(userId: bigint) {
    const driver = await this.prisma.driver.findUnique({
      where: { userId },
      include: {
        user: { select: { name: true, phone: true, email: true, status: true } },
        vehicles: true,
      },
    });
    if (!driver) {
      throw new ForbiddenException('Driver profile required');
    }

    const kyc = await this.kyc.snapshot(userId);
    const [activeRide, activeParcel] = await Promise.all([
      this.prisma.booking.findFirst({
        where: { driverId: driver.id, status: { in: ACTIVE_RIDE_STATUSES } },
        orderBy: { updatedAt: 'desc' },
      }),
      this.prisma.parcelShipment.findFirst({
        where: { driverId: driver.id, status: { in: ACTIVE_PARCEL_STATUSES } },
        orderBy: { updatedAt: 'desc' },
      }),
    ]);

    const duty = resolveDutyStatus({
      storedDuty: driver.dutyStatus,
      onlineFlag: driver.online,
      userStatus: driver.user.status,
      vehicleStatuses: driver.vehicles.map((row) => row.status),
      canGoOnline: kyc.canGoOnline,
      hasActiveRide: Boolean(activeRide),
      hasActiveParcel: Boolean(activeParcel),
    });

    const persist = persistFromDuty(duty);
    if (driver.dutyStatus !== persist.dutyStatus || driver.online !== persist.online) {
      await this.prisma.driver.update({
        where: { id: driver.id },
        data: persist,
      });
    }

    const receive = canReceiveOffers(duty, kyc.canGoOnline);
    const todayStart = startOfTodayIst();
    const [
      todayRides,
      todayParcels,
      pendingCount,
      wallet,
      todayLedger,
      incentivesPayload,
    ] = await Promise.all([
      this.prisma.booking.findMany({
        where: {
          driverId: driver.id,
          status: BookingStatus.COMPLETED,
          updatedAt: { gte: todayStart },
        },
        select: { id: true },
      }),
      this.prisma.parcelShipment.findMany({
        where: {
          driverId: driver.id,
          status: 'delivered',
          updatedAt: { gte: todayStart },
        },
        select: { id: true },
      }),
      receive
        ? this.prisma.booking.count({
            where: {
              status: { in: OFFER_STATUSES },
              driverId: null,
              createdAt: { gte: new Date(Date.now() - 15 * 60 * 1000) },
            },
          })
        : Promise.resolve(0),
      this.prisma.wallet.findUnique({
        where: { ownerType_ownerUserId: { ownerType: 'DRIVER', ownerUserId: userId } },
      }),
      this.prisma.walletLedger.findMany({
        where: {
          createdAt: { gte: todayStart },
          wallet: { ownerType: WalletOwnerType.DRIVER, ownerUserId: userId },
        },
      }),
      this.care.incentives(userId),
    ]);

    const todayFinance = summarizeLedger(todayLedger);
    const todayTrips = todayRides.length + todayParcels.length;
    const incentives = incentivesPayload.incentives;

    const documentAlerts = (kyc.documents as Array<{
      type: string;
      label: string;
      status: string;
      expiresAt: string | null;
      rejectionReason: string | null;
    }>).flatMap((doc) => alertsForDocument(doc));

    return {
      id: driver.id.toString(),
      userId: driver.userId.toString(),
      name: driver.user.name,
      phone: driver.user.phone,
      email: driver.user.email,
      city: driver.city,
      ratingAvg: Number(driver.ratingAvg),
      kycStatus: kyc.kycStatus,
      canGoOnline: kyc.canGoOnline,
      nextStep: kyc.nextStep,
      dutyStatus: duty,
      dutyLabel: DUTY_LABELS[duty],
      online: persist.online,
      canReceiveOffers: receive,
      offerBlockReason: receive ? null : offerBlockReason(duty, kyc.canGoOnline),
      today: {
        earningsPaise: todayFinance.netPaise,
        earningsRupees: todayFinance.netPaise / 100,
        trips: todayTrips,
        rides: todayRides.length,
        parcels: todayParcels.length,
        grossPaise: todayFinance.grossPaise,
        grossRupees: todayFinance.grossPaise / 100,
        commissionPaise: todayFinance.commissionPaise,
        commissionRupees: todayFinance.commissionPaise / 100,
        incentivesPaise: todayFinance.incentivesPaise,
        incentivesRupees: todayFinance.incentivesPaise / 100,
      },
      pendingRequests: pendingCount,
      wallet: {
        balancePaise: Number(wallet?.balancePaise ?? 0),
        balanceRupees: Number(wallet?.balancePaise ?? 0) / 100,
      },
      incentives,
      documentAlerts,
      vehicle: driver.vehicles[0]
        ? {
            registrationNo: driver.vehicles[0].registrationNo,
            category: driver.vehicles[0].category,
            brand: driver.vehicles[0].brand,
            model: driver.vehicles[0].model,
            status: driver.vehicles[0].status,
          }
        : null,
      activeJob: activeRide
        ? { kind: 'ride', id: activeRide.id.toString(), status: activeRide.status }
        : activeParcel
          ? { kind: 'parcel', id: activeParcel.id.toString(), status: activeParcel.status }
          : null,
    };
  }

  async trips(userId: bigint) {
    const driver = await this.requireDriver(userId);
    const [rides, parcels] = await Promise.all([
      this.prisma.booking.findMany({
        where: { driverId: driver.id },
        orderBy: { updatedAt: 'desc' },
        take: 40,
        include: { ratings: true },
      }),
      this.prisma.parcelShipment.findMany({
        where: { driverId: driver.id },
        orderBy: { updatedAt: 'desc' },
        take: 40,
      }),
    ]);
    const items = [
      ...rides.map((row) => {
        const customer = row.ratings.find((r) => r.fromRole === 'CUSTOMER');
        const self = row.ratings.find((r) => r.fromRole === 'DRIVER');
        return {
          kind: 'ride' as const,
          id: row.id.toString(),
          publicRef: row.publicRef,
          status: row.status,
          pickupText: row.pickupText,
          dropText: row.dropText,
          product: row.product,
          category: row.category,
          quotePaise: Number(row.quotePaise ?? 0),
          quoteRupees: Number(row.quotePaise ?? 0) / 100,
          customerRating: customer?.stars ?? null,
          driverRating: self?.stars ?? null,
          updatedAt: row.updatedAt.toISOString(),
        };
      }),
      ...parcels.map((row) => ({
        kind: 'parcel' as const,
        id: row.id.toString(),
        status: row.status,
        pickupText: row.pickupText,
        dropText: row.dropText,
        product: 'PARCEL',
        category: row.category,
        quotePaise: Number(row.quotePaise ?? 0),
        quoteRupees: Number(row.quotePaise ?? 0) / 100,
        updatedAt: row.updatedAt.toISOString(),
      })),
    ].sort((a, b) => b.updatedAt.localeCompare(a.updatedAt));
    return { trips: items };
  }

  async earnings(userId: bigint) {
    const driver = await this.requireDriver(userId);
    const todayStart = startOfTodayIst();
    const weekStart = new Date(todayStart.getTime() - 6 * 24 * 60 * 60 * 1000);
    const monthStart = startOfMonthIst();
    const [todayRides, weekRides, monthRides, todayParcels, weekParcels, monthParcels, wallet, periodEntries, withdrawalsEnabled, minPaise] =
      await Promise.all([
        this.prisma.booking.count({
          where: { driverId: driver.id, status: BookingStatus.COMPLETED, updatedAt: { gte: todayStart } },
        }),
        this.prisma.booking.count({
          where: { driverId: driver.id, status: BookingStatus.COMPLETED, updatedAt: { gte: weekStart } },
        }),
        this.prisma.booking.count({
          where: { driverId: driver.id, status: BookingStatus.COMPLETED, updatedAt: { gte: monthStart } },
        }),
        this.prisma.parcelShipment.count({
          where: { driverId: driver.id, status: 'delivered', updatedAt: { gte: todayStart } },
        }),
        this.prisma.parcelShipment.count({
          where: { driverId: driver.id, status: 'delivered', updatedAt: { gte: weekStart } },
        }),
        this.prisma.parcelShipment.count({
          where: { driverId: driver.id, status: 'delivered', updatedAt: { gte: monthStart } },
        }),
        this.prisma.wallet.findUnique({
          where: { ownerType_ownerUserId: { ownerType: WalletOwnerType.DRIVER, ownerUserId: userId } },
          include: {
            entries: { orderBy: { createdAt: 'desc' }, take: 50 },
            withdrawals: { orderBy: { createdAt: 'desc' }, take: 20 },
          },
        }),
        this.prisma.walletLedger.findMany({
          where: {
            createdAt: { gte: weekStart < monthStart ? weekStart : monthStart },
            wallet: { ownerType: WalletOwnerType.DRIVER, ownerUserId: userId },
          },
        }),
        this.prisma.systemSetting.findUnique({ where: { key: 'driver_withdrawals_enabled' } }),
        this.prisma.systemSetting.findUnique({ where: { key: 'driver_withdraw_min_paise' } }),
      ]);
    const entries = wallet?.entries ?? [];
    const today = summarizeLedger(periodEntries.filter((row) => row.createdAt >= todayStart));
    const week = summarizeLedger(periodEntries.filter((row) => row.createdAt >= weekStart));
    const month = summarizeLedger(periodEntries.filter((row) => row.createdAt >= monthStart));
    const supported = (withdrawalsEnabled?.value ?? 'true').trim() === 'true';
    return {
      today: periodPayload(today, todayRides + todayParcels),
      week: periodPayload(week, weekRides + weekParcels),
      month: periodPayload(month, monthRides + monthParcels),
      trips: todayRides + todayParcels,
      grossFare: { paise: today.grossPaise, rupees: today.grossPaise / 100 },
      commission: { paise: today.commissionPaise, rupees: today.commissionPaise / 100 },
      netEarnings: { paise: today.netPaise, rupees: today.netPaise / 100 },
      incentives: { paise: today.incentivesPaise, rupees: today.incentivesPaise / 100 },
      wallet: {
        balancePaise: Number(wallet?.balancePaise ?? 0),
        balanceRupees: Number(wallet?.balancePaise ?? 0) / 100,
        recent: entries.map((entry) => this.wallets.serializeLedger(entry)),
      },
      withdrawals: {
        supported,
        minRupees: Number(minPaise?.value ?? 50000) / 100,
        items: (wallet?.withdrawals ?? []).map((row) => ({
          id: row.id.toString(),
          publicRef: row.publicRef,
          amountPaise: Number(row.amountPaise),
          amountRupees: Number(row.amountPaise) / 100,
          status: row.status,
          createdAt: row.createdAt.toISOString(),
        })),
      },
    };
  }

  async assertCanReceiveOffers(userId: bigint) {
    const snap = await this.summary(userId);
    if (!snap.canReceiveOffers) {
      throw new ForbiddenException(snap.offerBlockReason ?? 'Not available for trip requests');
    }
    return snap;
  }

  async setDuty(userId: bigint, next: 'online' | 'offline' | 'busy') {
    const current = await this.summary(userId);
    if (next === 'online') {
      await this.kyc.assertCanGoOnline(userId);
      if (current.dutyStatus === 'suspended') {
        throw new ForbiddenException('Account is suspended');
      }
      if (current.dutyStatus === 'maintenance') {
        throw new ForbiddenException('Vehicle is in maintenance');
      }
      if (current.dutyStatus === 'on_trip' || current.dutyStatus === 'on_delivery') {
        throw new ForbiddenException('Finish the current job before changing status');
      }
    }
    if (next === 'offline' || next === 'busy') {
      if (current.dutyStatus === 'on_trip' || current.dutyStatus === 'on_delivery') {
        throw new ForbiddenException('Finish the current job before going offline');
      }
    }
    const duty: DutyStatus = next;
    await this.prisma.driver.update({
      where: { userId },
      data: persistFromDuty(duty),
    });
    return this.summary(userId);
  }

  async markJobDuty(driverId: bigint, duty: 'on_trip' | 'on_delivery' | 'online' | 'offline') {
    await this.prisma.driver.update({
      where: { id: driverId },
      data: persistFromDuty(duty),
    });
  }

  private async requireDriver(userId: bigint) {
    const driver = await this.prisma.driver.findUnique({ where: { userId } });
    if (!driver) {
      throw new NotFoundException('Driver profile required');
    }
    return driver;
  }
}

function startOfTodayIst() {
  const day = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Kolkata',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date());
  return new Date(`${day}T00:00:00+05:30`);
}

function startOfMonthIst() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Kolkata',
    year: 'numeric',
    month: '2-digit',
  }).format(new Date());
  return new Date(`${parts}-01T00:00:00+05:30`);
}

function summarizeLedger(
  rows: Array<{
    direction: LedgerDirection;
    amountPaise: bigint;
    commissionPaise: bigint;
    grossPaise: bigint;
    kind: string;
    status: string;
  }>,
) {
  return rows
    .filter((row) => row.status === 'posted')
    .reduce(
      (acc, row) => {
        const amount = Number(row.amountPaise);
        if (row.direction === LedgerDirection.CREDIT && row.kind === 'trip') {
          acc.netPaise += amount;
          acc.commissionPaise += Number(row.commissionPaise);
          acc.grossPaise += Number(row.grossPaise);
          acc.tripCredits += 1;
        }
        if (row.direction === LedgerDirection.CREDIT && row.kind === 'incentive') {
          acc.incentivesPaise += amount;
          acc.netPaise += amount;
        }
        return acc;
      },
      { netPaise: 0, commissionPaise: 0, grossPaise: 0, incentivesPaise: 0, tripCredits: 0 },
    );
}

function periodPayload(
  stats: ReturnType<typeof summarizeLedger>,
  trips: number,
) {
  return {
    trips,
    earningsPaise: stats.netPaise,
    earningsRupees: stats.netPaise / 100,
    grossPaise: stats.grossPaise,
    grossRupees: stats.grossPaise / 100,
    commissionPaise: stats.commissionPaise,
    commissionRupees: stats.commissionPaise / 100,
    netPaise: stats.netPaise,
    netRupees: stats.netPaise / 100,
    incentivesPaise: stats.incentivesPaise,
    incentivesRupees: stats.incentivesPaise / 100,
  };
}
