import { randomBytes } from 'crypto';
import { BadRequestException, ForbiddenException, Injectable, NotFoundException } from '@nestjs/common';
import { LedgerDirection, Prisma, UserRole, WalletOwnerType } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { DomainEvents } from '../common/domain-events.service';
import { Actor, canAccessDistrict } from '../access/territory';
import { serializeCommissionPolicy } from '../ride-engine/commission.engine';

export type LedgerKind = 'trip' | 'incentive' | 'withdrawal' | 'reversal' | 'adjustment' | 'commission';

export function walletOwnerTypeForRole(role: UserRole): WalletOwnerType | null {
  switch (role) {
    case UserRole.CUSTOMER:
      return WalletOwnerType.CUSTOMER;
    case UserRole.DRIVER:
      return WalletOwnerType.DRIVER;
    case UserRole.FLEET_OWNER:
      return WalletOwnerType.FLEET_OWNER;
    case UserRole.DISTRICT_HEAD:
      return WalletOwnerType.DISTRICT_HEAD;
    case UserRole.FRANCHISE:
      return WalletOwnerType.FRANCHISE;
    case UserRole.CORPORATE:
      return WalletOwnerType.CORPORATE;
    case UserRole.ADMIN:
    case UserRole.SUPER_ADMIN:
      return WalletOwnerType.PLATFORM;
    default:
      return null;
  }
}

export type LedgerPostInput = {
  ownerType: WalletOwnerType;
  ownerUserId: bigint;
  direction: LedgerDirection;
  amountPaise: number;
  commissionPaise?: number;
  grossPaise?: number;
  bookingId?: bigint | null;
  kind?: LedgerKind;
  status?: string;
  note?: string;
  paymentRef?: string | null;
};

