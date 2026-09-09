import {
  canUseLiveFleetMap,
  customerMaySeeDriverLocation,
  districtSeesOnlyOwn,
  filterFleetMapVehicles,
  opsMaySeeVehicleLocation,
} from './location-access';
import { UserRole } from '@prisma/client';
import { Actor } from '../access/territory';
import { shouldPersistFix, shouldSkipPing } from './live-fix';

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

describe('live location access', () => {
  it('lets a customer see the driver only on their own live trip', () => {
    expect(
      customerMaySeeDriverLocation({
        actor: { role: UserRole.CUSTOMER, userId: 5n },
        customerId: 5n,
        driverId: 9n,
        status: 'STARTED',
      }),
    ).toBe(true);
    expect(
      customerMaySeeDriverLocation({
        actor: { role: UserRole.CUSTOMER, userId: 5n },
        customerId: 6n,
        driverId: 9n,
        status: 'STARTED',
      }),
    ).toBe(false);
    expect(
      customerMaySeeDriverLocation({
        actor: { role: UserRole.CUSTOMER, userId: 5n },
        customerId: 5n,
        driverId: 9n,
        status: 'REQUESTED',
      }),
    ).toBe(false);
  });

  it('keeps district heads inside their assigned district', () => {
    expect(districtSeesOnlyOwn(patnaHead, 10)).toBe(true);
    expect(districtSeesOnlyOwn(patnaHead, 99)).toBe(false);
    expect(
      opsMaySeeVehicleLocation(patnaHead, {
        districtId: 99,
        districtStateId: 1,
        driverId: 3n,
        fleetOwnerId: 4n,
      }),
    ).toBe(false);
  });

  it('builds the live fleet map from Vehicle.districtId, never from a client district id', () => {
    expect(canUseLiveFleetMap(patnaHead)).toBe(true);
    expect(canUseLiveFleetMap({ ...patnaHead, role: UserRole.FRANCHISE, unrestricted: false })).toBe(false);
    const rows = [
      { districtId: 10, districtStateId: 1, driverId: 3n, fleetOwnerId: 4n },
      { districtId: 99, districtStateId: 1, driverId: 8n, fleetOwnerId: 4n },
    ];
    const visible = filterFleetMapVehicles(patnaHead, rows);
    expect(visible).toHaveLength(1);
    expect(visible[0].districtId).toBe(10);
    expect(visible.some((row) => row.districtId === 99)).toBe(false);

    const clientAskedForGaya = 99;
    const stillPatnaOnly = filterFleetMapVehicles(patnaHead, rows.filter((row) => row.districtId === clientAskedForGaya));
    expect(stillPatnaOnly).toEqual([]);
  });

  it('limits fleet owners to owned fleet vehicles even in the same district', () => {
    const fleet: Actor = {
      ...patnaHead,
      userId: 20n,
      role: UserRole.FLEET_OWNER,
      fleetOwnerId: 4n,
    };
    const visible = filterFleetMapVehicles(fleet, [
      { districtId: 10, districtStateId: 1, driverId: 3n, fleetOwnerId: 4n },
      { districtId: 10, districtStateId: 1, driverId: 9n, fleetOwnerId: 99n },
    ]);
    expect(visible).toHaveLength(1);
    expect(visible[0].fleetOwnerId).toBe(4n);
  });

  it('keeps state heads inside the assigned state', () => {
    const biharHead: Actor = {
      ...patnaHead,
      userId: 12n,
      role: UserRole.STATE_HEAD,
      districtId: null,
      stateId: 1,
    };
    const visible = filterFleetMapVehicles(biharHead, [
      { districtId: 10, districtStateId: 1, driverId: 3n, fleetOwnerId: 4n },
      { districtId: 40, districtStateId: 2, driverId: 8n, fleetOwnerId: 4n },
    ]);
    expect(visible.map((row) => row.districtId)).toEqual([10]);
  });

  it('skips noisy pings and persists last known on meaningful movement', () => {
    const prev = {
      driverId: '1',
      vehicleId: null,
      bookingId: null,
      districtId: 10,
      lat: 25.5941,
      lng: 85.1376,
      heading: 0,
      speed: 0,
      tripStatus: 'online',
      recordedAt: new Date(1_000).toISOString(),
    };
    expect(shouldSkipPing(prev, { lat: 25.5941, lng: 85.1376, recordedAt: 1_400 })).toBe(true);
    expect(shouldPersistFix(prev, { lat: 25.6, lng: 85.15, recordedAt: 4_000 })).toBe(true);
  });
});
