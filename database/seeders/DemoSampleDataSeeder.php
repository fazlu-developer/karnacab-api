<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Truncates transactional tables (leaves geo/catalog/settings) and inserts one dummy user per role.
 * Uses direct table inserts only — no application services.
 */
class DemoSampleDataSeeder extends Seeder
{
    private const PASSWORD = 'ChangeMe@123';

    private const KEEP = [
        '_prisma_migrations',
        'migrations',
        'ops_migrations',
        'web_migrations',
        'states',
        'districts',
        'fare_rules',
        'bulk_rate_rules',
        'parcel_fare_rules',
        'commission_rules',
        'catalog_services',
        'cms_pages',
        'system_settings',
        'support_faqs',
        'travel_packages',
    ];

    public function run(): void
    {
        $now = now();
        $password = Hash::make(self::PASSWORD);
        $bihar = (int) (DB::table('states')->where('code', 'BR')->value('id') ?: 1);
        $delhi = (int) (DB::table('states')->where('code', 'DL')->value('id') ?: 2);
        $patna = (int) (DB::table('districts')->where('name', 'Patna')->value('id') ?: 26);
        $gaya = (int) (DB::table('districts')->where('name', 'Gaya')->value('id') ?: 11);
        $northEast = (int) (DB::table('districts')->where('name', 'North East Delhi')->value('id') ?: 45);

        $this->truncateTransactional();

        $customerId = $this->user([
            'role' => 'CUSTOMER',
            'name' => 'Demo Customer',
            'email' => 'customer@karnacab.local',
            'phone' => '9100000001',
            'password_hash' => $password,
            'state_id' => $delhi,
            'district_id' => $northEast,
            'last_lat' => 28.7113000,
            'last_lng' => 77.2706000,
            'last_address' => 'Mustafabad, North East Delhi',
            'gender' => 'MALE',
            'date_of_birth' => '1994-04-12',
            'profile_completed_at' => $now,
            'location_updated_at' => $now,
        ]);

        $indDriverUser = $this->user([
            'role' => 'DRIVER',
            'name' => 'Demo Individual Driver',
            'email' => 'driver@karnacab.local',
            'phone' => '9100000002',
            'password_hash' => $password,
            'state_id' => $delhi,
            'district_id' => $northEast,
            'last_lat' => 28.7121000,
            'last_lng' => 77.2714000,
            'last_address' => 'Near Mustafabad Metro',
            'gender' => 'MALE',
            'date_of_birth' => '1990-08-21',
            'profile_completed_at' => $now,
            'location_updated_at' => $now,
        ]);

        $fleetDriverUser = $this->user([
            'role' => 'DRIVER',
            'name' => 'Demo Fleet Driver',
            'email' => 'fleetdriver@karnacab.local',
            'phone' => '9100000003',
            'password_hash' => $password,
            'state_id' => $bihar,
            'district_id' => $patna,
            'last_lat' => 25.5941000,
            'last_lng' => 85.1376000,
            'last_address' => 'Patna Junction',
            'gender' => 'MALE',
            'date_of_birth' => '1992-01-15',
            'profile_completed_at' => $now,
            'location_updated_at' => $now,
        ]);

        $fleetOwnerUser = $this->user([
            'role' => 'FLEET_OWNER',
            'name' => 'Demo Fleet Owner',
            'email' => 'fleet@karnacab.local',
            'phone' => '9100000004',
            'password_hash' => $password,
            'state_id' => $bihar,
            'district_id' => $patna,
            'profile_completed_at' => $now,
        ]);

        $districtHeadUser = $this->user([
            'role' => 'DISTRICT_HEAD',
            'name' => 'Demo District Head',
            'email' => 'district@karnacab.local',
            'phone' => '9100000005',
            'password_hash' => $password,
            'state_id' => $bihar,
            'district_id' => $gaya,
            'profile_completed_at' => $now,
        ]);

        $stateHeadUser = $this->user([
            'role' => 'STATE_HEAD',
            'name' => 'Demo State Head',
            'email' => 'statehead@karnacab.local',
            'phone' => '9100000006',
            'password_hash' => $password,
            'state_id' => $bihar,
            'district_id' => $patna,
            'profile_completed_at' => $now,
        ]);

        $franchiseUser = $this->user([
            'role' => 'FRANCHISE',
            'name' => 'Demo Franchise',
            'email' => 'franchise@karnacab.local',
            'phone' => '9100000007',
            'password_hash' => $password,
            'state_id' => $bihar,
            'district_id' => $patna,
            'profile_completed_at' => $now,
        ]);

        $corporateUser = $this->user([
            'role' => 'CORPORATE',
            'name' => 'Demo Corporate',
            'email' => 'corporate@karnacab.local',
            'phone' => '9100000008',
            'password_hash' => $password,
            'state_id' => $bihar,
            'district_id' => $patna,
            'profile_completed_at' => $now,
        ]);

        $adsUser = $this->user([
            'role' => 'ADVERTISER',
            'name' => 'Demo Advertiser',
            'email' => 'ads@karnacab.local',
            'phone' => '9100000009',
            'password_hash' => $password,
            'state_id' => $bihar,
            'district_id' => $patna,
            'profile_completed_at' => $now,
        ]);

        $adminUser = $this->user([
            'role' => 'ADMIN',
            'name' => 'Demo Admin',
            'email' => 'admin@karnacab.local',
            'phone' => '9100000010',
            'password_hash' => $password,
            'profile_completed_at' => $now,
        ]);

        $superUser = $this->user([
            'role' => 'SUPER_ADMIN',
            'name' => 'Demo Super Admin',
            'email' => 'super@karnacab.local',
            'phone' => '9100000011',
            'password_hash' => $password,
            'profile_completed_at' => $now,
        ]);

        $franchiseId = DB::table('franchises')->insertGetId([
            'district_id' => $patna,
            'state_id' => $bihar,
            'owner_user_id' => $franchiseUser,
            'kind' => 'EXCLUSIVE_FRANCHISE',
            'status' => 'ACTIVE',
            'active_district_key' => (string) $patna,
            'trade_name' => 'Patna Exclusive Franchise',
            'contact_phone' => '9100000007',
            'kyc_status' => 'approved',
            'agreement_status' => 'signed',
            'fee_amount_paise' => 5000000,
            'commission_percent' => 8.00,
            'starts_on' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('franchises')->insert([
            'district_id' => $gaya,
            'state_id' => $bihar,
            'owner_user_id' => $districtHeadUser,
            'kind' => 'DISTRICT_HEAD',
            'status' => 'ACTIVE',
            'active_district_key' => (string) $gaya,
            'trade_name' => 'Gaya District Head',
            'contact_phone' => '9100000005',
            'kyc_status' => 'approved',
            'agreement_status' => 'signed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fleetId = DB::table('fleet_owners')->insertGetId([
            'user_id' => $fleetOwnerUser,
            'trade_name' => 'Patna Demo Fleet',
            'gstin' => '10AAAAA0000A1Z5',
            'state_id' => $bihar,
            'district_id' => $patna,
            'franchise_id' => $franchiseId,
            'status' => 'ACTIVE',
            'address' => 'Fraser Road, Patna',
            'created_at' => $now,
        ]);

        $indDriverId = DB::table('drivers')->insertGetId([
            'user_id' => $indDriverUser,
            'license_no' => 'DL-DEMO-IND-01',
            'online' => 1,
            'duty_status' => 'online',
            'parcel_enabled' => 1,
            'rating_avg' => 4.80,
            'kyc_status' => 'approved',
            'city' => 'Delhi',
            'application_submitted_at' => $now,
            'terms_accepted_at' => $now,
            'vehicle_family' => 'CAB',
            'driver_type' => 'individual_driver',
            'state_id' => $delhi,
            'district_id' => $northEast,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fleetDriverId = DB::table('drivers')->insertGetId([
            'user_id' => $fleetDriverUser,
            'fleet_owner_id' => $fleetId,
            'license_no' => 'BR-DEMO-FLT-01',
            'online' => 1,
            'duty_status' => 'online',
            'parcel_enabled' => 1,
            'rating_avg' => 4.60,
            'kyc_status' => 'approved',
            'city' => 'Patna',
            'application_submitted_at' => $now,
            'terms_accepted_at' => $now,
            'vehicle_family' => 'CAB',
            'driver_type' => 'fleet_driver',
            'state_id' => $bihar,
            'district_id' => $patna,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $indVehicleId = DB::table('vehicles')->insertGetId([
            'district_id' => $northEast,
            'state_id' => $delhi,
            'driver_id' => $indDriverId,
            'individual_driver_id' => $indDriverId,
            'category' => 'SEDAN',
            'registration_no' => 'DL1CDEMO01',
            'status' => 'ACTIVE',
            'brand' => 'Maruti',
            'model' => 'Dzire',
            'year' => 2022,
            'color' => 'White',
            'fuel' => 'PETROL',
            'last_lat' => 28.7121000,
            'last_lng' => 77.2714000,
            'last_fix_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fleetVehicleId = DB::table('vehicles')->insertGetId([
            'district_id' => $patna,
            'state_id' => $bihar,
            'fleet_owner_id' => $fleetId,
            'driver_id' => $fleetDriverId,
            'category' => 'SUV',
            'registration_no' => 'BR01DEMO01',
            'status' => 'ACTIVE',
            'brand' => 'Hyundai',
            'model' => 'Creta',
            'year' => 2023,
            'color' => 'Black',
            'fuel' => 'DIESEL',
            'last_lat' => 25.5941000,
            'last_lng' => 85.1376000,
            'last_fix_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('vehicle_driver_assignments')->insert([
            'fleet_owner_id' => $fleetId,
            'vehicle_id' => $fleetVehicleId,
            'driver_id' => $fleetDriverId,
            'assigned_by' => $fleetOwnerUser,
            'status' => 'ACTIVE',
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->wallet($customerId, 'CUSTOMER', 250000);
        $this->wallet($indDriverUser, 'DRIVER', 180000);
        $this->wallet($fleetDriverUser, 'DRIVER', 90000);
        $this->wallet($fleetOwnerUser, 'FLEET_OWNER', 500000);
        $this->wallet($franchiseUser, 'FRANCHISE', 100000);
        $this->wallet($districtHeadUser, 'DISTRICT_HEAD', 75000);
        $this->wallet($corporateUser, 'CORPORATE', 1000000);

        DB::table('corporate_accounts')->insert([
            'owner_user_id' => $corporateUser,
            'company_name' => 'Bihar Industries Corp',
            'gstin' => '10BBBBB1111B1Z5',
            'contact_name' => 'Demo Corporate',
            'contact_phone' => '9100000008',
            'contact_email' => 'corporate@karnacab.local',
            'district_id' => $patna,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ad_campaigns')->insert([
            'advertiser_user_id' => $adsUser,
            'business_name' => 'Patna Hotels Ads',
            'title' => 'Weekend Stay Offer',
            'category' => 'local_businesses',
            'campaign_type' => 'banner',
            'target_city' => 'Patna',
            'state_id' => $bihar,
            'district_id' => $patna,
            'starts_on' => $now,
            'ends_on' => $now->copy()->addDays(30),
            'budget_paise' => 2500000,
            'status' => 'active',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('coupons')->insert([
            'code' => 'DEMO10',
            'title' => 'Demo 10% off',
            'subtitle' => 'Sample coupon for local rides',
            'kind' => 'percent',
            'percent' => 10,
            'max_discount_paise' => 5000,
            'min_fare_paise' => 8000,
            'product' => 'LOCAL_CAB',
            'starts_on' => $now,
            'ends_on' => $now->copy()->addYear(),
            'active' => 1,
            'audience' => 'all',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('bookings')->insert([
            'public_ref' => 'KCDEMO01',
            'customer_id' => $customerId,
            'district_id' => $northEast,
            'product' => 'LOCAL_CAB',
            'category' => 'SEDAN',
            'status' => 'COMPLETED',
            'pickup_text' => 'Mustafabad, North East Delhi',
            'drop_text' => 'Kashmere Gate ISBT, Delhi',
            'pickup_lat' => 28.7113000,
            'pickup_lng' => 77.2706000,
            'drop_lat' => 28.6676000,
            'drop_lng' => 77.2264000,
            'distance_km' => 8.40,
            'quote_paise' => 18500,
            'final_fare_paise' => 18500,
            'commission_paise' => 1850,
            'driver_earning_paise' => 16650,
            'driver_id' => $indDriverId,
            'vehicle_id' => $indVehicleId,
            'start_otp' => '1111',
            'end_otp' => '2222',
            'passenger_name' => 'Demo Customer',
            'passenger_phone' => '9100000001',
            'trip_started_at' => $now->copy()->subHours(2),
            'trip_ended_at' => $now->copy()->subHour(),
            'created_at' => $now->copy()->subHours(3),
            'updated_at' => $now,
        ]);

        DB::table('user_notifications')->insert([
            [
                'user_id' => $customerId,
                'title' => 'Welcome to KarnaCab',
                'body' => 'Demo customer account is ready. OTP for this number is 123456.',
                'kind' => 'info',
                'created_at' => $now,
            ],
            [
                'user_id' => $indDriverUser,
                'title' => 'You are online',
                'body' => 'Demo individual driver is KYC approved and online.',
                'kind' => 'info',
                'created_at' => $now,
            ],
        ]);

        $superOps = $this->opsUser([
            'name' => 'Demo Super Admin',
            'email' => 'super@karnacab.local',
            'password' => $password,
            'role' => 'SUPER_ADMIN',
            'nest_user_id' => $superUser,
        ]);
        $this->opsUser([
            'name' => 'Demo Admin',
            'email' => 'admin@karnacab.local',
            'password' => $password,
            'role' => 'ADMIN',
            'nest_user_id' => $adminUser,
        ]);
        $managerOps = $this->opsUser([
            'name' => 'Demo Manager',
            'email' => 'manager@karnacab.local',
            'password' => $password,
            'role' => 'MANAGER',
            'state_id' => $bihar,
        ]);
        $this->opsUser([
            'name' => 'Demo State Head',
            'email' => 'statehead@karnacab.local',
            'password' => $password,
            'role' => 'STATE_HEAD',
            'nest_user_id' => $stateHeadUser,
            'state_id' => $bihar,
        ]);
        $this->opsUser([
            'name' => 'Demo District Head',
            'email' => 'district@karnacab.local',
            'password' => $password,
            'role' => 'DISTRICT_HEAD',
            'nest_user_id' => $districtHeadUser,
            'state_id' => $bihar,
            'district_id' => $gaya,
        ]);
        $this->opsUser([
            'name' => 'Demo Franchise',
            'email' => 'franchise@karnacab.local',
            'password' => $password,
            'role' => 'FRANCHISE',
            'nest_user_id' => $franchiseUser,
            'state_id' => $bihar,
            'district_id' => $patna,
        ]);
        $this->opsUser([
            'name' => 'Demo Fleet Owner',
            'email' => 'fleet@karnacab.local',
            'password' => $password,
            'role' => 'FLEET_OWNER',
            'nest_user_id' => $fleetOwnerUser,
            'state_id' => $bihar,
            'district_id' => $patna,
            'fleet_owner_id' => $fleetId,
        ]);
        $this->opsUser([
            'name' => 'Demo Corporate',
            'email' => 'corporate@karnacab.local',
            'password' => $password,
            'role' => 'CORPORATE',
            'nest_user_id' => $corporateUser,
        ]);
        $this->opsUser([
            'name' => 'Demo Advertiser',
            'email' => 'ads@karnacab.local',
            'password' => $password,
            'role' => 'ADVERTISER',
            'nest_user_id' => $adsUser,
        ]);

        foreach ([
            'dashboard.view', 'customers.view', 'drivers.view', 'bookings.view',
            'vehicles.view', 'fleet.view', 'reports.view', 'payments.view',
        ] as $ability) {
            DB::table('ops_user_permissions')->insert([
                'user_id' => $managerOps,
                'ability' => $ability,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('ops_state_head_assignments')->insert([
            'user_id' => $this->opsId('statehead@karnacab.local'),
            'state_id' => $bihar,
            'status' => 'ACTIVE',
            'assigned_by' => $superOps,
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command?->info('Demo sample data loaded. Admin password: '.self::PASSWORD);
        $this->command?->info('Customer OTP login: 9100000001 / 123456');
        $this->command?->info('Individual driver OTP: 9100000002 / 123456');
        $this->command?->info('Fleet driver OTP: 9100000003 / 123456');
        $this->command?->info('Fleet owner app: fleet@karnacab.local / '.self::PASSWORD);
        $this->command?->info('Admin panel: super@karnacab.local / '.self::PASSWORD);
        $this->seedNetworkDuplicates($now, $password, $bihar, $delhi, $patna, $gaya, $northEast, $customerId, $indDriverId, $fleetDriverId, $indVehicleId, $fleetVehicleId, $franchiseUser, $districtHeadUser);
    }

    /**
     * Extra customers, drivers, bookings and a Delhi state head so territory filters can be tested.
     */
    private function seedNetworkDuplicates(
        $now,
        string $password,
        int $bihar,
        int $delhi,
        int $patna,
        int $gaya,
        int $northEast,
        int $customerId,
        int $indDriverId,
        int $fleetDriverId,
        int $indVehicleId,
        int $fleetVehicleId,
        int $franchiseUser,
        int $districtHeadUser,
    ): void {
        $delhiHeadUser = $this->user([
            'role' => 'STATE_HEAD',
            'name' => 'Delhi State Head',
            'email' => 'delhihead@karnacab.local',
            'phone' => '9100000020',
            'password_hash' => $password,
            'state_id' => $delhi,
            'profile_completed_at' => $now,
        ]);
        $this->opsUser([
            'name' => 'Delhi State Head',
            'email' => 'delhihead@karnacab.local',
            'password' => $password,
            'role' => 'STATE_HEAD',
            'nest_user_id' => $delhiHeadUser,
            'state_id' => $delhi,
        ]);
        $superOps = $this->opsId('super@karnacab.local');
        DB::table('ops_state_head_assignments')->insert([
            'user_id' => $this->opsId('delhihead@karnacab.local'),
            'state_id' => $delhi,
            'status' => 'ACTIVE',
            'assigned_by' => $superOps,
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        for ($i = 1; $i <= 8; $i++) {
            $inDelhi = $i % 2 === 0;
            $stateId = $inDelhi ? $delhi : $bihar;
            $districtId = $inDelhi ? $northEast : ($i % 4 === 1 ? $gaya : $patna);
            $lat = $inDelhi ? 28.71 + ($i * 0.002) : 25.59 + ($i * 0.003);
            $lng = $inDelhi ? 77.27 + ($i * 0.002) : 85.13 + ($i * 0.003);
            $cust = $this->user([
                'role' => 'CUSTOMER',
                'name' => 'Test Rider '.$i,
                'email' => "rider{$i}@karnacab.local",
                'phone' => '91000001'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'password_hash' => $password,
                'state_id' => $stateId,
                'district_id' => $districtId,
                'last_lat' => $lat,
                'last_lng' => $lng,
                'last_address' => $inDelhi ? 'North East Delhi' : ($districtId === $gaya ? 'Gaya' : 'Patna'),
                'gender' => $i % 2 ? 'MALE' : 'FEMALE',
                'date_of_birth' => '199'.($i % 9).'-0'.(($i % 8) + 1).'-12',
                'profile_completed_at' => $now,
                'location_updated_at' => $now,
            ]);
            $drvUser = $this->user([
                'role' => 'DRIVER',
                'name' => 'Test Driver '.$i,
                'email' => "driver{$i}@karnacab.local",
                'phone' => '91000002'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'password_hash' => $password,
                'state_id' => $stateId,
                'district_id' => $districtId,
                'last_lat' => $lat + 0.001,
                'last_lng' => $lng + 0.001,
                'last_address' => $inDelhi ? 'Delhi stand' : 'Bihar stand',
                'gender' => 'MALE',
                'date_of_birth' => '1988-0'.(($i % 8) + 1).'-05',
                'profile_completed_at' => $now,
                'location_updated_at' => $now,
            ]);
            $drv = DB::table('drivers')->insertGetId([
                'user_id' => $drvUser,
                'license_no' => ($inDelhi ? 'DL' : 'BR').'-TEST-'.$i,
                'online' => $i <= 4 ? 1 : 0,
                'duty_status' => $i <= 4 ? 'online' : 'offline',
                'rating_avg' => 4.20 + ($i * 0.05),
                'kyc_status' => 'approved',
                'city' => $inDelhi ? 'Delhi' : ($districtId === $gaya ? 'Gaya' : 'Patna'),
                'application_submitted_at' => $now,
                'vehicle_family' => $i % 3 === 0 ? 'BIKE' : ($i % 3 === 1 ? 'AUTO' : 'CAR'),
                'driver_type' => 'individual_driver',
                'state_id' => $stateId,
                'district_id' => $districtId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $cat = $i % 3 === 0 ? 'BIKE' : ($i % 3 === 1 ? 'AUTO' : 'SEDAN');
            $veh = DB::table('vehicles')->insertGetId([
                'district_id' => $districtId,
                'state_id' => $stateId,
                'driver_id' => $drv,
                'individual_driver_id' => $drv,
                'category' => $cat,
                'registration_no' => ($inDelhi ? 'DL9C' : 'BR0'.$i).'TEST'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => 'ACTIVE',
                'brand' => $cat === 'BIKE' ? 'Honda' : ($cat === 'AUTO' ? 'Bajaj' : 'Maruti'),
                'model' => $cat === 'BIKE' ? 'Activa' : ($cat === 'AUTO' ? 'RE' : 'Swift'),
                'year' => 2020 + ($i % 5),
                'color' => 'White',
                'fuel' => 'PETROL',
                'last_lat' => $lat,
                'last_lng' => $lng,
                'last_fix_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('bookings')->insert([
                'public_ref' => 'KCTEST'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'customer_id' => $cust,
                'district_id' => $districtId,
                'product' => 'LOCAL_CAB',
                'category' => $cat,
                'status' => $i % 3 === 0 ? 'COMPLETED' : ($i % 3 === 1 ? 'REQUESTED' : 'STARTED'),
                'pickup_text' => $inDelhi ? 'Mustafabad' : 'Patna Junction',
                'drop_text' => $inDelhi ? 'Kashmere Gate' : 'Gandhi Maidan',
                'pickup_lat' => $lat,
                'pickup_lng' => $lng,
                'drop_lat' => $lat + 0.02,
                'drop_lng' => $lng + 0.02,
                'distance_km' => 4 + $i,
                'quote_paise' => 8000 + ($i * 500),
                'final_fare_paise' => $i % 3 === 0 ? 8000 + ($i * 500) : null,
                'driver_id' => $drv,
                'vehicle_id' => $veh,
                'passenger_name' => 'Test Rider '.$i,
                'passenger_phone' => '91000001'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'created_at' => $now->copy()->subHours($i),
                'updated_at' => $now,
            ]);
        }
        $this->command?->info('Delhi state head: delhihead@karnacab.local / '.self::PASSWORD);
        $this->command?->info('Extra test riders/drivers/bookings: rider1-8 and driver1-8 @karnacab.local');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function user(array $row): int
    {
        $now = now();

        return (int) DB::table('users')->insertGetId(array_merge([
            'status' => 'ACTIVE',
            'session_epoch' => 0,
            'force_logout_pending' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $row));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function opsUser(array $row): int
    {
        $now = now();

        return (int) DB::table('ops_users')->insertGetId(array_merge([
            'status' => 'ACTIVE',
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $row));
    }

    private function opsId(string $email): int
    {
        return (int) DB::table('ops_users')->where('email', $email)->value('id');
    }

    private function wallet(int $userId, string $type, int $paise): void
    {
        $now = now();
        DB::table('wallets')->insert([
            'owner_user_id' => $userId,
            'owner_type' => $type,
            'balance_paise' => $paise,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function truncateTransactional(): void
    {
        $name = 'Tables_in_'.DB::getDatabaseName();
        $tables = collect(DB::select('SHOW TABLES'))
            ->map(fn ($row) => $row->$name)
            ->reject(fn ($table) => in_array($table, self::KEEP, true))
            ->values();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
