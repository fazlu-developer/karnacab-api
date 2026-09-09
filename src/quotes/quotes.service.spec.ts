import { RideProduct, VehicleCategory } from '@prisma/client';
import { QuotesService } from './quotes.service';
import { FareEngine } from '../ride-engine/fare.engine';

describe('QuotesService One-Way', () => {
  const prisma = {
    fareRule: { findFirst: jest.fn() },
    commissionRule: { findFirst: jest.fn() },
  };
  const demoDriver = { placeNearby: jest.fn() };
  const fares = new FareEngine(prisma as never);
  const maps = { directions: jest.fn(), pathKm: jest.fn() };
  const service = new QuotesService(demoDriver as never, fares, maps as never);

  it('quotes ONE_WAY from fare_rules without changing product', async () => {
    prisma.fareRule.findFirst.mockResolvedValue({
      minKm: 10,
      includedKm: 10,
      perKmPaise: 1800,
      extraKmPaise: 1800,
      waitingPaise: 0,
      nightPercent: 0,
      gstPercent: 0,
      cancelPaise: 0,
      driverAllowPaise: 0,
      applyToll: false,
      applyParking: false,
      applyGstToBase: false,
    });
    prisma.commissionRule.findFirst.mockResolvedValue({
      percent: 10,
      onBaseFare: true,
      onGst: false,
      onToll: false,
      onParking: false,
      onWaiting: false,
      onOther: false,
      onDiscount: false,
    });

    const quote = await service.ride({
      product: RideProduct.ONE_WAY,
      category: VehicleCategory.SEDAN,
      distanceKm: 12,
    });

    expect(quote.product).toBe(RideProduct.ONE_WAY);
    expect(quote.category).toBe(VehicleCategory.SEDAN);
    expect(quote.totalPaise).toBe(21600);
  });
});
