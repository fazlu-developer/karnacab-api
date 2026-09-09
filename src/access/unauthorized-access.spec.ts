import { ForbiddenException } from '@nestjs/common';
import { UserRole } from '@prisma/client';
import { ScopeService } from './scope.service';
import { Actor } from './territory';

describe('unauthorized API access (service policies)', () => {
  const scopes = new ScopeService({} as never);

  const patnaHead: Actor = {
    userId: 11n,
    role: UserRole.DISTRICT_HEAD,
    status: 'ACTIVE',
    districtId: 10,
    stateId: 1,
    driverId: null,
    fleetOwnerId: null,
    unrestricted: false,
  };

  const otherBooking = {
    customerId: 50n,
    driverId: 3n,
    vehicleFleetOwnerId: 4n,
    districtId: 99,
    districtStateId: 1,
    status: 'COMPLETED',
  };

  it('rejects district head GET booking in another district', () => {
    expect(() => scopes.assertBooking(patnaHead, otherBooking)).toThrow(ForbiddenException);
  });

  it('rejects district head GET driver/vehicle/parcel/fleet/revenue row in another district', () => {
    expect(() =>
      scopes.assertDriver(patnaHead, {
        id: 3n,
        userId: 8n,
        fleetOwnerId: 4n,
        userDistrictId: 99,
        userStateId: 1,
        vehicleDistrictIds: [99],
        vehicleStateIds: [1],
      }),
    ).toThrow(ForbiddenException);
    expect(() =>
      scopes.assertVehicle(patnaHead, {
        districtId: 99,
        districtStateId: 1,
        driverId: 3n,
        fleetOwnerId: 4n,
      }),
    ).toThrow(ForbiddenException);
    expect(() =>
      scopes.assertParcel(patnaHead, {
        customerId: 50n,
        customerDistrictId: 99,
        customerStateId: 1,
      }),
    ).toThrow(ForbiddenException);
    expect(() =>
      scopes.assertBulk(patnaHead, {
        customerId: 50n,
        customerDistrictId: 99,
        customerStateId: 1,
      }),
    ).toThrow(ForbiddenException);
    expect(() =>
      scopes.assertTravelBooking(patnaHead, {
        customerId: 50n,
        customerDistrictId: 99,
        customerStateId: 1,
        packageDistrictId: 99,
      }),
    ).toThrow(ForbiddenException);
    expect(() =>
      scopes.assertFleet(patnaHead, {
        userId: 7n,
        vehicleDistrictIds: [99],
        vehicleStateIds: [1],
      }),
    ).toThrow(ForbiddenException);
  });

  it('rejects state head GET booking in another state', () => {
    const biharHead: Actor = {
      ...patnaHead,
      userId: 12n,
      role: UserRole.STATE_HEAD,
      districtId: null,
      stateId: 1,
    };
    expect(() =>
      scopes.assertBooking(biharHead, {
        ...otherBooking,
        districtId: 20,
        districtStateId: 2,
      }),
    ).toThrow(ForbiddenException);
  });

  it('rejects customer GET of another customer booking', () => {
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
    expect(() => scopes.assertBooking(customer, otherBooking)).toThrow(ForbiddenException);
  });

  it('bookingWhere for district head never includes another district id', () => {
    const where = scopes.bookingWhere(patnaHead);
    expect(where).toEqual({ districtId: 10 });
    expect(where).not.toEqual({});
  });

  it('bookingWhere for unassigned district head returns no rows', () => {
    const where = scopes.bookingWhere({ ...patnaHead, districtId: null });
    expect(where).toEqual({ id: { in: [] } });
  });

  it('limits fleet owner vehicle and driver queries to owned fleet', () => {
    const fleet: Actor = {
      userId: 20n,
      role: UserRole.FLEET_OWNER,
      status: 'ACTIVE',
      districtId: 10,
      stateId: 1,
      driverId: null,
      fleetOwnerId: 4n,
      unrestricted: false,
    };
    expect(scopes.vehicleWhere(fleet)).toEqual({ fleetOwnerId: 4n });
    expect(scopes.driverWhere(fleet)).toEqual({ fleetOwnerId: 4n });
    expect(scopes.bookingWhere(fleet)).toEqual({ vehicle: { fleetOwnerId: 4n } });
    expect(() =>
      scopes.assertVehicle(fleet, {
        districtId: 10,
        districtStateId: 1,
        driverId: 3n,
        fleetOwnerId: 99n,
      }),
    ).toThrow(ForbiddenException);
    expect(() =>
      scopes.assertDriver(fleet, {
        id: 3n,
        userId: 8n,
        fleetOwnerId: 99n,
        userDistrictId: 10,
        userStateId: 1,
        vehicleDistrictIds: [10],
        vehicleStateIds: [1],
      }),
    ).toThrow(ForbiddenException);
  });

  it('scopes live fleet map queries by assigned district or owned fleet', () => {
    expect(scopes.vehicleWhere(patnaHead)).toEqual({ districtId: 10 });
    expect(scopes.vehicleWhere({ ...patnaHead, districtId: null })).toEqual({ id: { in: [] } });
    const biharHead: Actor = {
      ...patnaHead,
      userId: 12n,
      role: UserRole.STATE_HEAD,
      districtId: null,
      stateId: 1,
    };
    expect(scopes.vehicleWhere(biharHead)).toEqual({ district: { stateId: 1 } });
    expect(scopes.bookingWhere(biharHead)).toEqual({ district: { stateId: 1 } });
    expect(scopes.districtWhere(biharHead)).toEqual({ stateId: 1 });
    expect(scopes.districtWhere({ ...biharHead, stateId: null })).toEqual({ id: { in: [] } });
    expect(() =>
      scopes.assertVehicle(biharHead, {
        districtId: 40,
        districtStateId: 2,
        driverId: 3n,
        fleetOwnerId: 4n,
      }),
    ).toThrow(ForbiddenException);
    expect(() =>
      scopes.assertDistrict(biharHead, { id: 40, stateId: 2 }),
    ).toThrow(ForbiddenException);
    expect(() => scopes.requireAssignedState({ ...biharHead, stateId: null })).toThrow(ForbiddenException);
  });

  it('locks district head queries to assigned district and rejects another district id', () => {
    expect(scopes.bookingWhere(patnaHead)).toEqual({ districtId: 10 });
    expect(scopes.vehicleWhere(patnaHead)).toEqual({ districtId: 10 });
    expect(scopes.districtWhere(patnaHead)).toEqual({ id: 10 });
    expect(() => scopes.requireAssignedDistrict({ ...patnaHead, districtId: null })).toThrow(ForbiddenException);
    expect(() =>
      scopes.assertVehicle(patnaHead, {
        districtId: 99,
        districtStateId: 1,
        driverId: 3n,
        fleetOwnerId: 4n,
      }),
    ).toThrow(ForbiddenException);
    expect(() => scopes.assertDistrict(patnaHead, { id: 99, stateId: 1 })).toThrow(ForbiddenException);
    expect(() =>
      scopes.assertBooking(patnaHead, {
        customerId: 50n,
        driverId: 3n,
        vehicleFleetOwnerId: 4n,
        districtId: 99,
        districtStateId: 1,
        status: 'COMPLETED',
      }),
    ).toThrow(ForbiddenException);
  });

  it('rejects district head franchise rows in another district and keeps franchiseWhere on assigned district', () => {
    expect(() =>
      scopes.assertFranchise(patnaHead, {
        ownerUserId: 88n,
        districtId: 99,
        districtStateId: 1,
      }),
    ).toThrow(ForbiddenException);
    expect(scopes.franchiseWhere(patnaHead)).toEqual({
      OR: [{ ownerUserId: 11n }, { districtId: 10 }],
    });
  });
});
