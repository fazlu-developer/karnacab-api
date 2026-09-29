<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parcel_fare_rules')) {
            return;
        }

        DB::statement("ALTER TABLE parcel_fare_rules MODIFY category ENUM('BIKE','AUTO','E_RICKSHAW','MINI','SEDAN','SUV','TRAVELLER','TRUCK') NOT NULL");

        $now = now();
        foreach (['LOCAL', 'BIHAR'] as $lane) {
            foreach (['BIKE', 'AUTO', 'SEDAN', 'TRAVELLER', 'TRUCK'] as $category) {
                $exists = DB::table('parcel_fare_rules')->where('lane', $lane)->where('category', $category)->exists();
                if ($exists) {
                    continue;
                }
                DB::table('parcel_fare_rules')->insert([
                    'lane' => $lane,
                    'category' => $category,
                    'min_km' => 1,
                    'included_km' => 2,
                    'per_km_paise' => 1500,
                    'extra_km_paise' => 1800,
                    'per_kg_paise' => 200,
                    'min_charge_paise' => 4900,
                    'gst_percent' => 5,
                    'volumetric_divisor' => 5000,
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('parcel_fare_rules')) {
            return;
        }

        DB::table('parcel_fare_rules')->where('category', 'TRUCK')->delete();
        DB::statement("ALTER TABLE parcel_fare_rules MODIFY category ENUM('BIKE','AUTO','E_RICKSHAW','MINI','SEDAN','SUV','TRAVELLER') NOT NULL");
    }
};
