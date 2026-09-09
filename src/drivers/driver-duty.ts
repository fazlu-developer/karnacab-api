import { BookingStatus } from '@prisma/client';

export const DUTY_STATUSES = [
  'offline',
  'online',
  'on_trip',
  'on_delivery',
  'busy',
  'maintenance',
  'suspended',
] as const;

export type DutyStatus = (typeof DUTY_STATUSES)[number];

export const DUTY_LABELS: Record<DutyStatus, string> = {
  offline: 'Offline',
  online: 'Online',
  on_trip: 'On Trip',
  on_delivery: 'On Delivery',
  busy: 'Busy',
  maintenance: 'Maintenance',
  suspended: 'Suspended',
};

export const ACTIVE_RIDE_STATUSES: BookingStatus[] = [
  BookingStatus.ASSIGNED,
  BookingStatus.DRIVER_ASSIGNED,
  BookingStatus.DRIVER_ARRIVING,
  BookingStatus.DRIVER_ARRIVED,
  BookingStatus.STARTED,
  BookingStatus.ONGOING,
];

export const ACTIVE_PARCEL_STATUSES = [
  'assigned',
  'picked_up',
  'in_transit',
  'destination',
  'out_for_delivery',
];

export const VEHICLE_MAINTENANCE = new Set(['maintenance', 'under_maintenance']);

export type DutyContext = {
  storedDuty: string;
  onlineFlag: boolean;
  userStatus: string;
  vehicleStatuses: string[];
  canGoOnline: boolean;
  hasActiveRide: boolean;
  hasActiveParcel: boolean;
};

export function normalizeDuty(value: string | null | undefined): DutyStatus {
  return DUTY_STATUSES.includes(value as DutyStatus) ? (value as DutyStatus) : 'offline';
}

export function resolveDutyStatus(ctx: DutyContext): DutyStatus {
  if (ctx.userStatus === 'SUSPENDED') {
    return 'suspended';
  }
  if (ctx.hasActiveRide) {
    return 'on_trip';
  }
  if (ctx.hasActiveParcel) {
    return 'on_delivery';
  }
  if (ctx.vehicleStatuses.some((status) => VEHICLE_MAINTENANCE.has(status))) {
    return 'maintenance';
  }
  if (!ctx.canGoOnline) {
    return 'offline';
  }
  const stored = normalizeDuty(ctx.storedDuty);
  if (stored === 'on_trip' || stored === 'on_delivery' || stored === 'suspended' || stored === 'maintenance') {
    return ctx.onlineFlag ? 'online' : 'offline';
  }
  return stored;
}

export function canReceiveOffers(duty: DutyStatus, canGoOnline: boolean) {
  return duty === 'online' && canGoOnline;
}

export function offerBlockReason(duty: DutyStatus, canGoOnline: boolean) {
  if (!canGoOnline) {
    return 'Required documents are missing, rejected, or expired';
  }
  switch (duty) {
    case 'offline':
      return 'Go online to receive trip requests';
    case 'on_trip':
      return 'Finish the current trip before taking another request';
    case 'on_delivery':
      return 'Finish the current delivery before taking another request';
    case 'busy':
      return 'You are marked busy';
    case 'maintenance':
      return 'Vehicle is in maintenance';
    case 'suspended':
      return 'Account is suspended';
    default:
      return null;
  }
}

export function persistFromDuty(duty: DutyStatus) {
  return {
    dutyStatus: duty,
    online: duty === 'online',
  };
}
