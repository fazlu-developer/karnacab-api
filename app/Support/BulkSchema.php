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
            'advance_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('advance_paise')->default(0),
            'advance_paid_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('advance_paid_paise')->default(0),
            'advance_payment_ref' => fn (Blueprint $t) => $t->string('advance_payment_ref', 64)->nullable(),
            'commission_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('commission_paise')->default(0),
            'waiting_extra_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('waiting_extra_paise')->default(0),
            'waiting_paise_per_min' => fn (Blueprint $t) => $t->unsignedInteger('waiting_paise_per_min')->default(0),
            'distance_km' => fn (Blueprint $t) => $t->decimal('distance_km', 8, 2)->nullable(),
            'start_otp' => fn (Blueprint $t) => $t->string('start_otp', 8)->nullable(),
            'stops_json' => fn (Blueprint $t) => $t->text('stops_json')->nullable(),
            'invoice_no' => fn (Blueprint $t) => $t->string('invoice_no', 24)->nullable(),
            'reject_reason' => fn (Blueprint $t) => $t->string('reject_reason', 240)->nullable(),
            'confirmed_at' => fn (Blueprint $t) => $t->timestamp('confirmed_at')->nullable(),
            'assigned_at' => fn (Blueprint $t) => $t->timestamp('assigned_at')->nullable(),
        ];
        foreach ($cols as $name => $define) {
            if (! Schema::hasColumn('bulk_bookings', $name)) {
                Schema::table('bulk_bookings', $define);
            }
        }
        if (! Schema::hasTable('bulk_booking_cabs')) {
            Schema::create('bulk_booking_cabs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bulk_booking_id')->index();
                $table->unsignedInteger('cab_no')->default(1);
                $table->string('category', 40)->nullable();
                $table->unsignedBigInteger('driver_id')->nullable()->index();
                $table->unsignedBigInteger('driver_user_id')->nullable()->index();
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->string('driver_name', 120)->nullable();
                $table->string('driver_phone', 20)->nullable();
                $table->string('plate', 24)->nullable();
                $table->string('status', 24)->default('pending');
                $table->string('phase', 24)->default('idle');
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->unsignedInteger('stop_index')->default(0);
                $table->unsignedBigInteger('fare_paise')->default(0);
                $table->unsignedBigInteger('commission_paise')->default(0);
                $table->unsignedInteger('wait_minutes')->default(0);
                $table->unsignedBigInteger('wait_charge_paise')->default(0);
                $table->unsignedBigInteger('collected_paise')->default(0);
                $table->timestamp('wait_started_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('outbound_completed_at')->nullable();
                $table->timestamp('return_started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }
}
