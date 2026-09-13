<?php

namespace Database\Seeders;

use App\Support\Geo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class MustafabadRideTestSeeder extends Seeder
{
    public const CUSTOMER_PHONE = '9100000001';

    /** Mustafabad, North East Delhi */
    public const LAT = 28.7113;

    public const LNG = 77.2706;

    public function run(): void
    {
        $this->cleanupPrevious();
        $now = now();
        $districtId = DB::table('districts')->where('name', 'like', '%North East%')->value('id')
            ?: DB::table('districts')->where('name', 'like', 'Delhi%')->value('id');

        $customerId = DB::table('users')->insertGetId([
            'role' => 'CUSTOMER',
            'status' => 'ACTIVE',
            'name' => 'Test Customer',
            'email' => '9100000001@mustafabad.karnacab.test',
            'phone' => self::CUSTOMER_PHONE,
            'password_hash' => Hash::make('TestPass@123'),
            'last_lat' => self::LAT,
            'last_lng' => self::LNG,
            'last_address' => 'Mustafabad, North East Delhi, Delhi, India',
            'location_updated_at' => $now,
            'profile_completed_at' => $now,
            'district_id' => $districtId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (! DB::table('wallets')->where('owner_user_id', $customerId)->exists()) {
            DB::table('wallets')->insert([
                'owner_user_id' => $customerId,
                'owner_type' => 'CUSTOMER',
                'balance_paise' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $distances = [1, 2, 3, 4, 5, 6, 7, 8, 9, 9.5, 11, 15];
        foreach ($distances as $i => $km) {
            $n = $i + 1;
            $point = Geo::offsetKm(self::LAT, self::LNG, $km, 40 + ($i * 18));
            $phone = '91000000'.str_pad((string) (10 + $n), 2, '0', STR_PAD_LEFT);
            $userId = DB::table('users')->insertGetId([
                'role' => 'DRIVER',
                'status' => 'ACTIVE',
                'name' => 'Mustafabad Bike '.$n,
                'email' => $phone.'@mustafabad.karnacab.test',
                'phone' => $phone,
                'password_hash' => Hash::make('TestPass@123'),
                'last_lat' => $point['lat'],
                'last_lng' => $point['lng'],
                'last_address' => 'Bike '.$n.' near Mustafabad ('.$km.' km)',
                'location_updated_at' => $now,
                'profile_completed_at' => $now,
                'district_id' => $districtId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $driverId = DB::table('drivers')->insertGetId([
                'user_id' => $userId,
                'license_no' => 'DL-TEST-'.$n,
                'online' => 1,
                'duty_status' => 'online',
                'rating_avg' => 4.8,
                'kyc_status' => 'approved',
                'city' => 'Delhi',
                'application_submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('vehicles')->insert([
                'driver_id' => $driverId,
                'district_id' => $districtId,
                'category' => 'BIKE',
                'registration_no' => 'DL1STEST'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                'status' => 'ACTIVE',
                'brand' => 'Honda',
                'model' => 'Activa',
                'year' => 2022,
                'color' => 'Black',
                'fuel' => 'PETROL',
                'last_lat' => $point['lat'],
                'last_lng' => $point['lng'],
                'last_fix_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (! DB::table('wallets')->where('owner_user_id', $userId)->exists()) {
                DB::table('wallets')->insert([
                    'owner_user_id' => $userId,
                    'owner_type' => 'DRIVER',
                    'balance_paise' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('system_settings')) {
            foreach ([
                'driver_search_radius_km' => '10',
                'driver_offer_radius_km' => '10',
                'ride_request_timeout_seconds' => '30',
            ] as $key => $value) {
                $exists = DB::table('system_settings')->where('key', $key)->exists();
                if ($exists) {
                    DB::table('system_settings')->where('key', $key)->update(['value' => $value, 'updated_at' => $now]);
                } else {
                    DB::table('system_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        $this->command?->info('Mustafabad test data ready. Customer OTP login: '.self::CUSTOMER_PHONE);
        $this->command?->info('Drivers 9100000011–9100000020 inside 10 km; 9100000021–22 outside.');
    }

    private function cleanupPrevious(): void
    {
        $userIds = DB::table('users')->where('email', 'like', '%@mustafabad.karnacab.test')->pluck('id');
        if ($userIds->isEmpty()) {
            return;
        }
        $driverIds = DB::table('drivers')->whereIn('user_id', $userIds)->pluck('id');
        $bookingIds = DB::table('bookings')->where(function ($q) use ($driverIds, $userIds) {
            if ($driverIds->isNotEmpty()) {
                $q->whereIn('driver_id', $driverIds);
            }
            $q->orWhereIn('customer_id', $userIds);
        })->pluck('id');
        if (Schema::hasTable('booking_driver_requests') && $bookingIds->isNotEmpty()) {
            DB::table('booking_driver_requests')->whereIn('booking_id', $bookingIds)->delete();
        }
        if ($bookingIds->isNotEmpty()) {
            if (Schema::hasTable('wallet_ledger')) {
                DB::table('wallet_ledger')->whereIn('booking_id', $bookingIds)->delete();
            }
            DB::table('bookings')->whereIn('id', $bookingIds)->delete();
        }
        if ($driverIds->isNotEmpty()) {
            DB::table('vehicles')->whereIn('driver_id', $driverIds)->delete();
            DB::table('drivers')->whereIn('id', $driverIds)->delete();
        }
        DB::table('wallets')->whereIn('owner_user_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    }
}
