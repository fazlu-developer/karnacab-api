import { UserRole } from '@prisma/client';

export const Permission = {
  UsersRead: 'users.read',
  UsersWrite: 'users.write',
  CustomersRead: 'customers.read',
  CustomersWrite: 'customers.write',
  DriversRead: 'drivers.read',
  DriversWrite: 'drivers.write',
  VehiclesRead: 'vehicles.read',
  VehiclesWrite: 'vehicles.write',
  BookingsRead: 'bookings.read',
  BookingsWrite: 'bookings.write',
  PaymentsRead: 'payments.read',
  PaymentsWrite: 'payments.write',
  WalletsRead: 'wallets.read',
  WalletsWrite: 'wallets.write',
  ParcelsRead: 'parcels.read',
  ParcelsWrite: 'parcels.write',
  TravelRead: 'travel.read',
  TravelWrite: 'travel.write',
  FleetRead: 'fleet.read',
  FleetWrite: 'fleet.write',
  FranchiseRead: 'franchise.read',
  FranchiseWrite: 'franchise.write',
  CorporateRead: 'corporate.read',
  CorporateWrite: 'corporate.write',
  AdvertisingRead: 'advertising.read',
  AdvertisingWrite: 'advertising.write',
  SafetyRead: 'safety.read',
  SafetyWrite: 'safety.write',
  PlatformAdmin: 'platform.admin',
} as const;

export type PermissionCode = (typeof Permission)[keyof typeof Permission];

export const PLATFORM_DOMAINS = [
  'users',
  'customers',
  'drivers',
  'vehicles',
  'bookings',
  'payments',
  'wallets',
  'parcels',
  'travel',
  'fleet',
  'franchise',
  'corporate',
  'advertising',
  'safety',
] as const;

const ALL_PERMISSIONS = Object.values(Permission);

const OPERATOR_READ: PermissionCode[] = [
  Permission.UsersRead,
  Permission.CustomersRead,
  Permission.DriversRead,
  Permission.VehiclesRead,
  Permission.BookingsRead,
  Permission.PaymentsRead,
  Permission.WalletsRead,
  Permission.ParcelsRead,
  Permission.TravelRead,
  Permission.CorporateRead,
  Permission.FleetRead,
  Permission.FranchiseRead,
  Permission.SafetyRead,
];

export const ROLE_PERMISSIONS: Record<UserRole, PermissionCode[]> = {
  CUSTOMER: [
    Permission.CustomersRead,
    Permission.BookingsRead,
    Permission.BookingsWrite,
    Permission.WalletsRead,
    Permission.ParcelsRead,
    Permission.ParcelsWrite,
    Permission.TravelRead,
    Permission.TravelWrite,
    Permission.CorporateRead,
    Permission.CorporateWrite,
    Permission.PaymentsRead,
    Permission.PaymentsWrite,
  ],
  DRIVER: [
    Permission.DriversRead,
    Permission.VehiclesRead,
    Permission.BookingsRead,
    Permission.BookingsWrite,
    Permission.WalletsRead,
    Permission.WalletsWrite,
    Permission.PaymentsRead,
    Permission.PaymentsWrite,
    Permission.ParcelsRead,
    Permission.ParcelsWrite,
  ],
  FLEET_OWNER: [
    Permission.FleetRead,
    Permission.FleetWrite,
    Permission.DriversRead,
    Permission.DriversWrite,
    Permission.VehiclesRead,
    Permission.VehiclesWrite,
    Permission.BookingsRead,
    Permission.WalletsRead,
    Permission.PaymentsRead,
    Permission.PaymentsWrite,
  ],
  DISTRICT_HEAD: [
    ...OPERATOR_READ,
    Permission.PaymentsWrite,
    Permission.FranchiseRead,
    Permission.FranchiseWrite,
    Permission.SafetyWrite,
  ],
  STATE_HEAD: [
    ...OPERATOR_READ,
    Permission.FranchiseRead,
    Permission.FranchiseWrite,
    Permission.UsersRead,
    Permission.SafetyWrite,
  ],
  FRANCHISE: [
    ...OPERATOR_READ,
    Permission.PaymentsWrite,
    Permission.FranchiseRead,
    Permission.FranchiseWrite,
    Permission.SafetyWrite,
  ],
  CORPORATE: [
    Permission.CorporateRead,
    Permission.CorporateWrite,
    Permission.BookingsRead,
    Permission.BookingsWrite,
    Permission.WalletsRead,
    Permission.PaymentsRead,
    Permission.PaymentsWrite,
    Permission.TravelRead,
  ],
  ADVERTISER: [
    Permission.AdvertisingRead,
    Permission.AdvertisingWrite,
  ],
  ADMIN: ALL_PERMISSIONS,
  SUPER_ADMIN: ALL_PERMISSIONS,
};

export function permissionsFor(role: UserRole | string): PermissionCode[] {
  if (role in ROLE_PERMISSIONS) {
    return ROLE_PERMISSIONS[role as UserRole];
  }
  return [];
}

export function hasPermission(
  role: UserRole | string,
  permission: PermissionCode,
): boolean {
  return permissionsFor(role).includes(permission);
}
