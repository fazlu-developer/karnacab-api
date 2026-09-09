import { ForbiddenException } from '@nestjs/common';
import { KycService } from './kyc.service';

describe('KycService go-online gate', () => {
  const prisma = {
    district: { findMany: jest.fn(), findFirst: jest.fn(), findUnique: jest.fn() },
    systemSetting: { findUnique: jest.fn() },
    driver: { findUnique: jest.fn(), update: jest.fn() },
    driverDocument: { updateMany: jest.fn(), findMany: jest.fn() },
    user: { update: jest.fn() },
    $transaction: jest.fn(),
  };
  const storage = {
    write: jest.fn(),
    read: jest.fn(),
    remove: jest.fn(),
    publicUrl: jest.fn().mockReturnValue(null),
  };
  const config = { get: jest.fn().mockReturnValue(4 * 1024 * 1024) };
  const service = new KycService(prisma as never, storage as never, config as never, { emit: jest.fn() } as never);

  beforeEach(() => {
    jest.clearAllMocks();
    prisma.systemSetting.findUnique.mockResolvedValue(null);
    prisma.driverDocument.updateMany.mockResolvedValue({ count: 0 });
    prisma.district.findMany.mockResolvedValue([]);
  });

  it('blocks going online when KYC is pending', () => {
    expect(
      service.computeCanGoOnline('pending', [{ type: 'LICENSE', status: 'verified' }], [
        'LICENSE',
        'RC',
        'INSURANCE',
        'SELFIE',
        'ID_PROOF',
      ]),
    ).toBe(false);
  });

  it('allows going online only when KYC and required docs are verified', () => {
    const required = ['LICENSE', 'RC', 'INSURANCE', 'SELFIE', 'ID_PROOF'] as const;
    const docs = required.map((type) => ({ type, status: 'verified' }));
    expect(service.computeCanGoOnline('verified', docs, [...required])).toBe(true);
  });

  it('throws when assertCanGoOnline sees pending KYC', async () => {
    prisma.driver.findUnique.mockResolvedValue({
      id: 1n,
      userId: 9n,
      kycStatus: 'pending',
      online: false,
      city: 'Patna',
      licenseNo: 'PENDING',
      termsAcceptedAt: null,
      applicationSubmittedAt: null,
      kycRejectedReason: null,
      bankAccountHolder: null,
      bankIfsc: null,
      bankAccountLast4: null,
      upiId: null,
      idType: null,
      idLast4: null,
      user: {
        name: 'A',
        email: 'a@b.c',
        phone: '9888888888',
        dateOfBirth: null,
        gender: null,
        districtId: 1,
      },
      documents: [],
      vehicles: [],
    });
    await expect(service.assertCanGoOnline(9n)).rejects.toBeInstanceOf(ForbiddenException);
  });
});
