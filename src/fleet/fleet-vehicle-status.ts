export const FLEET_VEHICLE_STATUSES = [
  'available',
  'online',
  'on_trip',
  'offline',
  'maintenance',
  'suspended',
] as const;

export type FleetVehicleStatus = (typeof FLEET_VEHICLE_STATUSES)[number];

export const FLEET_VEHICLE_STATUS_LABELS: Record<FleetVehicleStatus, string> = {
  available: 'Available',
  online: 'Online',
  on_trip: 'On Trip',
  offline: 'Offline',
  maintenance: 'Maintenance',
  suspended: 'Suspended',
};

export const FLEET_LOCKED_STATUSES = new Set<FleetVehicleStatus>(['maintenance', 'suspended']);

export function normalizeFleetVehicleStatus(value: string | null | undefined): FleetVehicleStatus {
  return FLEET_VEHICLE_STATUSES.includes(value as FleetVehicleStatus)
    ? (value as FleetVehicleStatus)
    : 'offline';
}

export function resolveFleetVehicleStatus(input: {
  stored: string;
  assignedDriverId?: bigint | string | null;
  driverOnline?: boolean;
  driverDuty?: string | null;
  hasActiveTrip?: boolean;
}): { status: FleetVehicleStatus; label: string; locked: boolean } {
  const stored = normalizeFleetVehicleStatus(input.stored);
  if (FLEET_LOCKED_STATUSES.has(stored)) {
    return { status: stored, label: FLEET_VEHICLE_STATUS_LABELS[stored], locked: true };
  }
  let status: FleetVehicleStatus = 'offline';
  if (input.hasActiveTrip) {
    status = 'on_trip';
  } else if (input.driverDuty === 'online' || input.driverOnline) {
    status = 'online';
  } else if (input.assignedDriverId) {
    status = 'available';
  }
  return { status, label: FLEET_VEHICLE_STATUS_LABELS[status], locked: false };
}

export const VEHICLE_DOC_TYPES = ['RC', 'INSURANCE', 'PERMIT', 'FITNESS', 'PUC'] as const;
export type VehicleDocType = (typeof VEHICLE_DOC_TYPES)[number];
export const VEHICLE_DOC_LABELS: Record<VehicleDocType, string> = {
  RC: 'Registration certificate',
  INSURANCE: 'Insurance',
  PERMIT: 'Permit',
  FITNESS: 'Fitness certificate',
  PUC: 'Pollution certificate',
};
