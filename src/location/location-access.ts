import { BookingStatus, UserRole } from '@prisma/client';
import { Actor, canAccessDistrict, canReadVehicle, VehicleRecord } from '../access/territory';
import { LIVE_STATUSES } from '../ride-engine/booking-lifecycle';

export const LIVE_FLEET_MAP_ROLES: UserRole[] = [
  UserRole.ADMIN,
  UserRole.SUPER_ADMIN,
  UserRole.STATE_HEAD,
  UserRole.DISTRICT_HEAD,
  UserRole.FLEET_OWNER,
];

export function canUseLiveFleetMap(actor: Pick<Actor, 'role' | 'unrestricted'>): boolean {
  return actor.unrestricted || LIVE_FLEET_MAP_ROLES.includes(actor.role);
}

export function customerMaySeeDriverLocation(input: {
  actor: Pick<Actor, 'role' | 'userId'>;
  customerId: bigint;
  driverId: bigint | null;
  status: string;
}) {
  if (input.actor.role !== UserRole.CUSTOMER && input.actor.role !== UserRole.CORPORATE) {
    return false;
  }
  if (input.customerId !== input.actor.userId || input.driverId == null) {
    return false;
  }
  return LIVE_STATUSES.includes(input.status as BookingStatus);
}

export function opsMaySeeVehicleLocation(
  actor: Actor,
  vehicle: {
    districtId: number;
    districtStateId?: number | null;
    driverId: bigint | null;
    fleetOwnerId: bigint | null;
  },
) {
  if (actor.role === UserRole.CUSTOMER || actor.role === UserRole.CORPORATE) {
    return false;
  }
  return canReadVehicle(actor, vehicle);
}

export function districtSeesOnlyOwn(actor: Actor, vehicleDistrictId: number) {
  if (actor.role !== UserRole.DISTRICT_HEAD && actor.role !== UserRole.FRANCHISE) {
    return true;
  }
  return canAccessDistrict(actor, vehicleDistrictId, actor.stateId);
}

/** Drop any row the actor is not allowed to see. Client district/state/fleet ids are never applied. */
export function filterFleetMapVehicles<T extends VehicleRecord>(actor: Actor, rows: T[]): T[] {
  return rows.filter(
    (row) => opsMaySeeVehicleLocation(actor, row) && districtSeesOnlyOwn(actor, row.districtId),
  );
}
