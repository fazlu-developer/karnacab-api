export const COUPON_KINDS = ['percent', 'fixed'] as const;
export const COUPON_AUDIENCES = ['all', 'new_user', 'existing_user', 'first_ride', 'corporate'] as const;

export type CouponQuoteInput = {
  code?: string | null;
  title?: string | null;
  active?: boolean;
  kind?: string | null;
  percent?: number;
  amountPaise?: number;
  maxDiscountPaise?: number;
  minFarePaise?: number;
  product?: string | null;
  stateId?: number | null;
  districtId?: number | null;
  audience?: string | null;
  usageLimit?: number;
  userLimit?: number;
  startsOn?: Date | string | null;
  endsOn?: Date | string | null;
};

export type CouponQuoteContext = {
  farePaise: number;
  product?: string | null;
  stateId?: number | null;
  districtId?: number | null;
  role?: string | null;
  bookingCount?: number;
  completedRides?: number;
  usageCount?: number;
  userUsageCount?: number;
  now?: Date;
  discountPaise?: number;
};

export function couponDiscountPaise(coupon: CouponQuoteInput, farePaise: number): number {
  const fare = Math.max(0, farePaise);
  const kind = coupon.kind ?? ((coupon.percent ?? 0) > 0 ? 'percent' : 'fixed');
  let raw = 0;
  if (kind === 'percent') {
    const percent = Math.max(0, Math.min(100, coupon.percent ?? 0));
    raw = Math.floor((fare * percent) / 100);
  } else {
    raw = Math.max(0, coupon.amountPaise ?? 0);
  }
  const max = coupon.maxDiscountPaise ?? 0;
  if (max > 0) {
    raw = Math.min(raw, max);
  }
  return Math.min(fare, raw);
}

export function quoteCoupon(coupon: CouponQuoteInput, context: CouponQuoteContext): {
  ok: boolean;
  reason: string | null;
  discountPaise: number;
  code: string | null;
  title: string | null;
} {
  const { discountPaise: _ignored, ...safe } = context;
  void _ignored;
  const empty = {
    ok: false,
    reason: null as string | null,
    discountPaise: 0,
    code: coupon.code ?? null,
    title: coupon.title ?? null,
  };
  if (!coupon.active) {
    return { ...empty, reason: 'inactive' };
  }
  const now = safe.now ?? new Date();
  if (coupon.startsOn && now < new Date(coupon.startsOn)) {
    return { ...empty, reason: 'not_started' };
  }
  if (coupon.endsOn && now > new Date(coupon.endsOn)) {
    return { ...empty, reason: 'expired' };
  }
  const fare = Math.max(0, safe.farePaise);
  if ((coupon.minFarePaise ?? 0) > 0 && fare < (coupon.minFarePaise ?? 0)) {
    return { ...empty, reason: 'min_fare' };
  }
  if (coupon.product && safe.product !== coupon.product) {
    return { ...empty, reason: 'service' };
  }
  if (coupon.stateId != null && safe.stateId !== coupon.stateId) {
    return { ...empty, reason: 'state' };
  }
  if (coupon.districtId != null && safe.districtId !== coupon.districtId) {
    return { ...empty, reason: 'district' };
  }
  const audience = coupon.audience ?? 'all';
  const bookings = safe.bookingCount ?? 0;
  const completed = safe.completedRides ?? 0;
  if (audience === 'new_user' && bookings !== 0) {
    return { ...empty, reason: 'new_user' };
  }
  if (audience === 'existing_user' && completed <= 0) {
    return { ...empty, reason: 'existing_user' };
  }
  if (audience === 'first_ride' && completed !== 0) {
    return { ...empty, reason: 'first_ride' };
  }
  if (audience === 'corporate' && safe.role !== 'CORPORATE') {
    return { ...empty, reason: 'corporate' };
  }
  if ((coupon.usageLimit ?? 0) > 0 && (safe.usageCount ?? 0) >= (coupon.usageLimit ?? 0)) {
    return { ...empty, reason: 'usage_limit' };
  }
  if ((coupon.userLimit ?? 0) > 0 && (safe.userUsageCount ?? 0) >= (coupon.userLimit ?? 0)) {
    return { ...empty, reason: 'user_limit' };
  }
  const discount = couponDiscountPaise(coupon, fare);
  if (discount <= 0) {
    return { ...empty, reason: 'no_discount' };
  }
  return { ok: true, reason: null, discountPaise: discount, code: coupon.code ?? null, title: coupon.title ?? null };
}

export function couponMessage(reason: string | null): string {
  switch (reason) {
    case 'min_fare':
      return 'Booking amount is below the coupon minimum';
    case 'service':
      return 'This coupon is not valid for this service';
    case 'state':
      return 'This coupon is not valid in your state';
    case 'district':
      return 'This coupon is not valid in your district';
    case 'new_user':
      return 'This coupon is for new users only';
    case 'existing_user':
      return 'This coupon is for existing users only';
    case 'first_ride':
      return 'This coupon is for a first ride only';
    case 'corporate':
      return 'This coupon is for corporate accounts only';
    case 'usage_limit':
      return 'This coupon has reached its usage limit';
    case 'user_limit':
      return 'You have already used this coupon';
    default:
      return 'This coupon is not valid';
  }
}
