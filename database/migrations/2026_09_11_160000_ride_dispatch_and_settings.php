<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'search_expires_at')) {
                $table->timestamp('search_expires_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('bookings', 'arrived_at')) {
                $table->timestamp('arrived_at')->nullable()->after('trip_started_at');
            }
            if (! Schema::hasColumn('bookings', 'otp_verified_at')) {
                $table->timestamp('otp_verified_at')->nullable()->after('arrived_at');
            }
            if (! Schema::hasColumn('bookings', 'actual_distance_km')) {
                $table->decimal('actual_distance_km', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('bookings', 'duration_seconds')) {
                $table->unsignedInteger('duration_seconds')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'wait_minutes')) {
                $table->unsignedInteger('wait_minutes')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'final_fare_paise')) {
                $table->bigInteger('final_fare_paise')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'commission_paise')) {
                $table->bigInteger('commission_paise')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'driver_earning_paise')) {
                $table->bigInteger('driver_earning_paise')->nullable();
            }
        });

        if (! Schema::hasTable('booking_driver_requests')) {
            Schema::create('booking_driver_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('booking_id');
                $table->unsignedBigInteger('driver_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('status')->default('OFFERED');
                $table->decimal('distance_km', 10, 3)->nullable();
                $table->timestamp('offered_at')->nullable();
                $table->timestamp('responded_at')->nullable();
                $table->timestamps();
                $table->unique(['booking_id', 'driver_id']);
                $table->index(['driver_id', 'status']);
                $table->index(['booking_id', 'status']);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'last_heading')) {
                $table->decimal('last_heading', 8, 2)->nullable();
            }
        });

        if (Schema::hasTable('system_settings')) {
            $now = now();
            foreach ([
                ['key' => 'driver_search_radius_km', 'value' => '10'],
                ['key' => 'ride_request_timeout_seconds', 'value' => '30'],
            ] as $row) {
                $exists = DB::table('system_settings')->where('key', $row['key'])->exists();
                if (! $exists) {
                    $insert = $row;
                    if (Schema::hasColumn('system_settings', 'created_at')) {
                        $insert['created_at'] = $now;
                        $insert['updated_at'] = $now;
                    }
                    DB::table('system_settings')->insert($insert);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_driver_requests');
    }
};
