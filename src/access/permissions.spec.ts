import { permissionsFor, hasPermission, Permission } from './permissions';

describe('permissions catalog', () => {
  it('gives customers booking and wallet read/write without admin', () => {
    const perms = permissionsFor('CUSTOMER');
    expect(perms).toContain(Permission.BookingsWrite);
    expect(hasPermission('CUSTOMER', Permission.PaymentsWrite)).toBe(true);
    expect(hasPermission('DRIVER', Permission.PaymentsWrite)).toBe(true);
  });

  it('gives drivers vehicle read', () => {
    expect(hasPermission('DRIVER', Permission.VehiclesRead)).toBe(true);
    expect(hasPermission('DRIVER', Permission.FranchiseWrite)).toBe(false);
  });

  it('gives fleet owners fleet write', () => {
    expect(hasPermission('FLEET_OWNER', Permission.FleetWrite)).toBe(true);
  });

  it('gives district and franchise heads franchise write', () => {
    expect(hasPermission('DISTRICT_HEAD', Permission.FranchiseWrite)).toBe(true);
    expect(hasPermission('FRANCHISE', Permission.FranchiseWrite)).toBe(true);
    expect(hasPermission('STATE_HEAD', Permission.BookingsRead)).toBe(true);
  });

  it('gives advertisers ads read/write only', () => {
    expect(hasPermission('ADVERTISER', Permission.AdvertisingWrite)).toBe(true);
    expect(hasPermission('ADVERTISER', Permission.BookingsWrite)).toBe(false);
    expect(hasPermission('DISTRICT_HEAD', Permission.SafetyRead)).toBe(true);
    expect(hasPermission('ADVERTISER', Permission.SafetyWrite)).toBe(false);
    expect(hasPermission('ADMIN', Permission.SafetyWrite)).toBe(true);
    expect(hasPermission('SUPER_ADMIN', Permission.AdvertisingWrite)).toBe(true);
  });

  it('returns nothing for unknown roles', () => {
    expect(permissionsFor('UNKNOWN')).toEqual([]);
  });
});