@Injectable()
export class WalletsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly events: DomainEvents,
  ) {}

  async commissionPolicy() {
    const rule = await this.prisma.commissionRule.findFirst({
      where: { active: true, name: 'default' },
    });
    return {
      ...serializeCommissionPolicy(rule),
      id: rule?.id ?? null,
      name: rule?.name ?? 'default',
      active: rule?.active ?? true,
    };
  }

  async ensureForActor(userId: bigint, role: UserRole) {
    const ownerType = walletOwnerTypeForRole(role);
    if (!ownerType) {
      return;
    }
    await this.prisma.wallet.upsert({
      where: { ownerType_ownerUserId: { ownerType, ownerUserId: userId } },
      create: { ownerType, ownerUserId: userId, balancePaise: 0 },
      update: {},
    });
  }

  async mine(userId: bigint, role?: UserRole) {
    if (role) {
      await this.ensureForActor(userId, role);
    }
    const rows = await this.prisma.wallet.findMany({
      where: { ownerUserId: userId },
      include: {
        entries: {
          orderBy: { createdAt: 'desc' },
          take: 50,
        },
      },
    });
    return {
      wallets: rows.map((row) => ({
        id: row.id.toString(),
        ownerType: row.ownerType,
        balancePaise: row.balancePaise.toString(),
        balanceRupees: Number(row.balancePaise) / 100,
        recent: row.entries.map((entry) => this.serializeLedger(entry)),
      })),
    };
  }

  async one(actor: Actor, walletId: bigint) {
    const wallet = await this.prisma.wallet.findUnique({
      where: { id: walletId },
      include: {
        owner: { select: { id: true, districtId: true, stateId: true } },
        entries: { orderBy: { createdAt: 'desc' }, take: 20 },
      },
    });
    if (!wallet) {
      throw new NotFoundException('Wallet not found');
    }
    const own = wallet.ownerUserId === actor.userId;
    const territory = canAccessDistrict(
      actor,
      wallet.owner.districtId,
      wallet.owner.stateId,
    );
    const admin = actor.unrestricted;
    if (!own && !territory && !admin) {
      throw new ForbiddenException('Access denied for this wallet');
    }
    if (
      !own &&
      !admin &&
      (actor.role === UserRole.CUSTOMER ||
        actor.role === UserRole.DRIVER ||
        actor.role === UserRole.FLEET_OWNER ||
        actor.role === UserRole.CORPORATE)
    ) {
      throw new ForbiddenException('Access denied for this wallet');
    }
    return {
      id: wallet.id.toString(),
      ownerType: wallet.ownerType,
      ownerUserId: wallet.ownerUserId.toString(),
      balancePaise: wallet.balancePaise.toString(),
      balanceRupees: Number(wallet.balancePaise) / 100,
      recent: wallet.entries.map((entry) => this.serializeLedger(entry)),
    };
  }

  async post(input: LedgerPostInput, tx?: Prisma.TransactionClient) {
    const run = async (client: Prisma.TransactionClient) => {
      if (input.amountPaise <= 0) {
        throw new BadRequestException('Ledger amount must be greater than zero');
      }
      const wallet = await client.wallet.upsert({
        where: {
          ownerType_ownerUserId: { ownerType: input.ownerType, ownerUserId: input.ownerUserId },
        },
        create: {
          ownerType: input.ownerType,
          ownerUserId: input.ownerUserId,
          balancePaise: 0,
        },
        update: {},
      });
      await client.$queryRaw`SELECT id FROM wallets WHERE id = ${wallet.id} FOR UPDATE`;
      const locked = await client.wallet.findUnique({
        where: { id: wallet.id },
        select: { id: true, balancePaise: true },
      });
      const before = Number(locked?.balancePaise ?? 0);
      const delta = input.direction === LedgerDirection.CREDIT ? input.amountPaise : -input.amountPaise;
      const after = before + delta;
      if (after < 0) {
        throw new BadRequestException('Insufficient wallet balance');
      }
      const ledger = await client.walletLedger.create({
        data: {
          publicRef: this.newRef('KCW'),
          walletId: wallet.id,
          bookingId: input.bookingId ?? null,
          account: input.ownerType,
          direction: input.direction,
          amountPaise: BigInt(input.amountPaise),
          commissionPaise: BigInt(input.commissionPaise ?? 0),
          grossPaise: BigInt(input.grossPaise ?? input.amountPaise + (input.commissionPaise ?? 0)),
          balanceBefore: BigInt(before),
          balanceAfter: BigInt(after),
          kind: input.kind ?? 'trip',
          status: input.status ?? 'posted',
          note: input.note ?? null,
          ownerUserId: input.ownerUserId,
          paymentRef: input.paymentRef ?? null,
        },
      });
      const updated = await client.wallet.update({
        where: { id: wallet.id },
        data: { balancePaise: BigInt(after) },
      });
      return { wallet: updated, ledger };
    };
    const posted = tx ? await run(tx) : await this.prisma.$transaction((inner) => run(inner));
    this.events.emit('wallet.posted', {
      userId: input.ownerUserId.toString(),
      amountPaise: input.amountPaise,
      direction: input.direction,
      note: input.note ?? input.kind ?? 'Wallet update',
      ledgerId: posted.ledger.id.toString(),
    });
    return posted;
  }

  async requestDriverWithdrawal(userId: bigint, amountRupees: number) {
    const enabled = await this.setting('driver_withdrawals_enabled', 'true');
    if (enabled !== 'true') {
      throw new BadRequestException('Withdrawals are not enabled');
    }
    const minPaise = Number(await this.setting('driver_withdraw_min_paise', '50000')) || 50000;
    const amountPaise = Math.round(amountRupees * 100);
    if (!Number.isFinite(amountPaise) || amountPaise <= 0) {
      throw new BadRequestException('Enter a valid withdrawal amount');
    }
    if (amountPaise < minPaise) {
      throw new BadRequestException(`Minimum withdrawal is ₹${(minPaise / 100).toFixed(0)}`);
    }
    const driver = await this.prisma.driver.findUnique({
      where: { userId },
      select: { bankAccountLast4: true, upiId: true, kycStatus: true },
    });
    if (!driver) {
      throw new ForbiddenException('Driver profile required');
    }
    if (driver.kycStatus !== 'verified') {
      throw new BadRequestException('Complete KYC before withdrawing');
    }
    if (!driver.bankAccountLast4 && !driver.upiId) {
      throw new BadRequestException('Add a bank account or UPI ID before withdrawing');
    }
    return this.prisma.$transaction(async (tx) => {
      const posted = await this.post(
        {
          ownerType: WalletOwnerType.DRIVER,
          ownerUserId: userId,
          direction: LedgerDirection.DEBIT,
          amountPaise,
          commissionPaise: 0,
          grossPaise: amountPaise,
          kind: 'withdrawal',
          status: 'posted',
          note: driver.bankAccountLast4
            ? `Withdrawal requested to account ****${driver.bankAccountLast4}`
            : `Withdrawal requested to UPI ${driver.upiId}`,
        },
        tx,
      );
      const withdrawal = await tx.walletWithdrawal.create({
        data: {
          publicRef: this.newRef('KCX'),
          walletId: posted.wallet.id,
          ledgerId: posted.ledger.id,
          amountPaise: BigInt(amountPaise),
          status: 'requested',
          note: posted.ledger.note,
        },
      });
      return this.serializeWithdrawal(withdrawal, posted.ledger);
    });
  }

  async listWithdrawals() {
    const rows = await this.prisma.walletWithdrawal.findMany({
      orderBy: { createdAt: 'desc' },
      take: 100,
      include: { ledger: true, wallet: { select: { ownerUserId: true, balancePaise: true } } },
    });
    return { withdrawals: rows.map((row) => this.serializeWithdrawal(row, row.ledger)) };
  }

  async reviewWithdrawal(actor: Actor, id: bigint, status: 'paid' | 'rejected') {
    return this.prisma.$transaction(async (tx) => {
      const row = await tx.walletWithdrawal.findUnique({
        where: { id },
        include: { ledger: true, wallet: true },
      });
      if (!row) {
        throw new NotFoundException('Withdrawal not found');
      }
      if (row.status !== 'requested') {
        throw new BadRequestException('Withdrawal is already reviewed');
      }
      if (status === 'rejected') {
        await this.post(
          {
            ownerType: row.wallet.ownerType,
            ownerUserId: row.wallet.ownerUserId,
            direction: LedgerDirection.CREDIT,
            amountPaise: Number(row.amountPaise),
            commissionPaise: 0,
            grossPaise: Number(row.amountPaise),
            kind: 'reversal',
            note: `Withdrawal ${row.publicRef} rejected`,
          },
          tx,
        );
      }
      const updated = await tx.walletWithdrawal.update({
        where: { id },
        data: { status, reviewedById: actor.userId },
        include: { ledger: true },
      });
      return this.serializeWithdrawal(updated, updated.ledger);
    });
  }

  serializeLedger(entry: {
    id: bigint;
    publicRef: string;
    bookingId: bigint | null;
    account?: WalletOwnerType | string;
    direction: LedgerDirection;
    amountPaise: bigint;
    commissionPaise: bigint;
    grossPaise: bigint;
    balanceBefore: bigint;
    balanceAfter: bigint;
    kind: string;
    status: string;
    note: string | null;
    createdAt: Date;
    ownerUserId?: bigint | null;
    paymentRef?: string | null;
  }) {
    return {
      id: entry.id.toString(),
      transactionId: entry.publicRef,
      bookingId: entry.bookingId?.toString() ?? null,
      userId: entry.ownerUserId != null ? entry.ownerUserId.toString() : null,
      paymentReference: entry.paymentRef ?? null,
      account: entry.account ?? null,
      direction: entry.direction,
      credit: entry.direction === LedgerDirection.CREDIT,
      debit: entry.direction === LedgerDirection.DEBIT,
      amountPaise: Number(entry.amountPaise),
      amountRupees: Number(entry.amountPaise) / 100,
      commissionPaise: Number(entry.commissionPaise),
      commissionRupees: Number(entry.commissionPaise) / 100,
      grossPaise: Number(entry.grossPaise),
      grossRupees: Number(entry.grossPaise) / 100,
      balanceBeforePaise: Number(entry.balanceBefore),
      balanceBeforeRupees: Number(entry.balanceBefore) / 100,
      balanceAfterPaise: Number(entry.balanceAfter),
      balanceAfterRupees: Number(entry.balanceAfter) / 100,
      kind: entry.kind,
      status: entry.status,
      description: entry.note,
      createdAt: entry.createdAt.toISOString(),
    };
  }

  private serializeWithdrawal(
    row: {
      id: bigint;
      publicRef: string;
      amountPaise: bigint;
      status: string;
      note: string | null;
      createdAt: Date;
    },
    ledger: Parameters<WalletsService['serializeLedger']>[0],
  ) {
    return {
      id: row.id.toString(),
      publicRef: row.publicRef,
      amountPaise: Number(row.amountPaise),
      amountRupees: Number(row.amountPaise) / 100,
      status: row.status,
      note: row.note,
      createdAt: row.createdAt.toISOString(),
      ledger: this.serializeLedger(ledger),
    };
  }

  private newRef(prefix: string) {
    return `${prefix}${randomBytes(6).toString('hex').toUpperCase()}`.slice(0, 24);
  }

  private async setting(key: string, fallback: string) {
    const row = await this.prisma.systemSetting.findUnique({ where: { key } });
    return row?.value?.trim() || fallback;
  }
}
