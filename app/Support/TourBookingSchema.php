<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class TourBookingSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('travel_bookings')) {
            Schema::create('travel_bookings', function (Blueprint $table) {
                $table->id();
                $table->string('public_ref', 40)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('package_id')->nullable();
                $table->date('travel_date')->nullable();
                $table->unsignedInteger('guests')->default(1);
                $table->string('contact_name', 120)->nullable();
                $table->string('contact_phone', 20)->nullable();
                $table->string('pickup_text', 180)->nullable();
                $table->text('notes')->nullable();
                $table->bigInteger('quote_paise')->default(0);
                $table->string('payment_status', 24)->nullable();
                $table->string('payment_method', 24)->nullable();
                $table->string('status', 32)->nullable();
                $table->timestamps();
            });
        }
        $cols = [
            'pickup_text' => fn (Blueprint $t) => $t->string('pickup_text', 180)->nullable(),
            'pickup_lat' => fn (Blueprint $t) => $t->decimal('pickup_lat', 10, 7)->nullable(),
            'pickup_lng' => fn (Blueprint $t) => $t->decimal('pickup_lng', 10, 7)->nullable(),
            'return_date' => fn (Blueprint $t) => $t->date('return_date')->nullable(),
            'passengers' => fn (Blueprint $t) => $t->text('passengers')->nullable(),
            'driver_id' => fn (Blueprint $t) => $t->unsignedBigInteger('driver_id')->nullable(),
            'vehicle_id' => fn (Blueprint $t) => $t->unsignedBigInteger('vehicle_id')->nullable(),
            'ride_booking_id' => fn (Blueprint $t) => $t->unsignedBigInteger('ride_booking_id')->nullable(),
            'ticket_no' => fn (Blueprint $t) => $t->string('ticket_no', 40)->nullable(),
            'invoice_no' => fn (Blueprint $t) => $t->string('invoice_no', 40)->nullable(),
            'start_otp' => fn (Blueprint $t) => $t->string('start_otp', 8)->nullable(),
            'advance_paid_paise' => fn (Blueprint $t) => $t->bigInteger('advance_paid_paise')->default(0),
            'advance_payment_ref' => fn (Blueprint $t) => $t->string('advance_payment_ref', 64)->nullable(),
            'platform_commission_paise' => fn (Blueprint $t) => $t->bigInteger('platform_commission_paise')->default(0),
            'driver_advance_paise' => fn (Blueprint $t) => $t->bigInteger('driver_advance_paise')->default(0),
            'collected_outside_paise' => fn (Blueprint $t) => $t->bigInteger('collected_outside_paise')->default(0),
            'reject_reason' => fn (Blueprint $t) => $t->string('reject_reason', 240)->nullable(),
            'approved_at' => fn (Blueprint $t) => $t->timestamp('approved_at')->nullable(),
            'assigned_at' => fn (Blueprint $t) => $t->timestamp('assigned_at')->nullable(),
            'started_at' => fn (Blueprint $t) => $t->timestamp('started_at')->nullable(),
            'completed_at' => fn (Blueprint $t) => $t->timestamp('completed_at')->nullable(),
        ];
        foreach ($cols as $name => $define) {
            if (! Schema::hasColumn('travel_bookings', $name)) {
                Schema::table('travel_bookings', $define);
            }
        }
    }
}
