export type DocAlertWindow = 'expired' | '7' | '15' | '30';

export type DocumentAlert = {
  type: string;
  label: string;
  severity: 'error' | 'warning';
  window: DocAlertWindow | 'rejected';
  daysUntilExpiry: number | null;
  message: string;
};

export function daysUntilExpiry(expiresAt: string | Date | null | undefined, now = new Date()) {
  if (!expiresAt) {
    return null;
  }
  const end =
    typeof expiresAt === 'string' ? new Date(`${expiresAt.slice(0, 10)}T00:00:00+05:30`) : expiresAt;
  if (Number.isNaN(end.getTime())) {
    return null;
  }
  const day = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Kolkata',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now);
  const start = new Date(`${day}T00:00:00+05:30`);
  return Math.round((end.getTime() - start.getTime()) / 86400000);
}

export function alertWindowForDays(days: number | null, status?: string): DocAlertWindow | 'rejected' | null {
  if (status === 'rejected') {
    return 'rejected';
  }
  if (status === 'expired' || (days != null && days < 0)) {
    return 'expired';
  }
  if (days == null) {
    return null;
  }
  if (days <= 7) {
    return '7';
  }
  if (days <= 15) {
    return '15';
  }
  if (days <= 30) {
    return '30';
  }
  return null;
}

export function alertsForDocument(doc: {
  type: string;
  label: string;
  status: string;
  expiresAt: string | null;
  rejectionReason?: string | null;
}, now = new Date()): DocumentAlert[] {
  const days = daysUntilExpiry(doc.expiresAt, now);
  const window = alertWindowForDays(days, doc.status);
  if (!window) {
    return [];
  }
  if (window === 'rejected') {
    return [
      {
        type: doc.type,
        label: doc.label,
        severity: 'error',
        window,
        daysUntilExpiry: days,
        message: doc.rejectionReason || `${doc.label} was rejected.`,
      },
    ];
  }
  if (window === 'expired') {
    return [
      {
        type: doc.type,
        label: doc.label,
        severity: 'error',
        window,
        daysUntilExpiry: days,
        message: `${doc.label} has expired. Re-upload before going online.`,
      },
    ];
  }
  const label =
    window === '7' ? '7 days' : window === '15' ? '15 days' : '30 days';
  return [
    {
      type: doc.type,
      label: doc.label,
      severity: window === '7' ? 'error' : 'warning',
      window,
      daysUntilExpiry: days,
      message: `${doc.label} expires in ${days} day${days === 1 ? '' : 's'} (${label} alert).`,
    },
  ];
}
