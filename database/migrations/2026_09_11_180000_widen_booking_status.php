<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE bookings MODIFY status VARCHAR(32) NOT NULL DEFAULT 'REQUESTED'");
        if (Schema::hasTable('booking_driver_requests')) {
            DB::statement("ALTER TABLE booking_driver_requests MODIFY status VARCHAR(24) NOT NULL DEFAULT 'OFFERED'");
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE bookings MODIFY status ENUM('DRAFT','PENDING','CONFIRMED','DRIVER_SEARCHING','DRIVER_ASSIGNED','DRIVER_ARRIVING','DRIVER_ARRIVED','STARTED','COMPLETED','CANCELLED','REQUESTED','QUOTED','ASSIGNED','ONGOING') NOT NULL DEFAULT 'REQUESTED'");
    }
};
