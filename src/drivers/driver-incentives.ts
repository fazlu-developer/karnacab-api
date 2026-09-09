export type IncentiveCatalogRow = {
  id: string;
  title: string;
  subtitle: string;
  bonusPaise: number;
  targetTrips: number;
  targetEarningsPaise: number;
  period: 'day' | 'week' | 'month';
  validFrom: string | null;
  validTo: string | null;
};

export function parseIncentiveCatalog(raw?: string | null): IncentiveCatalogRow[] {
  if (!raw) {
    return [];
  }
  try {
    const parsed = JSON.parse(raw) as Array<Record<string, unknown>>;
    return parsed.map((row) => ({
      id: String(row.id ?? 'bonus'),
      title: String(row.title ?? 'Incentive'),
      subtitle: String(row.subtitle ?? ''),
      bonusPaise: Number(row.bonusPaise ?? row.amountPaise ?? 0),
      targetTrips: Number(row.targetTrips ?? 0),
      targetEarningsPaise: Number(row.targetEarningsPaise ?? 0),
      period: row.period === 'week' || row.period === 'month' ? row.period : 'day',
      validFrom: row.validFrom ? String(row.validFrom) : null,
      validTo: row.validTo ? String(row.validTo) : null,
    }));
  } catch {
    return [];
  }
}

export function istDateParts(now = new Date()) {
  const day = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Kolkata',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now);
  return day.split('-');
}

export function incentivePeriodKey(period: IncentiveCatalogRow['period'], now = new Date()) {
  const [y, m, d] = istDateParts(now);
  if (period === 'month') {
    return `M-${y}-${m}`;
  }
  if (period === 'week') {
    const [y, m, d] = istDateParts(now);
    const utc = new Date(Date.UTC(Number(y), Number(m) - 1, Number(d)));
    const week = isoWeek(utc);
    return `W-${y}-${String(week).padStart(2, '0')}`;
  }
  return `D-${y}-${m}-${d}`;
}

export function periodStart(period: IncentiveCatalogRow['period'], now = new Date()) {
  const today = startOfTodayIst(now);
  if (period === 'month') {
    const [y, m] = istDateParts(now);
    return new Date(`${y}-${m}-01T00:00:00+05:30`);
  }
  if (period === 'week') {
    return new Date(today.getTime() - ((today.getDay() + 6) % 7) * 86400000);
  }
  return today;
}

export function incentiveInValidity(row: IncentiveCatalogRow, now = new Date()) {
  if (row.validFrom && now < new Date(`${row.validFrom}T00:00:00+05:30`)) {
    return false;
  }
  if (row.validTo && now > new Date(`${row.validTo}T23:59:59+05:30`)) {
    return false;
  }
  return true;
}

export function incentiveProgress(input: {
  row: IncentiveCatalogRow;
  trips: number;
  earningsPaise: number;
  earnedPaise: number;
  now?: Date;
}) {
  const { row, trips, earningsPaise, earnedPaise } = input;
  const now = input.now ?? new Date();
  const tripRatio = row.targetTrips > 0 ? Math.min(1, trips / row.targetTrips) : 1;
  const earnRatio = row.targetEarningsPaise > 0 ? Math.min(1, earningsPaise / row.targetEarningsPaise) : 1;
  const progress = Math.min(tripRatio, earnRatio);
  const complete = progress >= 1 && (row.targetTrips > 0 || row.targetEarningsPaise > 0);
  const valid = incentiveInValidity(row, now);
  return {
    ...row,
    bonusRupees: row.bonusPaise / 100,
    targetEarningsRupees: row.targetEarningsPaise / 100,
    progressTrips: trips,
    progressEarningsPaise: earningsPaise,
    progressEarningsRupees: earningsPaise / 100,
    progress,
    complete,
    valid,
    earnedPaise,
    earnedRupees: earnedPaise / 100,
  };
}

function startOfTodayIst(now: Date) {
  const [y, m, d] = istDateParts(now);
  return new Date(`${y}-${m}-${d}T00:00:00+05:30`);
}

function isoWeek(date: Date) {
  const tmp = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()));
  const day = tmp.getUTCDay() || 7;
  tmp.setUTCDate(tmp.getUTCDate() + 4 - day);
  const yearStart = new Date(Date.UTC(tmp.getUTCFullYear(), 0, 1));
  return Math.ceil(((tmp.getTime() - yearStart.getTime()) / 86400000 + 1) / 7);
}
