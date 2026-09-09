import { UserRole } from '@prisma/client';
import {
  Actor,
  canAccessDistrict,
  canAccessState,
  canReadBooking,
  canReadDriver,
  canReadFleet,
  canReadParcel,
  canReadVehicle,
  canReadFranchise,
  canReadLead,
} from './territory';

const districtHead = (over: Partial<Actor> = {}): Actor => ({
  userId: 1n,
  role: UserRole.DISTRICT_HEAD,
  status: 'ACTIVE',
  districtId: 10,
  stateId: 1,
  driverId: null,
  fleetOwnerId: null,
  unrestricted: false,
  ...over,
});

const stateHead = (over: Partial<Actor> = {}): Actor => ({
  userId: 2n,
  role: UserRole.STATE_HEAD,
  status: 'ACTIVE',
  districtId: null,
  stateId: 1,
  driverId: null,
  fleetOwnerId: null,
  unrestricted: false,
  ...over,
});

describe('territory policies', () => {
  it('blocks a district head from another district booking, driver, vehicle, parcel, fleet, revenue row', () => {
    const actor = districtHead();
    const otherDistrict = 99;
    const otherState = 2;

    expect(canAccessDistrict(actor, otherDistrict, 1)).toBe(false);
    expect(
      canReadBooking(actor, {
        customerId: 9n,
        driverId: 3n,
        vehicleFleetOwnerId: 4n,
        districtId: otherDistrict,
        districtStateId: 1,
        status: 'COMPLETED',
      }),
    ).toBe(false);
    expect(
      canReadVehicle(actor, {
        districtId: otherDistrict,
        districtStateId: 1,
        driverId: 3n,
        fleetOwnerId: 4n,
      }),
    ).toBe(false);
    expect(
      canReadDriver(actor, {
        id: 3n,
        userId: 8n,
        fleetOwnerId: 4n,
        userDistrictId: otherDistrict,
        userStateId: 1,
        vehicleDistrictIds: [otherDistrict],
        vehicleStateIds: [1],
      }),
    ).toBe(false);
    expect(
      canReadParcel(actor, {
        customerId: 9n,
        customerDistrictId: otherDistrict,
        customerStateId: 1,
      }),
    ).toBe(false);
    expect(
      canReadFleet(actor, {
        userId: 7n,
        vehicleDistrictIds: [otherDistrict],
        vehicleStateIds: [1],
      }),
    ).toBe(false);
    expect(canAccessDistrict(actor, otherDistrict, otherState)).toBe(false);
  });

  it('allows a district head only inside the assigned district', () => {
    const actor = districtHead({ districtId: 10 });
    expect(canAccessDistrict(actor, 10, 1)).toBe(true);
    expect(
      canReadBooking(actor, {
        customerId: 9n,
        driverId: 3n,
        vehicleFleetOwnerId: 4n,
        districtId: 10,
        districtStateId: 1,
        status: 'COMPLETED',
      }),
    ).toBe(true);
  });

  it('denies district data when the head has no district assignment', () => {
    const actor = districtHead({ districtId: null });
    expect(canAccessDistrict(actor, 10, 1)).toBe(false);
  });

  it('blocks a state head from another state', () => {
    const actor = stateHead({ stateId: 1 });
    expect(canAccessState(actor, 2)).toBe(false);
    expect(canAccessDistrict(actor, 10, 2)).toBe(false);
    expect(
      canReadBooking(actor, {
        customerId: 9n,
        driverId: null,
        vehicleFleetOwnerId: null,
        districtId: 10,
        districtStateId: 2,
        status: 'COMPLETED',
      }),
    ).toBe(false);
    expect(
      canReadLead(actor, { userId: 9n, userDistrictId: 10, userStateId: 2 }),
    ).toBe(false);
  });

  it('allows a state head inside the assigned state', () => {
    const actor = stateHead({ stateId: 1 });
    expect(canAccessState(actor, 1)).toBe(true);
    expect(canAccessDistrict(actor, 10, 1)).toBe(true);
  });

  it('keeps customers on their own bookings and payments only', () => {
    const customer: Actor = {
      userId: 5n,
      role: UserRole.CUSTOMER,
      status: 'ACTIVE',
      districtId: 10,
      stateId: 1,
      driverId: null,
      fleetOwnerId: null,
      unrestricted: false,
    };
    expect(
      canReadBooking(customer, {
        customerId: 5n,
        driverId: null,
        vehicleFleetOwnerId: null,
        districtId: 10,
        districtStateId: 1,
        status: 'REQUESTED',
      }),
    ).toBe(true);
    expect(
      canReadBooking(customer, {
        customerId: 6n,
        driverId: null,
        vehicleFleetOwnerId: null,
        districtId: 10,
        districtStateId: 1,
        status: 'REQUESTED',
      }),
    ).toBe(false);
  });

  it('keeps drivers on own vehicle/trips', () => {
    const driver: Actor = {
      userId: 8n,
      role: UserRole.DRIVER,
      status: 'ACTIVE',
      districtId: 10,
      stateId: 1,
      driverId: 3n,
      fleetOwnerId: null,
      unrestricted: false,
    };
    expect(
      canReadVehicle(driver, {
        districtId: 99,
        driverId: 3n,
        fleetOwnerId: 1n,
      }),
    ).toBe(true);
    expect(
      canReadVehicle(driver, {
        districtId: 10,
        driverId: 99n,
        fleetOwnerId: 1n,
      }),
    ).toBe(false);
  });

  it('keeps fleet owners on own fleet only', () => {
    const fleet: Actor = {
      userId: 7n,
      role: UserRole.FLEET_OWNER,
      status: 'ACTIVE',
      districtId: null,
      stateId: null,
      driverId: null,
      fleetOwnerId: 4n,
      unrestricted: false,
    };
    expect(
      canReadFleet(fleet, { userId: 7n, vehicleDistrictIds: [10], vehicleStateIds: [1] }),
    ).toBe(true);
    expect(
      canReadVehicle(fleet, {
        districtId: 99,
        driverId: 3n,
        fleetOwnerId: 4n,
      }),
    ).toBe(true);
    expect(
      canReadVehicle(fleet, {
        districtId: 10,
        driverId: 3n,
        fleetOwnerId: 99n,
      }),
    ).toBe(false);
  });

  it('lets admin read any district', () => {
    const admin: Actor = {
      userId: 1n,
      role: UserRole.ADMIN,
      status: 'ACTIVE',
      districtId: null,
      stateId: null,
      driverId: null,
      fleetOwnerId: null,
      unrestricted: true,
    };
    expect(canAccessDistrict(admin, 99, 2)).toBe(true);
    expect(canAccessState(admin, 9)).toBe(true);
  });

  it('keeps exclusive franchise rows inside assigned district or state', () => {
    const head = districtHead();
    expect(
      canReadFranchise(head, { ownerUserId: 99n, districtId: 10, districtStateId: 1 }),
    ).toBe(true);
    expect(
      canReadFranchise(head, { ownerUserId: 99n, districtId: 99, districtStateId: 1 }),
    ).toBe(false);
    expect(
      canReadFranchise(stateHead(), { ownerUserId: 99n, districtId: 40, districtStateId: 2 }),
    ).toBe(false);
  });
});
