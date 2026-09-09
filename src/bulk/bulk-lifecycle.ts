export const BULK_STATUSES = [
  'requested',
  'quoted',
  'accepted',
  'advance_paid',
  'assigned',
  'trip',
  'invoiced',
  'completed',
  'cancelled',
] as const;

export type BulkStatus = (typeof BULK_STATUSES)[number];

export type BulkAction =
  | 'quote'
  | 'accept'
  | 'pay_advance'
  | 'assign'
  | 'trip'
  | 'invoice'
  | 'pay_balance'
  | 'cancel';

export const BULK_STATUS_LABELS: Record<BulkStatus, string> = {
  requested: 'Request',
  quoted: 'Quotation',
  accepted: 'Accepted',
  advance_paid: 'Advance',
  assigned: 'Assignment',
  trip: 'Trip',
  invoiced: 'Final Invoice',
  completed: 'Completed',
  cancelled: 'Cancelled',
};

const TRANSITIONS: Record<BulkStatus, Partial<Record<BulkAction, BulkStatus>>> = {
  requested: { quote: 'quoted', cancel: 'cancelled' },
  quoted: { accept: 'accepted', quote: 'quoted', cancel: 'cancelled' },
  accepted: { pay_advance: 'advance_paid', cancel: 'cancelled' },
  advance_paid: { assign: 'assigned' },
  assigned: { trip: 'trip' },
  trip: { invoice: 'invoiced' },
  invoiced: { pay_balance: 'completed' },
  completed: {},
  cancelled: {},
};

export function nextBulkStatus(status: string, action: BulkAction): BulkStatus {
  const current = (BULK_STATUSES as readonly string[]).includes(status)
    ? (status as BulkStatus)
    : 'requested';
  const next = TRANSITIONS[current][action];
  if (!next) {
    throw new Error(`Cannot ${action} a bulk booking that is ${BULK_STATUS_LABELS[current]}`);
  }
  return next;
}

export function bulkAllowedActions(status: string, role: 'CUSTOMER' | 'OPS'): BulkAction[] {
  const current = (BULK_STATUSES as readonly string[]).includes(status)
    ? (status as BulkStatus)
    : 'requested';
  const actions = Object.keys(TRANSITIONS[current]) as BulkAction[];
  if (role === 'CUSTOMER') {
    return actions.filter((action) =>
      ['accept', 'pay_advance', 'trip', 'invoice', 'pay_balance', 'cancel'].includes(action),
    );
  }
  return actions.filter((action) => ['quote', 'assign', 'trip', 'invoice', 'cancel'].includes(action));
}

export const DEFAULT_BULK_EVENTS = [
  { key: 'WEDDING', label: 'Wedding' },
  { key: 'CORPORATE', label: 'Corporate' },
  { key: 'SCHOOL', label: 'School' },
  { key: 'COLLEGE', label: 'College' },
  { key: 'EVENTS', label: 'Events' },
  { key: 'GROUP_TOURS', label: 'Group Tours' },
];

export function parseEventList(raw?: string | null) {
  if (!raw) {
    return DEFAULT_BULK_EVENTS;
  }
  try {
    const parsed = JSON.parse(raw) as { key: string; label: string }[];
    return Array.isArray(parsed) && parsed.length ? parsed : DEFAULT_BULK_EVENTS;
  } catch {
    return DEFAULT_BULK_EVENTS;
  }
}
