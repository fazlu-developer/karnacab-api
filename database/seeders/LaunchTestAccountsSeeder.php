<?php

namespace Database\Seeders;

use App\Support\BookingProductSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class LaunchTestAccountsSeeder extends Seeder
{
    private const CUSTOMER_PHONE = '7428059960';

    private const DRIVER_PHONE = '7065876175';

    private const CUSTOMER_WALLET_RUPEES = 10000;

    private const DRIVER_WALLET_RUPEES = 50000;

    private const TEST_LAT = 28.7116;

    private const TEST_LNG = 77.2703;

    public function run(): void
    {
        BookingProductSchema::ensure();
        $now = now();
        $password = Hash::make('ChangeMe@123');
        $delhi = (int) (DB::table('states')->where('code', 'DL')->value('id') ?: 2);
        $newDelhi = (int) (
            DB::table('districts')->where('name', 'New Delhi')->value('id')
            ?: DB::table('districts')->where('name', 'like', '%New Delhi%')->value('id')
            ?: DB::table('districts')->where('name', 'North East Delhi')->value('id')
            ?: 45
        );

        $customerId = $this->upsertUser([
            'role' => 'CUSTOMER',
            'status' => 'ACTIVE',
            'name' => 'Fazlu',
            'email' => self::CUSTOMER_PHONE.'@otp.karnacab.local',
            'phone' => self::CUSTOMER_PHONE,
            'password_hash' => $password,
            'state_id' => $delhi,
            'district_id' => $newDelhi,
            'last_lat' => self::TEST_LAT,
            'last_lng' => self::TEST_LNG,
            'last_address' => 'Nehru Vihar, Old Mustafabad, Delhi 110090',
            'gender' => 'MALE',
            'profile_completed_at' => $now,
            'location_updated_at' => $now,
        ]);
        $this->setWallet($customerId, 'CUSTOMER', self::CUSTOMER_WALLET_RUPEES * 100);

        $driverUserId = $this->upsertUser([
            'role' => 'DRIVER',
            'status' => 'ACTIVE',
            'name' => 'KarnaCab Driver',
            'email' => self::DRIVER_PHONE.'@otp.karnacab.local',
            'phone' => self::DRIVER_PHONE,
            'password_hash' => $password,
            'state_id' => $delhi,
            'district_id' => $newDelhi,
            'last_lat' => self::TEST_LAT,
            'last_lng' => self::TEST_LNG,
            'last_address' => 'Nehru Vihar, Old Mustafabad, Delhi 110090',
            'gender' => 'MALE',
            'profile_completed_at' => $now,
            'location_updated_at' => $now,
        ]);
        $this->setWallet($driverUserId, 'DRIVER', self::DRIVER_WALLET_RUPEES * 100);
        $driverId = $this->upsertDriver($driverUserId, $delhi, $newDelhi, $now);
        $this->upsertVehicle($driverId, $delhi, $newDelhi, $now);

        $this->command?->info('Customer '.self::CUSTOMER_PHONE.' wallet ₹'.self::CUSTOMER_WALLET_RUPEES);
        $this->command?->info('Driver '.self::DRIVER_PHONE.' wallet ₹'.self::DRIVER_WALLET_RUPEES.' (OTP 123456)');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertUser(array $row): int
    {
        $now = now();
        $existing = DB::table('users')->where('phone', $row['phone'])->first();
        $payload = array_merge(['updated_at' => $now], $row);
        $payload = array_filter($payload, fn ($key) => Schema::hasColumn('users', $key), ARRAY_FILTER_USE_KEY);
        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update($payload);

            return (int) $existing->id;
        }
        $payload['created_at'] = $now;

        return (int) DB::table('users')->insertGetId($payload);
    }

    private function setWallet(int $userId, string $type, int $paise): void
    {
        if (! Schema::hasTable('wallets')) {
            return;
        }
        $now = now();
        $q = DB::table('wallets')->where('owner_user_id', $userId);
        if (Schema::hasColumn('wallets', 'owner_type')) {
            $q->where('owner_type', $type);
        }
        $existing = $q->first();
        $payload = array_filter([
            'owner_user_id' => $userId,
            'owner_type' => $type,
            'balance_paise' => $paise,
            'updated_at' => $now,
        ], fn ($key) => Schema::hasColumn('wallets', $key), ARRAY_FILTER_USE_KEY);
        if ($existing) {
            DB::table('wallets')->where('id', $existing->id)->update($payload);

            return;
        }
        $payload['created_at'] = $now;
        DB::table('wallets')->insert($payload);
    }

    private function upsertDriver(int $userId, int $stateId, int $districtId, $now): int
    {
        $existing = DB::table('drivers')->where('user_id', $userId)->first();
        $payload = array_filter([
            'user_id' => $userId,
            'license_no' => 'DL-KARNA-01',
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
            'state_id' => $stateId,
            'district_id' => $districtId,
            'updated_at' => $now,
        ], fn ($key) => Schema::hasColumn('drivers', $key), ARRAY_FILTER_USE_KEY);
        if ($existing) {
            DB::table('drivers')->where('id', $existing->id)->update($payload);

            return (int) $existing->id;
        }
        $payload['created_at'] = $now;

        return (int) DB::table('drivers')->insertGetId($payload);
    }

    private function upsertVehicle(int $driverId, int $stateId, int $districtId, $now): void
    {
        if (! Schema::hasTable('vehicles')) {
            return;
        }
        $existing = DB::table('vehicles')->where('driver_id', $driverId)->orWhere('individual_driver_id', $driverId)->first();
        $payload = array_filter([
            'district_id' => $districtId,
            'state_id' => $stateId,
            'driver_id' => $driverId,
            'individual_driver_id' => $driverId,
            'category' => 'SEDAN',
            'registration_no' => 'DL1CKARNA1',
            'status' => 'ACTIVE',
            'brand' => 'Maruti',
            'model' => 'Dzire',
            'year' => 2022,
            'color' => 'White',
            'fuel' => 'PETROL',
            'last_lat' => self::TEST_LAT,
            'last_lng' => self::TEST_LNG,
            'last_fix_at' => $now,
            'updated_at' => $now,
        ], fn ($key) => Schema::hasColumn('vehicles', $key), ARRAY_FILTER_USE_KEY);
        if ($existing) {
            DB::table('vehicles')->where('id', $existing->id)->update($payload);

            return;
        }
        $payload['created_at'] = $now;
        DB::table('vehicles')->insert($payload);
    }
}
