<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class TravelPackageSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('travel_packages')) {
            Schema::create('travel_packages', function (Blueprint $table) {
                $table->id();
                $table->string('title')->nullable();
                $table->string('name')->nullable();
                $table->string('destination')->nullable();
                $table->bigInteger('price_paise')->default(0);
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }
        $cols = [
            'category' => fn (Blueprint $t) => $t->string('category', 40)->nullable(),
            'origin' => fn (Blueprint $t) => $t->string('origin', 120)->nullable(),
            'region' => fn (Blueprint $t) => $t->string('region', 80)->nullable(),
            'duration_label' => fn (Blueprint $t) => $t->string('duration_label', 40)->nullable(),
            'duration_hours' => fn (Blueprint $t) => $t->unsignedInteger('duration_hours')->nullable(),
            'nights' => fn (Blueprint $t) => $t->unsignedInteger('nights')->nullable(),
            'km_included' => fn (Blueprint $t) => $t->unsignedInteger('km_included')->nullable(),
            'vehicle_label' => fn (Blueprint $t) => $t->string('vehicle_label', 80)->nullable(),
            'vehicle_category' => fn (Blueprint $t) => $t->string('vehicle_category', 40)->nullable(),
            'driver_label' => fn (Blueprint $t) => $t->string('driver_label', 80)->nullable(),
            'min_pax' => fn (Blueprint $t) => $t->unsignedInteger('min_pax')->default(2),
            'places' => fn (Blueprint $t) => $t->text('places')->nullable(),
            'inclusions' => fn (Blueprint $t) => $t->text('inclusions')->nullable(),
            'exclusions' => fn (Blueprint $t) => $t->text('exclusions')->nullable(),
            'highlights' => fn (Blueprint $t) => $t->text('highlights')->nullable(),
            'itinerary' => fn (Blueprint $t) => $t->text('itinerary')->nullable(),
            'available_dates' => fn (Blueprint $t) => $t->text('available_dates')->nullable(),
            'gallery' => fn (Blueprint $t) => $t->text('gallery')->nullable(),
            'image_url' => fn (Blueprint $t) => $t->string('image_url', 255)->nullable(),
            'popular' => fn (Blueprint $t) => $t->boolean('popular')->default(false),
        ];
        foreach ($cols as $name => $define) {
            if (! Schema::hasColumn('travel_packages', $name)) {
                Schema::table('travel_packages', $define);
            }
        }
        if (Schema::hasTable('travel_bookings')) {
            foreach ([
                'pickup_text' => fn (Blueprint $t) => $t->string('pickup_text', 180)->nullable(),
                'driver_id' => fn (Blueprint $t) => $t->unsignedBigInteger('driver_id')->nullable(),
                'vehicle_id' => fn (Blueprint $t) => $t->unsignedBigInteger('vehicle_id')->nullable(),
                'ride_booking_id' => fn (Blueprint $t) => $t->unsignedBigInteger('ride_booking_id')->nullable(),
            ] as $name => $define) {
                if (! Schema::hasColumn('travel_bookings', $name)) {
                    Schema::table('travel_bookings', $define);
                }
            }
        }
    }
}
