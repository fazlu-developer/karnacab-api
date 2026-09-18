<?php

namespace App\Support;

class Permissions
{
    public static function forRole(string $role): array
    {
        $all = [
            'users.read', 'users.write', 'customers.read', 'customers.write',
            'drivers.read', 'drivers.write', 'vehicles.read', 'vehicles.write',
            'bookings.read', 'bookings.write', 'payments.read', 'payments.write',
            'wallets.read', 'wallets.write', 'parcels.read', 'parcels.write',
            'travel.read', 'travel.write', 'fleet.read', 'fleet.write',
            'franchise.read', 'franchise.write', 'corporate.read', 'corporate.write',
            'advertising.read', 'advertising.write', 'safety.read', 'safety.write',
            'platform.admin',
        ];

        return match ($role) {
            'SUPER_ADMIN', 'ADMIN' => $all,
            'CUSTOMER', 'CORPORATE' => [
                'customers.read', 'bookings.read', 'bookings.write', 'wallets.read',
                'parcels.read', 'parcels.write', 'travel.read', 'travel.write',
            ],
            'DRIVER' => ['drivers.read', 'bookings.read', 'bookings.write', 'wallets.read', 'vehicles.read'],
            'FLEET_OWNER' => [
                'fleet.dashboard.view', 'fleet.vehicle.view', 'fleet.vehicle.create', 'fleet.vehicle.update',
                'fleet.driver.view', 'fleet.driver.create', 'fleet.driver.update', 'fleet.driver.assign',
                'fleet.driver.unassign', 'fleet.driver.replace', 'fleet.leave.view', 'fleet.leave.manage',
                'fleet.booking.view', 'fleet.booking.create', 'fleet.trip.view', 'fleet.tracking.view',
                'fleet.earnings.view', 'fleet.reports.view', 'fleet.documents.view', 'fleet.notifications.view',
                'fleet.profile.update', 'fleet.support.view',
                'drivers.read', 'vehicles.read', 'bookings.read', 'payments.read', 'wallets.read',
            ],
            'ADVERTISER' => ['advertising.read', 'advertising.write'],
            default => ['users.read', 'drivers.read', 'vehicles.read', 'bookings.read', 'payments.read', 'wallets.read'],
        };
    }
}
