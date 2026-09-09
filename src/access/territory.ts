import { UserRole } from '@prisma/client';
import { isOpenOffer } from '../ride-engine/booking-lifecycle';

export type Actor = {
  userId: bigint;
  role: UserRole;
  status: string;
  districtId: number | null;
  stateId: number | null;
  driverId: bigint | null;
  fleetOwnerId: bigint | null;
  unrestricted: boolean;
};

export const OPERATOR_LOGIN_ROLES: UserRole[] = [
  UserRole.FLEET_OWNER,
  UserRole.DISTRICT_HEAD,
  UserRole.STATE_HEAD,
  UserRole.FRANCHISE,
  UserRole.CORPORATE,
  UserRole.ADMIN,
  UserRole.SUPER_ADMIN,
];

export function isUnrestricted(role: UserRole | string): boolean {
  return role === UserRole.ADMIN || role === UserRole.SUPER_ADMIN;
}

/** Fail closed: missing assignment means no territory. */
export function canAccessDistrict(
  actor: Pick<Actor, 'role' | 'districtId' | 'stateId' | 'unrestricted'>,
  districtId: number | null | undefined,
  districtStateId?: number | null,
): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (districtId == null) {
    return false;
  }
  if (
    actor.role === UserRole.DISTRICT_HEAD ||
    actor.role === UserRole.FRANCHISE
  ) {
    return actor.districtId != null && actor.districtId === districtId;
  }
  if (actor.role === UserRole.STATE_HEAD) {
    if (actor.stateId == null) {
      return false;
    }
    if (districtStateId != null) {
      return districtStateId === actor.stateId;
    }
    return false;
  }
  return false;
}

export function canAccessState(
  actor: Pick<Actor, 'role' | 'stateId' | 'unrestricted'>,
  stateId: number | null | undefined,
): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (actor.role !== UserRole.STATE_HEAD) {
    return false;
  }
  return actor.stateId != null && stateId != null && actor.stateId === stateId;
}

export type BookingRecord = {
  customerId: bigint;
  driverId: bigint | null;
  vehicleFleetOwnerId: bigint | null;
  districtId: number | null;
  districtStateId: number | null;
  status: string;
};

export function canReadBooking(actor: Actor, booking: BookingRecord): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (
    actor.role === UserRole.CUSTOMER ||
    actor.role === UserRole.CORPORATE
  ) {
    return booking.customerId === actor.userId;
  }
  if (actor.role === UserRole.DRIVER) {
    if (actor.driverId != null && booking.driverId === actor.driverId) {
      return true;
    }
    return isOpenOffer(booking.status, booking.driverId);
  }
  if (actor.role === UserRole.FLEET_OWNER) {
    return (
      actor.fleetOwnerId != null &&
      booking.vehicleFleetOwnerId === actor.fleetOwnerId
    );
  }
  return canAccessDistrict(actor, booking.districtId, booking.districtStateId);
}

export type VehicleRecord = {
  districtId: number;
  districtStateId?: number | null;
  driverId: bigint | null;
  fleetOwnerId: bigint | null;
};

export function canReadVehicle(actor: Actor, vehicle: VehicleRecord): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (actor.role === UserRole.DRIVER) {
    return actor.driverId != null && vehicle.driverId === actor.driverId;
  }
  if (actor.role === UserRole.FLEET_OWNER) {
    return (
      actor.fleetOwnerId != null && vehicle.fleetOwnerId === actor.fleetOwnerId
    );
  }
  return canAccessDistrict(actor, vehicle.districtId, vehicle.districtStateId ?? null);
}

export type DriverRecord = {
  id: bigint;
  userId: bigint;
  fleetOwnerId: bigint | null;
  userDistrictId: number | null;
  userStateId: number | null;
  vehicleDistrictIds: number[];
  vehicleStateIds: number[];
};

export function canReadDriver(actor: Actor, driver: DriverRecord): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (actor.role === UserRole.DRIVER) {
    return driver.userId === actor.userId;
  }
  if (actor.role === UserRole.FLEET_OWNER) {
    return (
      actor.fleetOwnerId != null && driver.fleetOwnerId === actor.fleetOwnerId
    );
  }
  if (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) {
    if (actor.districtId == null) {
      return false;
    }
    if (driver.userDistrictId === actor.districtId) {
      return true;
    }
    return driver.vehicleDistrictIds.includes(actor.districtId);
  }
  if (actor.role === UserRole.STATE_HEAD) {
    if (actor.stateId == null) {
      return false;
    }
    if (driver.userStateId === actor.stateId) {
      return true;
    }
    return driver.vehicleStateIds.includes(actor.stateId);
  }
  return false;
}

