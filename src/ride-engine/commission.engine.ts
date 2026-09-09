export type CommissionBuckets = {
  basePaise: number;
  gstPaise: number;
  tollPaise: number;
  parkingPaise: number;
  waitingPaise: number;
  discountPaise: number;
  otherPaise: number;
};

export type CommissionRuleFlags = {
  percent?: unknown;
  onBaseFare?: boolean | null;
  onGst?: boolean | null;
  onToll?: boolean | null;
  onParking?: boolean | null;
  onWaiting?: boolean | null;
  onDiscount?: boolean | null;
  onOther?: boolean | null;
  onComplete?: boolean | null;
};

export type CommissionSettlement = {
  percent: number;
  eligiblePaise: number;
  commissionPaise: number;
  netPaise: number;
  buckets: CommissionBuckets;
  applied: CommissionBuckets;
};

export type CommissionPolicyView = {
  percent: number;
  onBaseFare: boolean;
  onGst: boolean;
  onToll: boolean;
  onParking: boolean;
  onWaiting: boolean;
  onDiscount: boolean;
  onOther: boolean;
  onComplete: boolean;
  appliesTo: {
    baseFare: boolean;
    gst: boolean;
    toll: boolean;
    parking: boolean;
    waiting: boolean;
    discount: boolean;
    otherCharges: boolean;
    completeBookingAmount: boolean;
  };
  source: 'server';
  note: string;
};

export const DEFAULT_COMMISSION_PERCENT = 10;

export const COMMISSION_POLICY_NOTE =
  'Commission is calculated only on the server from this rule. Apps must display quote.commission and wallet ledger rows and must not recompute splits.';

export function emptyCommissionBuckets(): CommissionBuckets {
  return {
    basePaise: 0,
    gstPaise: 0,
    tollPaise: 0,
    parkingPaise: 0,
    waitingPaise: 0,
    discountPaise: 0,
    otherPaise: 0,
  };
}

export function serializeCommissionPolicy(
  rule?: CommissionRuleFlags | null,
): CommissionPolicyView {
  const onBaseFare = rule?.onBaseFare !== false;
  const onGst = Boolean(rule?.onGst);
  const onToll = Boolean(rule?.onToll);
  const onParking = Boolean(rule?.onParking);
  const onWaiting = Boolean(rule?.onWaiting);
  const onDiscount = Boolean(rule?.onDiscount);
  const onOther = Boolean(rule?.onOther);
  const onComplete = Boolean(rule?.onComplete);
  return {
    percent: Number(rule?.percent ?? DEFAULT_COMMISSION_PERCENT),
    onBaseFare,
    onGst,
    onToll,
    onParking,
    onWaiting,
    onDiscount,
    onOther,
    onComplete,
    appliesTo: {
      baseFare: onBaseFare,
      gst: onGst,
      toll: onToll,
      parking: onParking,
      waiting: onWaiting,
      discount: onDiscount,
      otherCharges: onOther,
      completeBookingAmount: onComplete,
    },
    source: 'server',
    note: COMMISSION_POLICY_NOTE,
  };
}

export function bucketsFromBreakdown(breakdown: {
  basePaise?: number;
  distancePaise?: number;
  extraPaise?: number;
  waitingPaise?: number;
  nightPaise?: number;
  driverAllowPaise?: number;
  nightStayPaise?: number;
  rentalExtraPaise?: number;
  stopPaise?: number;
  gstPaise?: number;
  tollPaise?: number;
  parkingPaise?: number;
  discountPaise?: number;
}): CommissionBuckets {
  return {
    basePaise: (breakdown.basePaise ?? 0) + (breakdown.distancePaise ?? 0) + (breakdown.extraPaise ?? 0),
    gstPaise: breakdown.gstPaise ?? 0,
    tollPaise: breakdown.tollPaise ?? 0,
    parkingPaise: breakdown.parkingPaise ?? 0,
    waitingPaise: breakdown.waitingPaise ?? 0,
    discountPaise: breakdown.discountPaise ?? 0,
    otherPaise:
      (breakdown.nightPaise ?? 0) +
      (breakdown.driverAllowPaise ?? 0) +
      (breakdown.nightStayPaise ?? 0) +
      (breakdown.rentalExtraPaise ?? 0) +
      (breakdown.stopPaise ?? 0),
  };
}

