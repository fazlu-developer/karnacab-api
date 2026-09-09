import { Injectable } from '@nestjs/common';
import {
  FranchiseKind,
  FranchiseStatus,
  LedgerDirection,
  Prisma,
  WalletOwnerType,
} from '@prisma/client';
import { settleFromQuoteSnapshot, splitPlatformCommission } from '../ride-engine/commission.engine';
import { WalletsService } from './wallets.service';

export type CompletedBookingRow = {
  id: bigint;
  customerId: bigint;
  districtId: number | null;
  driverId?: bigint | null;
  vehicleId?: bigint | null;
  quotePaise?: bigint | null;
  quoteSnapshot?: unknown;
  corporateAccountId?: bigint | null;
};

@Injectable()
export class WalletSettlementService {
  constructor(private readonly wallets: WalletsService) {}

  async settleCompletedTrip(tx: Prisma.TransactionClient, row: CompletedBookingRow) {
    if (!row.driverId) {
      return;
    }
    const existing = await tx.walletLedger.findFirst({
      where: {
        bookingId: row.id,
        direction: LedgerDirection.CREDIT,
        kind: 'trip',
        account: WalletOwnerType.DRIVER,
      },
      select: { id: true },
    });
    if (existing) {
      return;
    }

    const settled = settleFromQuoteSnapshot(
      row.quoteSnapshot,
      row.quotePaise ? Number(row.quotePaise) : 0,
    );
    const driver = await tx.driver.findUnique({
      where: { id: row.driverId },
      select: {
        userId: true,
        fleetOwner: { select: { userId: true } },
      },
    });
    if (!driver) {
      return;
    }

    const vehicle = row.vehicleId
      ? await tx.vehicle.findUnique({
          where: { id: row.vehicleId },
          select: { fleetOwner: { select: { userId: true } } },
        })
      : null;
    const fleetUserId = vehicle?.fleetOwner?.userId ?? driver.fleetOwner?.userId ?? null;

    const franchise = row.districtId
      ? await tx.franchise.findFirst({
          where: { districtId: row.districtId, status: FranchiseStatus.ACTIVE },
          select: { ownerUserId: true, kind: true, commissionPercent: true },
        })
      : null;

    const fleetSetting = await tx.systemSetting.findUnique({
      where: { key: 'fleet_commission_share_percent' },
    });
    const split = splitPlatformCommission(settled.commissionPaise, {
      fleetPercent: Number(fleetSetting?.value ?? 0) || 0,
      territoryPercent: franchise ? Number(franchise.commissionPercent) : 0,
    });

    const payment = await tx.payment.findFirst({
      where: {
        bookingId: row.id,
        status: { in: ['captured', 'paid', 'success'] },
      },
      orderBy: { id: 'desc' },
    });
    const walletPay = payment?.method?.toLowerCase() === 'wallet' && payment.status !== 'captured';
    const farePaise = row.quotePaise ? Number(row.quotePaise) : settled.netPaise + settled.commissionPaise;
    const paymentRef = payment?.publicRef ?? null;

    if (walletPay && !row.corporateAccountId && farePaise > 0) {
      await this.wallets.post(
        {
          ownerType: WalletOwnerType.CUSTOMER,
          ownerUserId: row.customerId,
          direction: LedgerDirection.DEBIT,
          amountPaise: farePaise,
          commissionPaise: settled.commissionPaise,
          grossPaise: farePaise,
          bookingId: row.id,
          kind: 'trip',
          note: 'Trip payment from customer wallet',
          paymentRef,
        },
        tx,
      );
    }

    if (settled.netPaise > 0) {
      await this.wallets.post(
        {
          ownerType: WalletOwnerType.DRIVER,
          ownerUserId: driver.userId,
          direction: LedgerDirection.CREDIT,
          amountPaise: settled.netPaise,
          commissionPaise: settled.commissionPaise,
          grossPaise: settled.netPaise + settled.commissionPaise,
          bookingId: row.id,
          kind: 'trip',
          note: 'Trip earnings after configured commission',
          paymentRef,
        },
        tx,
      );
    }

    if (fleetUserId && split.fleetPaise > 0) {
      await this.wallets.post(
        {
          ownerType: WalletOwnerType.FLEET_OWNER,
          ownerUserId: fleetUserId,
          direction: LedgerDirection.CREDIT,
          amountPaise: split.fleetPaise,
          commissionPaise: split.fleetPaise,
          grossPaise: settled.commissionPaise,
          bookingId: row.id,
          kind: 'commission',
          note: 'Fleet share of platform commission',
          paymentRef,
        },
        tx,
      );
    }

    if (franchise && split.territoryPaise > 0) {
      const ownerType =
        franchise.kind === FranchiseKind.DISTRICT_HEAD
          ? WalletOwnerType.DISTRICT_HEAD
          : WalletOwnerType.FRANCHISE;
      await this.wallets.post(
        {
          ownerType,
          ownerUserId: franchise.ownerUserId,
          direction: LedgerDirection.CREDIT,
          amountPaise: split.territoryPaise,
          commissionPaise: split.territoryPaise,
          grossPaise: settled.commissionPaise,
          bookingId: row.id,
          kind: 'commission',
          note: 'Territory share of platform commission',
          paymentRef,
        },
        tx,
      );
    }

    if (row.corporateAccountId && split.unallocatedPaise > 0) {
      const account = await tx.corporateAccount.findUnique({
        where: { id: row.corporateAccountId },
        select: { ownerUserId: true },
      });
      if (account) {
        await this.wallets.post(
          {
            ownerType: WalletOwnerType.CORPORATE,
            ownerUserId: account.ownerUserId,
            direction: LedgerDirection.CREDIT,
            amountPaise: split.unallocatedPaise,
            commissionPaise: split.unallocatedPaise,
            grossPaise: settled.commissionPaise,
            bookingId: row.id,
            kind: 'commission',
            note: 'Corporate share of unallocated platform commission',
            paymentRef,
          },
          tx,
        );
      }
    } else if (split.unallocatedPaise > 0) {
      const platformSetting = await tx.systemSetting.findUnique({
        where: { key: 'platform_wallet_user_id' },
      });
      const platformUserId = Number(platformSetting?.value || 0);
      if (Number.isFinite(platformUserId) && platformUserId > 0) {
        await this.wallets.post(
          {
            ownerType: WalletOwnerType.PLATFORM,
            ownerUserId: BigInt(platformUserId),
            direction: LedgerDirection.CREDIT,
            amountPaise: split.unallocatedPaise,
            commissionPaise: split.unallocatedPaise,
            grossPaise: settled.commissionPaise,
            bookingId: row.id,
            kind: 'commission',
            note: 'Platform / admin share of commission',
            paymentRef,
          },
          tx,
        );
      }
    }
  }
}
