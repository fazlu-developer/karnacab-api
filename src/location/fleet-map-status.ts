export const FLEET_MAP_STATUSES = [
  'online',
  'offline',
  'on_trip',
  'on_delivery',
  'maintenance',
] as const;

export type FleetMapStatus = (typeof FLEET_MAP_STATUSES)[number];

export const FLEET_MAP_STATUS_LABELS: Record<FleetMapStatus, string> = {
  online: 'Online',
  offline: 'Offline',
  on_trip: 'On Trip',
  on_delivery: 'On Delivery',
  maintenance: 'Maintenance',
};

const MAINTENANCE_STORED = new Set(['maintenance', 'under_maintenance']);

export function resolveFleetMapStatus(input: {
  stored?: string | null;
  driverOnline?: boolean;
  driverDuty?: string | null;
  hasActiveTrip?: boolean;
  hasActiveDelivery?: boolean;
}): { status: FleetMapStatus; label: string } {
  const stored = (input.stored ?? '').toLowerCase();
  if (MAINTENANCE_STORED.has(stored) || input.driverDuty === 'maintenance') {
    return { status: 'maintenance', label: FLEET_MAP_STATUS_LABELS.maintenance };
  }
  if (stored === 'suspended' || input.driverDuty === 'suspended') {
    return { status: 'offline', label: FLEET_MAP_STATUS_LABELS.offline };
  }
  if (input.hasActiveDelivery) {
    return { status: 'on_delivery', label: FLEET_MAP_STATUS_LABELS.on_delivery };
  }
  if (input.hasActiveTrip || input.driverDuty === 'on_trip') {
    return { status: 'on_trip', label: FLEET_MAP_STATUS_LABELS.on_trip };
  }
  if (input.driverDuty === 'on_delivery') {
    return { status: 'on_delivery', label: FLEET_MAP_STATUS_LABELS.on_delivery };
  }
  if (input.driverDuty === 'online' || input.driverOnline) {
    return { status: 'online', label: FLEET_MAP_STATUS_LABELS.online };
  }
  return { status: 'offline', label: FLEET_MAP_STATUS_LABELS.offline };
}

export function emptyFleetMapCounts(): Record<FleetMapStatus, number> {
  return {
    online: 0,
    offline: 0,
    on_trip: 0,
    on_delivery: 0,
    maintenance: 0,
  };
}
