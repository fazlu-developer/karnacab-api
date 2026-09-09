import { RideProduct, VehicleCategory } from '@prisma/client';
import { FareEngine } from './fare.engine';

describe('FareEngine', () => {
  const prisma = {
    fareRule: { findFirst: jest.fn() },
    commissionRule: { findFirst: jest.fn() },
  };
  const engine = new FareEngine(prisma as never);

  const sedanRule = {
    minKm: 10,
    includedKm: 10,
    perKmPaise: 1800,
    extraKmPaise: 1800,
    waitingPaise: 200,
    nightPercent: 15,
    gstPercent: 5,
    cancelPaise: 5000,
    driverAllowPaise: 0,
    rentalHours: null,
    applyToll: true,
    applyParking: true,
    applyGstToBase: true,
    discountPaise: 0,
    discountPercent: 0,
  };

  beforeEach(() => {
    prisma.fareRule.findFirst.mockResolvedValue({ ...sedanRule });
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
  });

  it('keeps ONE_WAY totals on the original fare_rules formula', async () => {
    prisma.fareRule.findFirst.mockResolvedValue({
      ...sedanRule,
      waitingPaise: 0,
      nightPercent: 0,
      gstPercent: 0,
      applyToll: false,
      applyParking: false,
      applyGstToBase: false,
    });
    const quote = await engine.quote({
      product: RideProduct.ONE_WAY,
      category: VehicleCategory.SEDAN,
      distanceKm: 12,
    });
    expect(quote.product).toBe(RideProduct.ONE_WAY);
    expect(quote.source).toBe('server');
    expect(quote.totalPaise).toBe(21600);
    expect(quote.totalRupees).toBe(216);
    expect(quote.commissionPaise).toBe(2160);
    expect(quote.commission.eligiblePaise).toBe(21600);
  });

  it('does not apply multi-stop extras to ONE_WAY', async () => {
    prisma.fareRule.findFirst.mockResolvedValue({
      ...sedanRule,
      waitingPaise: 0,
      nightPercent: 0,
      gstPercent: 0,
      applyToll: false,
      applyParking: false,
      applyGstToBase: false,
    });
    const quote = await engine.quote({
      product: RideProduct.ONE_WAY,
      category: VehicleCategory.SEDAN,
      distanceKm: 12,
      stopCount: 4,
    });
    expect(quote.breakdown.stopPaise).toBe(0);
    expect(quote.totalPaise).toBe(21600);
  });

  it('builds a Local Cab breakdown from admin fare_rules', async () => {
    const quote = await engine.quote({
      product: RideProduct.LOCAL_CAB,
      category: VehicleCategory.SEDAN,
      distanceKm: 12,
      waitMinutes: 10,
      night: true,
      tollPaise: 2000,
      parkingPaise: 1000,
    });
    const labels = quote.lines.map((row) => row.label);
    expect(labels).toEqual([
      'Base',
      'Distance',
      'Extra KM',
      'Waiting',
      'Night',
      'Toll',
      'Parking',
      'GST',
      'Discount',
      'Total',
    ]);
    expect(quote.rates.minKm).toBe(10);
    expect(quote.rates.perKmPaise).toBe(1800);
    expect(quote.rates.waitingPaisePerMin).toBe(200);
    expect(quote.rates.nightPercent).toBe(15);
    expect(quote.rates.gstPercent).toBe(5);
    expect(quote.rates.cancelPaise).toBe(5000);
    expect(quote.breakdown.waitingPaise).toBe(2000);
    expect(quote.breakdown.tollPaise).toBe(2000);
    expect(quote.breakdown.parkingPaise).toBe(1000);
    expect(quote.breakdown.nightPaise).toBeGreaterThan(0);
    expect(quote.breakdown.gstPaise).toBeGreaterThan(0);
    expect(quote.totalPaise).toBe(quote.lines.find((row) => row.key === 'total')?.paise);
  });

  it('quotes Round Way with driver allowance, night stay, and round-trip KM', async () => {
    prisma.fareRule.findFirst.mockResolvedValue({
      ...sedanRule,
      driverAllowPaise: 40000,
      nightStayPaise: 50000,
      waitingPaise: 0,
      nightPercent: 0,
      gstPercent: 0,
      applyToll: false,
      applyParking: false,
      applyGstToBase: false,
    });
    const quote = await engine.quote({
      product: RideProduct.ROUND_WAY,
      category: VehicleCategory.SEDAN,
      distanceKm: 12,
      nightStayNights: 1,
    });
    expect(quote.billedKm).toBe(24);
    expect(quote.breakdown.driverAllowPaise).toBe(40000);
    expect(quote.breakdown.nightStayPaise).toBe(50000);
    expect(quote.lines.map((row) => row.label)).toContain('Driver allowance');
    expect(quote.lines.map((row) => row.label)).toContain('Night stay');
  });

  it('quotes Rental packages using extra hour from fare_rules', async () => {
    prisma.fareRule.findFirst.mockResolvedValue({
      ...sedanRule,
      minKm: 40,
      includedKm: 40,
      rentalHours: 4,
      extraHourPaise: 15000,
      waitingPaise: 0,
      nightPercent: 0,
      gstPercent: 0,
      applyToll: false,
      applyParking: false,
      applyGstToBase: false,
    });
    const quote = await engine.quote({
      product: RideProduct.RENTAL,
      category: VehicleCategory.SEDAN,
      distanceKm: 12,
      hours: 4,
      extraHours: 1,
    });
    expect(quote.hours).toBe(4);
    expect(quote.breakdown.rentalExtraPaise).toBe(15000);
    expect(quote.lines.map((row) => row.label)).toEqual([
      'Package',
      'Extra KM',
      'Extra hour',
      'Toll',
      'Parking',
      'GST',
      'Discount',
      'Total',
    ]);
  });

  it('applies admin discount without changing a zero-discount One-Way total', async () => {
    prisma.fareRule.findFirst.mockResolvedValue({
      ...sedanRule,
      waitingPaise: 0,
      nightPercent: 0,
      gstPercent: 0,
      applyToll: false,
      applyParking: false,
      applyGstToBase: false,
      discountPaise: 600,
    });
    const quote = await engine.quote({
      product: RideProduct.ONE_WAY,
      category: VehicleCategory.SEDAN,
      distanceKm: 12,
    });
    expect(quote.breakdown.discountPaise).toBe(600);
    expect(quote.totalPaise).toBe(21000);
  });

  it('quotes MULTI_STOP with per-stop fare_rules and does not change ONE_WAY', async () => {
    prisma.fareRule.findFirst.mockResolvedValue({
      ...sedanRule,
      stopPaise: 1500,
      waitingPaise: 0,
      nightPercent: 0,
      gstPercent: 0,
      applyToll: false,
      applyParking: false,
      applyGstToBase: false,
    });
    const quote = await engine.quote({
      product: RideProduct.MULTI_STOP,
      category: VehicleCategory.SEDAN,
      distanceKm: 12,
      stopCount: 3,
    });
    expect(quote.breakdown.stopPaise).toBe(4500);
    expect(quote.lines.map((row) => row.label)).toContain('Stops');
    expect(quote.totalPaise).toBe(26100);
  });

  it('quotes AIRPORT with the same distance formula as local without stop extras', async () => {
    prisma.fareRule.findFirst.mockResolvedValue({
      ...sedanRule,
      waitingPaise: 0,
      nightPercent: 0,
      gstPercent: 0,
      applyToll: false,
      applyParking: false,
      applyGstToBase: false,
    });
    const quote = await engine.quote({
      product: RideProduct.AIRPORT,
      category: VehicleCategory.SEDAN,
      distanceKm: 12,
      stopCount: 2,
    });
    expect(quote.product).toBe(RideProduct.AIRPORT);
    expect(quote.breakdown.stopPaise).toBe(0);
    expect(quote.totalPaise).toBe(21600);
  });

  it('does not take commission on waiting or toll unless those flags are on', async () => {
    prisma.fareRule.findFirst.mockResolvedValue({
      ...sedanRule,
      waitingPaise: 200,
      nightPercent: 0,
      gstPercent: 0,
      applyToll: true,
      applyParking: false,
      applyGstToBase: false,
    });
    const quote = await engine.quote({
      product: RideProduct.ONE_WAY,
      category: VehicleCategory.SEDAN,
      distanceKm: 12,
      waitMinutes: 10,
      tollPaise: 200_000,
    });
    expect(quote.breakdown.waitingPaise).toBe(2000);
    expect(quote.breakdown.tollPaise).toBe(200_000);
    expect(quote.totalPaise).toBe(21600 + 2000 + 200_000);
    expect(quote.commission.eligiblePaise).toBe(21600);
    expect(quote.commissionPaise).toBe(2160);
  });
});
