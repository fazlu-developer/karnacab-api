<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class BulkSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('bulk_rate_rules')) {
            Schema::create('bulk_rate_rules', function (Blueprint $table) {
                $table->id();
                $table->string('event_key', 40)->nullable()->index();
                $table->string('category', 40)->index();
                $table->string('trip_kind', 24)->nullable()->index();
                $table->unsignedBigInteger('per_vehicle_paise')->default(0);
                $table->unsignedBigInteger('driver_allow_paise')->default(0);
                $table->unsignedBigInteger('toll_parking_paise')->default(0);
                $table->decimal('gst_percent', 5, 2)->default(5);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('bulk_bookings')) {
            Schema::create('bulk_bookings', function (Blueprint $table) {
                $table->id();
                $table->string('public_ref', 24)->nullable()->index();
                $table->string('status', 32)->nullable();
                $table->string('event_key', 40)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->timestamps();
            });
        }
        $cols = [
            'category' => fn (Blueprint $t) => $t->string('category', 40)->nullable(),
            'trip_kind' => fn (Blueprint $t) => $t->string('trip_kind', 24)->nullable(),
            'quote_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('quote_paise')->nullable(),
            'pickup_text' => fn (Blueprint $t) => $t->string('pickup_text', 220)->nullable(),
            'drop_text' => fn (Blueprint $t) => $t->string('drop_text', 220)->nullable(),
            'pickup_lat' => fn (Blueprint $t) => $t->decimal('pickup_lat', 10, 7)->nullable(),
            'pickup_lng' => fn (Blueprint $t) => $t->decimal('pickup_lng', 10, 7)->nullable(),
            'drop_lat' => fn (Blueprint $t) => $t->decimal('drop_lat', 10, 7)->nullable(),
            'drop_lng' => fn (Blueprint $t) => $t->decimal('drop_lng', 10, 7)->nullable(),
            'event_date' => fn (Blueprint $t) => $t->date('event_date')->nullable(),
            'event_time' => fn (Blueprint $t) => $t->string('event_time', 8)->nullable(),
            'vehicle_count' => fn (Blueprint $t) => $t->unsignedInteger('vehicle_count')->nullable(),
            'passengers' => fn (Blueprint $t) => $t->unsignedInteger('passengers')->nullable(),
            'passengers_per_cab' => fn (Blueprint $t) => $t->unsignedInteger('passengers_per_cab')->nullable(),
            'requirements' => fn (Blueprint $t) => $t->text('requirements')->nullable(),
            'lines_json' => fn (Blueprint $t) => $t->text('lines_json')->nullable(),
            'quote_snapshot' => fn (Blueprint $t) => $t->text('quote_snapshot')->nullable(),
            'payment_method' => fn (Blueprint $t) => $t->string('payment_method', 32)->nullable(),
            'payment_status' => fn (Blueprint $t) => $t->string('payment_status', 24)->nullable(),
            'ride_booking_id' => fn (Blueprint $t) => $t->unsignedBigInteger('ride_booking_id')->nullable(),
            'contact_name' => fn (Blueprint $t) => $t->string('contact_name', 120)->nullable(),
            'contact_phone' => fn (Blueprint $t) => $t->string('contact_phone', 20)->nullable(),
        ];
        foreach ($cols as $name => $define) {
            if (! Schema::hasColumn('bulk_bookings', $name)) {
                Schema::table('bulk_bookings', $define);
            }
        }
    }
}
