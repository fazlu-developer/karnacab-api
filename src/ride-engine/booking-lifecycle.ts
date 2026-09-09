import { BookingStatus, UserRole } from '@prisma/client';

export const LIFECYCLE = [
  'draft',
  'pending',
  'confirmed',
  'driver_searching',
  'driver_assigned',
  'driver_arriving',
  'driver_arrived',
  'started',
  'completed',
  'cancelled',
] as const;

export type Lifecycle = (typeof LIFECYCLE)[number];

export type LifecycleAction =
  | 'confirm'
  | 'search'
  | 'arriving'
  | 'arrived'
  | 'start'
  | 'complete'
  | 'cancel';

const FROM_DB: Record<string, Lifecycle> = {
  DRAFT: 'draft',
  PENDING: 'pending',
  QUOTED: 'pending',
  CONFIRMED: 'confirmed',
  REQUESTED: 'driver_searching',
  DRIVER_SEARCHING: 'driver_searching',
  ASSIGNED: 'driver_assigned',
  DRIVER_ASSIGNED: 'driver_assigned',
  DRIVER_ARRIVING: 'driver_arriving',
  DRIVER_ARRIVED: 'driver_arrived',
  STARTED: 'started',
  ONGOING: 'started',
  COMPLETED: 'completed',
  CANCELLED: 'cancelled',
};

/** Existing apps read REQUESTED / ASSIGNED / ONGOING. */
const TO_LEGACY: Record<Lifecycle, BookingStatus> = {
  draft: BookingStatus.REQUESTED,
  pending: BookingStatus.QUOTED,
  confirmed: BookingStatus.CONFIRMED,
  driver_searching: BookingStatus.REQUESTED,
  driver_assigned: BookingStatus.ASSIGNED,
  driver_arriving: BookingStatus.ASSIGNED,
  driver_arrived: BookingStatus.ASSIGNED,
  started: BookingStatus.ONGOING,
  completed: BookingStatus.COMPLETED,
  cancelled: BookingStatus.CANCELLED,
};

const TO_DB: Record<Lifecycle, BookingStatus> = {
  draft: BookingStatus.DRAFT,
  pending: BookingStatus.PENDING,
  confirmed: BookingStatus.CONFIRMED,
  driver_searching: BookingStatus.DRIVER_SEARCHING,
  driver_assigned: BookingStatus.DRIVER_ASSIGNED,
  driver_arriving: BookingStatus.DRIVER_ARRIVING,
  driver_arrived: BookingStatus.DRIVER_ARRIVED,
  started: BookingStatus.STARTED,
  completed: BookingStatus.COMPLETED,
  cancelled: BookingStatus.CANCELLED,
};

export const OFFER_STATUSES: BookingStatus[] = [
  BookingStatus.REQUESTED,
  BookingStatus.DRIVER_SEARCHING,
];

export const LIVE_STATUSES: BookingStatus[] = [
  BookingStatus.ASSIGNED,
  BookingStatus.DRIVER_ASSIGNED,
  BookingStatus.DRIVER_ARRIVING,
  BookingStatus.DRIVER_ARRIVED,
  BookingStatus.ONGOING,
  BookingStatus.STARTED,
];

export function toLifecycle(status: string): Lifecycle {
  return FROM_DB[status] ?? 'pending';
}

export function toLegacyStatus(lifecycle: Lifecycle): BookingStatus {
  return TO_LEGACY[lifecycle];
}

export function toDbStatus(lifecycle: Lifecycle): BookingStatus {
  return TO_DB[lifecycle];
}

export function isOpenOffer(status: string, driverId: bigint | null) {
  return OFFER_STATUSES.includes(status as BookingStatus) && driverId == null;
}

const TRANSITIONS: Record<Lifecycle, Partial<Record<LifecycleAction, Lifecycle>>> = {
  draft: { confirm: 'pending', search: 'driver_searching', cancel: 'cancelled' },
  pending: { confirm: 'confirmed', search: 'driver_searching', cancel: 'cancelled' },
  confirmed: { search: 'driver_searching', cancel: 'cancelled' },
  driver_searching: { cancel: 'cancelled' },
  driver_assigned: { arriving: 'driver_arriving', arrived: 'driver_arrived', cancel: 'cancelled' },
  driver_arriving: { arrived: 'driver_arrived', cancel: 'cancelled' },
  driver_arrived: { start: 'started', cancel: 'cancelled' },
  started: { complete: 'completed', cancel: 'cancelled' },
  completed: {},
  cancelled: {},
};

export function nextLifecycle(status: string, action: LifecycleAction): Lifecycle {
  const current = toLifecycle(status);
  const next = TRANSITIONS[current][action];
  if (!next) {
    throw new Error(`Cannot ${action} a booking that is ${current}`);
  }
  return next;
}

export function allowedActions(status: string, role: UserRole): LifecycleAction[] {
  const current = toLifecycle(status);
  const actions = Object.keys(TRANSITIONS[current]) as LifecycleAction[];
  if (role === UserRole.DRIVER) {
    return actions.filter((action) =>
      ['arriving', 'arrived', 'start', 'complete', 'cancel'].includes(action),
    );
  }
  if (role === UserRole.CUSTOMER || role === UserRole.CORPORATE) {
    return actions.filter((action) =>
      ['confirm', 'search', 'cancel'].includes(action),
    );
  }
  return actions;
}

export const LIFECYCLE_LABELS: Record<Lifecycle, string> = {
  draft: 'Draft',
  pending: 'Pending',
  confirmed: 'Confirmed',
  driver_searching: 'Finding driver',
  driver_assigned: 'Driver assigned',
  driver_arriving: 'Driver arriving',
  driver_arrived: 'Driver arrived',
  started: 'Trip started',
  completed: 'Completed',
  cancelled: 'Cancelled',
};

/** Management console trip path. Cancelled is a separate branch. */
export const ADMIN_TRIP_STEPS = [
  { key: 'requested', label: 'Requested', from: 0 },
  { key: 'assigned', label: 'Assigned', from: 1 },
  { key: 'accepted', label: 'Accepted', from: 1 },
  { key: 'arrived', label: 'Arrived', from: 3 },
  { key: 'otp', label: 'OTP Verified', from: 4 },
  { key: 'started', label: 'Started', from: 4 },
  { key: 'progress', label: 'In Progress', from: 4 },
  { key: 'completed', label: 'Completed', from: 5 },
] as const;

const ADMIN_STAGE: Record<Lifecycle, number> = {
  draft: 0,
  pending: 0,
  confirmed: 0,
  driver_searching: 0,
  driver_assigned: 1,
  driver_arriving: 2,
  driver_arrived: 3,
  started: 4,
  completed: 5,
  cancelled: -1,
};

export function adminTripTrack(status: string) {
  const lifecycle = toLifecycle(status);
  const stage = ADMIN_STAGE[lifecycle] ?? 0;
  const cancelled = lifecycle === 'cancelled';
  return {
    status,
    lifecycle,
    label: cancelled ? 'Cancelled' : LIFECYCLE_LABELS[lifecycle],
    cancelled,
    path: cancelled
      ? ['Requested', 'Cancelled']
      : ADMIN_TRIP_STEPS.map((step) => step.label),
    steps: ADMIN_TRIP_STEPS.map((step) => ({
      key: step.key,
      label: step.label,
      done: !cancelled && stage > step.from,
      active: !cancelled && stage === step.from,
    })),
  };
}