export type ParcelRecord = {
  customerId: bigint;
  customerDistrictId: number | null;
  customerStateId: number | null;
  driverId?: bigint | null;
};

export function canReadParcel(actor: Actor, parcel: ParcelRecord): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (
    actor.role === UserRole.CUSTOMER ||
    actor.role === UserRole.CORPORATE
  ) {
    return parcel.customerId === actor.userId;
  }
  if (actor.role === UserRole.DRIVER) {
    return parcel.driverId != null && parcel.driverId === actor.driverId;
  }
  if (actor.role === UserRole.STATE_HEAD) {
    return actor.stateId != null && parcel.customerStateId === actor.stateId;
  }
  return canAccessDistrict(
    actor,
    parcel.customerDistrictId,
    parcel.customerStateId,
  );
}

export type TravelBookingRecord = {
  customerId: bigint;
  customerDistrictId: number | null;
  customerStateId: number | null;
  packageDistrictId?: number | null;
};

export function canReadTravelBooking(actor: Actor, booking: TravelBookingRecord): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (actor.role === UserRole.CUSTOMER || actor.role === UserRole.CORPORATE) {
    return booking.customerId === actor.userId;
  }
  return (
    canAccessDistrict(actor, booking.customerDistrictId, booking.customerStateId) ||
    canAccessDistrict(actor, booking.packageDistrictId ?? null, booking.customerStateId)
  );
}

export type BulkRecord = {
  customerId: bigint;
  customerDistrictId: number | null;
  customerStateId: number | null;
};

export function canReadBulk(actor: Actor, booking: BulkRecord): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (actor.role === UserRole.CUSTOMER || actor.role === UserRole.CORPORATE) {
    return booking.customerId === actor.userId;
  }
  return canAccessDistrict(actor, booking.customerDistrictId, booking.customerStateId);
}

export type CorporateAccountRecord = {
  ownerUserId: bigint;
  districtId?: number | null;
};

export function canReadCorporateAccount(actor: Actor, account: CorporateAccountRecord): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (actor.role === UserRole.CUSTOMER || actor.role === UserRole.CORPORATE) {
    return account.ownerUserId === actor.userId;
  }
  return canAccessDistrict(actor, account.districtId ?? null, null);
}

export type FleetRecord = {
  userId: bigint;
  vehicleDistrictIds: number[];
  vehicleStateIds: number[];
};

export function canReadFleet(actor: Actor, fleet: FleetRecord): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (actor.role === UserRole.FLEET_OWNER) {
    return fleet.userId === actor.userId;
  }
  if (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) {
    return (
      actor.districtId != null &&
      fleet.vehicleDistrictIds.includes(actor.districtId)
    );
  }
  if (actor.role === UserRole.STATE_HEAD) {
    return actor.stateId != null && fleet.vehicleStateIds.includes(actor.stateId);
  }
  return false;
}

export type FranchiseRecord = {
  ownerUserId: bigint;
  districtId: number;
  districtStateId?: number | null;
};

export function canReadFranchise(actor: Actor, row: FranchiseRecord): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (row.ownerUserId === actor.userId) {
    return true;
  }
  if (actor.role === UserRole.STATE_HEAD) {
    return actor.stateId != null && row.districtStateId === actor.stateId;
  }
  if (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) {
    return actor.districtId != null && actor.districtId === row.districtId;
  }
  return false;
}

export type LeadRecord = {
  userId: bigint | null;
  userDistrictId?: number | null;
  userStateId?: number | null;
};

export function canReadLead(actor: Actor, lead: LeadRecord): boolean {
  if (actor.unrestricted) {
    return true;
  }
  if (lead.userId == null) {
    return false;
  }
  if (actor.role === UserRole.STATE_HEAD) {
    return actor.stateId != null && lead.userStateId === actor.stateId;
  }
  if (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) {
    return actor.districtId != null && lead.userDistrictId === actor.districtId;
  }
  return false;
}

export function denyAllWhere(): { id: { in: bigint[] } } {
  return { id: { in: [] } };
}
