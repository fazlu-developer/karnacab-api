import {
  adCtr,
  campaignIsLive,
  canServeAds,
  matchesAdLocation,
} from './ad.policy';

describe('KarnaCab ads policy', () => {
  it('allows home, travel, discovery, and catalog only', () => {
    expect(canServeAds({ placement: 'home' }).ok).toBe(true);
    expect(canServeAds({ placement: 'travel' }).ok).toBe(true);
    expect(canServeAds({ placement: 'discovery' }).ok).toBe(true);
    expect(canServeAds({ placement: 'catalog' }).ok).toBe(true);
  });

  it('blocks booking, driver arrival, trip, SOS, payment, OTP, and navigation', () => {
    expect(canServeAds({ placement: 'booking' })).toEqual({ ok: false, reason: 'blocked_placement' });
    expect(canServeAds({ placement: 'driver_arrival' }).ok).toBe(false);
    expect(canServeAds({ placement: 'trip' }).ok).toBe(false);
    expect(canServeAds({ placement: 'sos' }).ok).toBe(false);
    expect(canServeAds({ placement: 'payment' }).ok).toBe(false);
    expect(canServeAds({ placement: 'otp' }).ok).toBe(false);
    expect(canServeAds({ placement: 'navigation' }).ok).toBe(false);
    expect(canServeAds({ placement: 'home', suppressed: 'active_trip' }).ok).toBe(false);
  });

  it('matches district and city targeting', () => {
    expect(
      matchesAdLocation({
        campaignStateId: 1,
        campaignDistrictId: 26,
        campaignCity: 'Patna',
        actorStateId: 1,
        actorDistrictId: 26,
        actorCity: 'Patna Junction, Patna, Bihar',
      }),
    ).toBe(true);
    expect(
      matchesAdLocation({
        campaignStateId: 1,
        campaignDistrictId: 26,
        campaignCity: null,
        actorStateId: 2,
        actorDistrictId: 26,
        actorCity: null,
      }),
    ).toBe(false);
  });

  it('serves only published in-window campaigns with remaining budget', () => {
    const now = new Date('2026-09-07T12:00:00Z');
    expect(
      campaignIsLive({
        status: 'published',
        startsOn: new Date('2026-09-01'),
        endsOn: new Date('2026-09-30'),
        budgetPaise: 10_000,
        budgetUsedPaise: 100,
        now,
      }),
    ).toBe(true);
    expect(
      campaignIsLive({
        status: 'pending',
        startsOn: new Date('2026-09-01'),
        endsOn: new Date('2026-09-30'),
        budgetPaise: 10_000,
        budgetUsedPaise: 0,
        now,
      }),
    ).toBe(false);
  });

  it('computes CTR from impressions and clicks', () => {
    expect(adCtr(0, 0)).toBe(0);
    expect(adCtr(100, 5)).toBe(5);
  });
});
