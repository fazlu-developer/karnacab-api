export const PARCEL_STATUSES = [
  'created',
  'assigned',
  'picked_up',
  'in_transit',
  'destination',
  'out_for_delivery',
  'delivered',
  'cancelled',
] as const;

export type ParcelStatus = (typeof PARCEL_STATUSES)[number];

export type ParcelAction =
  | 'pickup'
  | 'transit'
  | 'arrive'
  | 'out_for_delivery'
  | 'deliver'
  | 'cancel';

export const PARCEL_STATUS_LABELS: Record<ParcelStatus, string> = {
  created: 'Created',
  assigned: 'Assigned',
  picked_up: 'Picked Up',
  in_transit: 'In Transit',
  destination: 'Destination',
  out_for_delivery: 'Out for Delivery',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
};

const TRANSITIONS: Record<ParcelStatus, Partial<Record<ParcelAction, ParcelStatus>>> = {
  created: { cancel: 'cancelled' },
  assigned: { pickup: 'picked_up', cancel: 'cancelled' },
  picked_up: { transit: 'in_transit', cancel: 'cancelled' },
  in_transit: { arrive: 'destination' },
  destination: { out_for_delivery: 'out_for_delivery' },
  out_for_delivery: { deliver: 'delivered' },
  delivered: {},
  cancelled: {},
};

export function normalizeParcelStatus(status: string): ParcelStatus {
  if (status === 'requested') {
    return 'created';
  }
  return (PARCEL_STATUSES as readonly string[]).includes(status) ? (status as ParcelStatus) : 'created';
}

export function nextParcelStatus(status: string, action: ParcelAction): ParcelStatus {
  const current = normalizeParcelStatus(status);
  const next = TRANSITIONS[current][action];
  if (!next) {
    throw new Error(`Cannot ${action} a parcel that is ${PARCEL_STATUS_LABELS[current]}`);
  }
  return next;
}

export function parcelAllowedActions(status: string, role: 'CUSTOMER' | 'DRIVER' | 'OPS'): ParcelAction[] {
  const current = normalizeParcelStatus(status);
  const actions = Object.keys(TRANSITIONS[current]) as ParcelAction[];
  if (role === 'DRIVER') {
    return actions.filter((action) => action !== 'cancel' || current === 'assigned');
  }
  if (role === 'CUSTOMER') {
    return current === 'created' || current === 'assigned' ? ['cancel'] : [];
  }
  return actions;
}
