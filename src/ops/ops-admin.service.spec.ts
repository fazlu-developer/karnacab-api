import { UserRole, UserStatus } from '@prisma/client';
import { OpsAdminService } from './ops-admin.service';
import { LIVE_STATUSES } from '../ride-engine/booking-lifecycle';

describe('OpsAdminService', () => {
  const actor = {
    userId: 9n,
    role: UserRole.SUPER_ADMIN,
    status: 'ACTIVE',
    districtId: null,
    stateId: null,
    driverId: null,
    fleetOwnerId: null,
    unrestricted: true,
  };

  const prisma = {
    user: { count: jest.fn(), findMany: jest.fn(), findUnique: jest.fn() },
    driver: { count: jest.fn() },
    booking: { count: jest.fn(), aggregate: jest.fn() },
    walletLedger: { aggregate: jest.fn() },
    parcelShipment: { count: jest.fn() },
    bulkBooking: { count: jest.fn() },
    fleetOwner: { count: jest.fn() },
    supportTicket: { count: jest.fn() },
    driverDocument: { count: jest.fn() },
    adCampaign: { count: jest.fn() },
  };
  const scopes = {
    bookingWhere: jest.fn().mockReturnValue({}),
    driverWhere: jest.fn().mockReturnValue({}),
    userWhere: jest.fn().mockReturnValue({}),
    parcelWhere: jest.fn().mockReturnValue({}),
    fleetWhere: jest.fn().mockReturnValue({}),
  };
  const audit = { record: jest.fn() };
  const bookings = { one: jest.fn() };
  const service = new OpsAdminService(prisma as never, scopes as never, audit as never, bookings as never);

  beforeEach(() => {
    jest.clearAllMocks();
    prisma.user.count.mockResolvedValue(10);
    prisma.driver.count.mockResolvedValue(4);
    prisma.booking.count.mockResolvedValue(3);
    prisma.booking.aggregate.mockResolvedValue({ _sum: { quotePaise: 20580 } });
    prisma.walletLedger.aggregate.mockResolvedValue({ _sum: { amountPaise: 1000, commissionPaise: 200 } });
    prisma.parcelShipment.count.mockResolvedValue(1);
    prisma.bulkBooking.count.mockResolvedValue(2);
    prisma.fleetOwner.count.mockResolvedValue(1);
    prisma.supportTicket.count.mockResolvedValue(0);
    prisma.driverDocument.count.mockResolvedValue(0);
    prisma.adCampaign.count.mockResolvedValue(1);
  });

  it('returns the management KPI set without secrets', async () => {
    const result = await service.dashboard(actor);
    expect(result.kpis.totalUsers).toBe(10);
    expect(result.kpis.activeRides).toBe(3);
    expect(result.kpis.todayRevenuePaise).toBe(20580);
    expect(result.kpis.activeAdvertisements).toBe(1);
    expect(LIVE_STATUSES.length).toBeGreaterThan(0);
    expect(JSON.stringify(result)).not.toMatch(/password/i);
  });

  it('lists users without passwordHash', async () => {
    prisma.user.findMany.mockResolvedValue([
      {
        id: 1n,
        role: UserRole.CUSTOMER,
        status: UserStatus.ACTIVE,
        name: 'Fazlu',
        email: 'customer@karnacab.local',
        phone: '9999999999',
        gender: 'MALE',
        lastAddress: 'Patna',
        stateId: 1,
        districtId: 1,
        emergencyName: 'Home',
        createdAt: new Date('2026-09-08T00:00:00Z'),
        updatedAt: new Date('2026-09-08T00:00:00Z'),
      },
    ]);
    const result = await service.listUsers(actor, {});
    expect(result.users[0].email).toBe('customer@karnacab.local');
    expect(result.users[0]).not.toHaveProperty('passwordHash');
  });
});
