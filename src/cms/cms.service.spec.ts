import { CmsService } from './cms.service';

describe('CmsService', () => {
  const prisma = {
    cmsPage: {
      findMany: jest.fn(),
      findUnique: jest.fn(),
    },
    systemSetting: { findUnique: jest.fn() },
  };
  const catalog = { listPublic: jest.fn() };
  const support = { faqs: jest.fn() };
  const fares = { rentalPackages: jest.fn() };
  const cms = new CmsService(prisma as never, catalog as never, support as never, fares as never);

  beforeEach(() => {
    jest.clearAllMocks();
    catalog.listPublic.mockResolvedValue({
      rideTypes: [{ key: 'ONE_WAY', title: 'One Way' }],
      districts: [{ id: 1, name: 'Patna' }],
      packages: [],
    });
    support.faqs.mockResolvedValue({ faqs: [{ q: 'Where is my booking?', a: 'Open My bookings.' }] });
    fares.rentalPackages.mockResolvedValue([{ hours: 8 }]);
    prisma.systemSetting.findUnique.mockResolvedValue(null);
    prisma.cmsPage.findMany.mockResolvedValue([
      {
        id: 1n,
        slug: 'one-way',
        title: 'One Way',
        eyebrow: 'Ride',
        seoTitle: null,
        seoDescription: null,
        lede: 'Point-to-point trips from Admin fare rules.',
        body: { sections: [] },
        template: 'service',
        leadType: 'RIDE',
        registerKind: null,
        productKey: 'ONE_WAY',
        navGroup: 'rides',
        navLabel: 'One way',
        sortOrder: 21,
        published: true,
        updatedAt: new Date('2026-09-08T00:00:00Z'),
      },
    ]);
  });

  it('exposes catalog districts and FAQs instead of hard-coded fares', async () => {
    const site = await cms.site();
    expect(site.catalog.districts[0].name).toBe('Patna');
    expect(site.catalog.rentalPackages[0].hours).toBe(8);
    expect(site.faqs[0].q).toContain('booking');
    expect(site.pages[0].productKey).toBe('ONE_WAY');
    expect(site.pages[0].lede).not.toMatch(/₹|rs\s*\d/i);
  });
});
