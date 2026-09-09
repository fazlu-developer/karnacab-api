export const AD_CATEGORIES = [
  'hotels',
  'restaurants',
  'hospitals',
  'coaching',
  'local_businesses',
  'automobile',
  'real_estate',
  'travel',
  'other',
] as const;

export type AdCategory = (typeof AD_CATEGORIES)[number];

export const AD_CATEGORY_LABELS: Record<AdCategory, string> = {
  hotels: 'Hotels',
  restaurants: 'Restaurants',
  hospitals: 'Hospitals',
  coaching: 'Coaching Institutes',
  local_businesses: 'Local Businesses',
  automobile: 'Automobile',
  real_estate: 'Real Estate',
  travel: 'Travel Businesses',
  other: 'Other approved businesses',
};

export const AD_TYPES = ['banner', 'listing', 'discovery', 'travel'] as const;
export type AdCampaignType = (typeof AD_TYPES)[number];

export const AD_TYPE_LABELS: Record<AdCampaignType, string> = {
  banner: 'Banner',
  listing: 'Sponsored listing',
  discovery: 'Discovery card',
  travel: 'Travel promo',
};

export const AD_STATUSES = [
  'draft',
  'pending',
  'approved',
  'published',
  'paused',
  'rejected',
  'expired',
  'completed',
] as const;

export type AdStatus = (typeof AD_STATUSES)[number];

export const AD_ALLOWED_PLACEMENTS = ['home', 'travel', 'discovery', 'catalog'] as const;
export type AdAllowedPlacement = (typeof AD_ALLOWED_PLACEMENTS)[number];

export const AD_BLOCKED_PLACEMENTS = [
  'booking',
  'active_booking',
  'critical_booking',
  'otp',
  'payment',
  'wallet',
  'driver_arrival',
  'trip',
  'active_trip',
  'navigation',
  'driver_navigation',
  'sos',
  'live_trip',
] as const;

export type AdBlockedPlacement = (typeof AD_BLOCKED_PLACEMENTS)[number];

export const AD_BANNER_MIME = new Set(['image/jpeg', 'image/png', 'image/webp']);

export function isAllowedAdPlacement(placement: string): boolean {
  return (AD_ALLOWED_PLACEMENTS as readonly string[]).includes(placement);
}

export function isBlockedAdPlacement(placement: string): boolean {
  return (AD_BLOCKED_PLACEMENTS as readonly string[]).includes(placement);
}

export function canServeAds(input: {
  placement: string;
  suppressed?: string | null;
}): { ok: boolean; reason: string | null } {
  if (input.suppressed) {
    return { ok: false, reason: input.suppressed };
  }
  if (isBlockedAdPlacement(input.placement)) {
    return { ok: false, reason: 'blocked_placement' };
  }
  if (!isAllowedAdPlacement(input.placement)) {
    return { ok: false, reason: 'unknown_placement' };
  }
  return { ok: true, reason: null };
}

export function matchesAdLocation(input: {
  campaignStateId?: number | null;
  campaignDistrictId: number | null;
  campaignCity: string | null;
  actorStateId?: number | null;
  actorDistrictId: number | null;
  actorCity: string | null;
}): boolean {
  if (input.campaignStateId != null) {
    if (input.actorStateId == null || input.actorStateId !== input.campaignStateId) {
      return false;
    }
  }
  if (input.campaignDistrictId != null) {
    if (input.actorDistrictId == null || input.actorDistrictId !== input.campaignDistrictId) {
      return false;
    }
  }
  const want = (input.campaignCity ?? '').trim().toLowerCase();
  if (want) {
    const have = (input.actorCity ?? '').trim().toLowerCase();
    if (!have || !have.includes(want)) {
      return false;
    }
  }
  return true;
}

export function campaignIsLive(input: {
  status: string;
  startsOn: Date;
  endsOn: Date;
  budgetPaise: number;
  budgetUsedPaise: number;
  now?: Date;
}): boolean {
  const now = input.now ?? new Date();
  if (input.status !== 'published') {
    return false;
  }
  if (now < input.startsOn || now > input.endsOn) {
    return false;
  }
  return input.budgetUsedPaise < input.budgetPaise;
}

export function adCtr(impressions: number, clicks: number): number {
  if (!impressions) {
    return 0;
  }
  return Math.round((clicks * 10000) / impressions) / 100;
}
