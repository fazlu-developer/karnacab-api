import { couponDiscountPaise, quoteCoupon } from './coupon.engine';

describe('coupon engine', () => {
  it('quotes percent with a max cap and ignores client discount', () => {
    const quoted = quoteCoupon(
      { active: true, kind: 'percent', percent: 50, maxDiscountPaise: 1000, code: 'SAVE' },
      { farePaise: 10_000, discountPaise: 9000 },
    );
    expect(quoted.ok).toBe(true);
    expect(quoted.discountPaise).toBe(1000);
    expect(couponDiscountPaise({ kind: 'fixed', amountPaise: 5000 }, 20_000)).toBe(5000);
  });

  it('enforces service, territory, first ride, and limits', () => {
    const coupon = {
      active: true,
      kind: 'percent',
      percent: 10,
      product: 'AIRPORT',
      stateId: 1,
      districtId: 10,
      audience: 'first_ride',
      usageLimit: 1,
    };
    expect(quoteCoupon(coupon, { farePaise: 20_000, product: 'LOCAL_CAB', stateId: 1, districtId: 10, completedRides: 0 }).reason).toBe('service');
    expect(quoteCoupon(coupon, { farePaise: 20_000, product: 'AIRPORT', stateId: 2, districtId: 10, completedRides: 0 }).reason).toBe('state');
    expect(quoteCoupon(coupon, { farePaise: 20_000, product: 'AIRPORT', stateId: 1, districtId: 10, completedRides: 1 }).reason).toBe('first_ride');
    expect(quoteCoupon(coupon, { farePaise: 20_000, product: 'AIRPORT', stateId: 1, districtId: 10, completedRides: 0, usageCount: 1 }).reason).toBe('usage_limit');
  });
});
