import {
  settleCommission,
  settleFromQuoteSnapshot,
  splitPlatformCommission,
  serializeCommissionPolicy,
} from './commission.engine';

const emptyOther = {
  gstPaise: 0,
  tollPaise: 0,
  parkingPaise: 0,
  waitingPaise: 0,
  discountPaise: 0,
  otherPaise: 0,
};

describe('commission engine', () => {
  it('takes 10% of eligible base only (₹10,000 → ₹1,000)', () => {
    const settled = settleCommission(
      { basePaise: 1_000_000, ...emptyOther },
      { percent: 10, onBaseFare: true },
      1_000_000,
    );
    expect(settled.eligiblePaise).toBe(1_000_000);
    expect(settled.commissionPaise).toBe(100_000);
    expect(settled.netPaise).toBe(900_000);
  });

  it('does not commission toll when onToll is false', () => {
    const settled = settleCommission(
      {
        basePaise: 800_000,
        gstPaise: 0,
        tollPaise: 200_000,
        parkingPaise: 0,
        waitingPaise: 0,
        discountPaise: 0,
        otherPaise: 0,
      },
      { percent: 10, onBaseFare: true, onToll: false },
      1_000_000,
    );
    expect(settled.eligiblePaise).toBe(800_000);
    expect(settled.commissionPaise).toBe(80_000);
    expect(settled.netPaise).toBe(920_000);
  });

  it('does not commission discount unless onDiscount is true', () => {
    const buckets = { basePaise: 1_000_000, ...emptyOther, discountPaise: 100_000 };
    const off = settleCommission(buckets, { percent: 10, onBaseFare: true, onDiscount: false }, 900_000);
    expect(off.eligiblePaise).toBe(1_000_000);
    expect(off.commissionPaise).toBe(100_000);
    const on = settleCommission(buckets, { percent: 10, onBaseFare: true, onDiscount: true }, 900_000);
    expect(on.eligiblePaise).toBe(1_100_000);
    expect(on.commissionPaise).toBe(110_000);
  });

  it('does not invent 10% of the whole fare when snapshot has no commission', () => {
    const settled = settleFromQuoteSnapshot({ totalPaise: 10_000 });
    expect(settled.commissionPaise).toBe(0);
    expect(settled.netPaise).toBe(10_000);
  });

  it('splits platform commission without exceeding the withheld amount', () => {
    expect(splitPlatformCommission(100_000, { fleetPercent: 20, territoryPercent: 30 })).toEqual({
      fleetPaise: 20_000,
      territoryPaise: 30_000,
      unallocatedPaise: 50_000,
    });
    expect(splitPlatformCommission(100_000, { fleetPercent: 80, territoryPercent: 80 })).toEqual({
      fleetPaise: 50_000,
      territoryPaise: 50_000,
      unallocatedPaise: 0,
    });
  });

  it('exposes explicit appliesTo flags so apps do not guess components', () => {
    const policy = serializeCommissionPolicy({
      percent: 10,
      onBaseFare: true,
      onGst: false,
      onToll: false,
      onParking: false,
      onWaiting: false,
      onDiscount: false,
      onOther: false,
    });
    expect(policy.appliesTo).toEqual({
      baseFare: true,
      gst: false,
      toll: false,
      parking: false,
      waiting: false,
      discount: false,
      otherCharges: false,
      completeBookingAmount: false,
    });
    expect(policy.source).toBe('server');
  });
});
