import { FranchiseKind, FranchiseStatus, LedgerDirection, WalletOwnerType } from '@prisma/client';
import { WalletSettlementService } from './wallet-settlement.service';

describe('WalletSettlementService', () => {
  it('posts driver net and territory commission through the ledger in one transaction client', async () => {
    const post = jest.fn().mockResolvedValue({ wallet: {}, ledger: {} });
    const service = new WalletSettlementService({ post } as never);
    const tx = {
      walletLedger: { findFirst: jest.fn().mockResolvedValue(null) },
      driver: {
        findUnique: jest.fn().mockResolvedValue({
          userId: 2n,
          fleetOwner: { userId: 3n },
        }),
      },
      vehicle: { findUnique: jest.fn().mockResolvedValue({ fleetOwner: { userId: 3n } }) },
      franchise: {
        findFirst: jest.fn().mockResolvedValue({
          ownerUserId: 4n,
          kind: FranchiseKind.DISTRICT_HEAD,
          commissionPercent: 40,
        }),
      },
      systemSetting: {
        findUnique: jest.fn().mockImplementation(({ where }: { where: { key: string } }) => {
          if (where.key === 'fleet_commission_share_percent') {
            return { value: '10' };
          }
          if (where.key === 'platform_wallet_user_id') {
            return { value: '99' };
          }
          return null;
        }),
      },
      payment: { findFirst: jest.fn().mockResolvedValue(null) },
      corporateAccount: { findUnique: jest.fn() },
    };

    await service.settleCompletedTrip(tx as never, {
      id: 9n,
      customerId: 1n,
      districtId: 26,
      driverId: 11n,
      vehicleId: 22n,
      quotePaise: 100_000n,
      quoteSnapshot: {
        totalPaise: 100_000,
        commission: {
          percent: 10,
          commissionPaise: 10_000,
          netPaise: 90_000,
          eligiblePaise: 100_000,
        },
      },
    });

    expect(tx.franchise.findFirst).toHaveBeenCalledWith(
      expect.objectContaining({
        where: expect.objectContaining({ status: FranchiseStatus.ACTIVE }),
      }),
    );
    expect(post).toHaveBeenCalledTimes(4);
    expect(post.mock.calls[0][0]).toMatchObject({
      ownerType: WalletOwnerType.DRIVER,
      direction: LedgerDirection.CREDIT,
      amountPaise: 90_000,
      commissionPaise: 10_000,
      kind: 'trip',
    });
    expect(post.mock.calls[1][0]).toMatchObject({
      ownerType: WalletOwnerType.FLEET_OWNER,
      amountPaise: 1_000,
      kind: 'commission',
    });
    expect(post.mock.calls[2][0]).toMatchObject({
      ownerType: WalletOwnerType.DISTRICT_HEAD,
      amountPaise: 4_000,
      kind: 'commission',
    });
    expect(post.mock.calls[3][0]).toMatchObject({
      ownerType: WalletOwnerType.PLATFORM,
      amountPaise: 5_000,
      kind: 'commission',
    });
    expect(post.mock.calls.every((call: unknown[]) => call[1] === tx)).toBe(true);
  });

  it('skips a second post when a driver trip ledger already exists', async () => {
    const post = jest.fn();
    const service = new WalletSettlementService({ post } as never);
    const tx = {
      walletLedger: { findFirst: jest.fn().mockResolvedValue({ id: 1n }) },
    };
    await service.settleCompletedTrip(tx as never, {
      id: 9n,
      customerId: 1n,
      districtId: 26,
      driverId: 11n,
    });
    expect(post).not.toHaveBeenCalled();
  });
});