export function settleCommission(
  buckets: CommissionBuckets,
  rule: CommissionRuleFlags | null | undefined,
  totalPaise: number,
): CommissionSettlement {
  const percent = Number(rule?.percent ?? DEFAULT_COMMISSION_PERCENT);
  if (rule?.onComplete) {
    const commissionPaise = Math.round((Math.max(0, totalPaise) * percent) / 100);
    return {
      percent,
      eligiblePaise: Math.max(0, totalPaise),
      commissionPaise,
      netPaise: Math.max(0, totalPaise - commissionPaise),
      buckets,
      applied: emptyCommissionBuckets(),
    };
  }
  const applied: CommissionBuckets = {
    basePaise: rule?.onBaseFare === false ? 0 : buckets.basePaise,
    gstPaise: rule?.onGst ? buckets.gstPaise : 0,
    tollPaise: rule?.onToll ? buckets.tollPaise : 0,
    parkingPaise: rule?.onParking ? buckets.parkingPaise : 0,
    waitingPaise: rule?.onWaiting ? buckets.waitingPaise : 0,
    discountPaise: rule?.onDiscount ? buckets.discountPaise : 0,
    otherPaise: rule?.onOther ? buckets.otherPaise : 0,
  };
  const eligiblePaise =
    applied.basePaise +
    applied.gstPaise +
    applied.tollPaise +
    applied.parkingPaise +
    applied.waitingPaise +
    applied.discountPaise +
    applied.otherPaise;
  const commissionPaise = Math.round((eligiblePaise * percent) / 100);
  return {
    percent,
    eligiblePaise,
    commissionPaise,
    netPaise: Math.max(0, totalPaise - commissionPaise),
    buckets,
    applied,
  };
}

function asBuckets(value: unknown): CommissionBuckets {
  const row = value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
  return {
    basePaise: Number(row.basePaise ?? 0) || 0,
    gstPaise: Number(row.gstPaise ?? 0) || 0,
    tollPaise: Number(row.tollPaise ?? 0) || 0,
    parkingPaise: Number(row.parkingPaise ?? 0) || 0,
    waitingPaise: Number(row.waitingPaise ?? 0) || 0,
    discountPaise: Number(row.discountPaise ?? 0) || 0,
    otherPaise: Number(row.otherPaise ?? 0) || 0,
  };
}

export function settleFromQuoteSnapshot(snapshot: unknown, fallbackTotalPaise = 0): CommissionSettlement {
  const row =
    snapshot && typeof snapshot === 'object' ? (snapshot as Record<string, unknown>) : {};
  const totalPaise = Number(row.totalPaise ?? fallbackTotalPaise) || 0;
  const stored = row.commission;
  if (stored && typeof stored === 'object') {
    const c = stored as Record<string, unknown>;
    if (Number.isFinite(Number(c.commissionPaise))) {
      const buckets = asBuckets(c.buckets);
      const commissionPaise = Number(c.commissionPaise);
      return {
        percent: Number(c.percent ?? DEFAULT_COMMISSION_PERCENT),
        eligiblePaise: Number(c.eligiblePaise ?? 0),
        commissionPaise,
        netPaise: Number.isFinite(Number(c.netPaise)) ? Number(c.netPaise) : Math.max(0, totalPaise - commissionPaise),
        buckets,
        applied: c.applied ? asBuckets(c.applied) : buckets,
      };
    }
  }
  if (row.breakdown && typeof row.breakdown === 'object') {
    const buckets = bucketsFromBreakdown(row.breakdown as Parameters<typeof bucketsFromBreakdown>[0]);
    return settleCommission(
      buckets,
      {
        percent: Number(row.commissionPercent ?? DEFAULT_COMMISSION_PERCENT),
        onBaseFare: true,
        onGst: false,
        onToll: false,
        onParking: false,
        onWaiting: false,
        onDiscount: false,
        onOther: false,
      },
      totalPaise,
    );
  }
  if (Number.isFinite(Number(row.commissionPaise))) {
    const commissionPaise = Number(row.commissionPaise);
    return {
      percent: Number(row.commissionPercent ?? DEFAULT_COMMISSION_PERCENT),
      eligiblePaise: 0,
      commissionPaise,
      netPaise: Math.max(0, totalPaise - commissionPaise),
      buckets: emptyCommissionBuckets(),
      applied: emptyCommissionBuckets(),
    };
  }
  return {
    percent: DEFAULT_COMMISSION_PERCENT,
    eligiblePaise: 0,
    commissionPaise: 0,
    netPaise: totalPaise,
    buckets: emptyCommissionBuckets(),
    applied: emptyCommissionBuckets(),
  };
}

export function splitPlatformCommission(
  commissionPaise: number,
  shares: { fleetPercent: number; territoryPercent: number },
): { fleetPaise: number; territoryPaise: number; unallocatedPaise: number } {
  const commission = Math.max(0, Math.round(commissionPaise));
  const fleetPct = Math.min(100, Math.max(0, Number(shares.fleetPercent) || 0));
  const territoryPct = Math.min(100, Math.max(0, Number(shares.territoryPercent) || 0));
  let fleetPaise = Math.round((commission * fleetPct) / 100);
  let territoryPaise = Math.round((commission * territoryPct) / 100);
  if (fleetPaise + territoryPaise > commission) {
    const totalPct = fleetPct + territoryPct;
    fleetPaise = totalPct > 0 ? Math.round((commission * fleetPct) / totalPct) : 0;
    territoryPaise = commission - fleetPaise;
  }
  return {
    fleetPaise,
    territoryPaise,
    unallocatedPaise: Math.max(0, commission - fleetPaise - territoryPaise),
  };
}
