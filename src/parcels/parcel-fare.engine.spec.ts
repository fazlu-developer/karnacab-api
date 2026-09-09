import { VehicleCategory } from '@prisma/client';
import { ParcelFareEngine } from './parcel-fare.engine';

describe('ParcelFareEngine', () => {
  const prisma = {
    parcelFareRule: { findFirst: jest.fn() },
  };
  const engine = new ParcelFareEngine(prisma as never);

  it('quotes local parcel from parcel_fare_rules', async () => {
    prisma.parcelFareRule.findFirst.mockResolvedValue({
      minKm: 2,
      includedKm: 2,
      perKmPaise: 1200,
      extraKmPaise: 1500,
      perKgPaise: 800,
      minChargePaise: 4900,
      gstPercent: 0,
      volumetricDivisor: 5000,
    });
    const quote = await engine.quote({
      biharLane: false,
      category: VehicleCategory.BIKE,
      distanceKm: 4,
      weightKg: 1,
    });
    expect(quote.source).toBe('server');
    expect(quote.lane).toBe('LOCAL');
    expect(quote.billedKm).toBe(4);
    expect(quote.totalPaise).toBe(6200);
  });
});
