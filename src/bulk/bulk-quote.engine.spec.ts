import { VehicleCategory } from '@prisma/client';
import { BulkQuoteEngine } from './bulk-quote.engine';

describe('BulkQuoteEngine', () => {
  const prisma = {
    bulkRateRule: { findFirst: jest.fn() },
  };
  const engine = new BulkQuoteEngine(prisma as never);

  it('quotes wedding traveller fleet from bulk_rate_rules', async () => {
    prisma.bulkRateRule.findFirst.mockResolvedValue({
      perVehiclePaise: 100000,
      gstPercent: 0,
    });
    const quote = await engine.quote({
      eventKey: 'WEDDING',
      category: VehicleCategory.TRAVELLER,
      vehicleCount: 3,
      advancePercent: 30,
    });
    expect(quote.source).toBe('server');
    expect(quote.totalPaise).toBe(300000);
    expect(quote.advancePaise).toBe(90000);
    expect(quote.balancePaise).toBe(210000);
  });
});
