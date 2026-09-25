<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CorporatePlanSchema
{
    public static function ensure(?string $connection = null): void
    {
        $schema = $connection ? Schema::connection($connection) : Schema::connection();
        $db = $connection ? DB::connection($connection) : DB::connection();
        if (! $schema->hasTable('corporate_plans')) {
            $schema->create('corporate_plans', function (Blueprint $table) {
                $table->id();
                $table->string('plan_key', 40)->nullable()->index();
                $table->string('title', 120);
                $table->string('subtitle', 180)->nullable();
                $table->string('pricing_mode', 24)->default('VEHICLE');
                $table->unsignedBigInteger('price_paise')->default(0);
                $table->string('price_label', 80)->nullable();
                $table->decimal('gst_percent', 5, 2)->default(5);
                $table->text('highlights')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('status', 24)->default('PUBLISHED');
                $table->timestamps();
            });
        }
        foreach ([
            'plan_key' => fn (Blueprint $t) => $t->string('plan_key', 40)->nullable(),
            'subtitle' => fn (Blueprint $t) => $t->string('subtitle', 180)->nullable(),
            'pricing_mode' => fn (Blueprint $t) => $t->string('pricing_mode', 24)->default('VEHICLE'),
            'price_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('price_paise')->default(0),
            'price_label' => fn (Blueprint $t) => $t->string('price_label', 80)->nullable(),
            'gst_percent' => fn (Blueprint $t) => $t->decimal('gst_percent', 5, 2)->default(5),
            'highlights' => fn (Blueprint $t) => $t->text('highlights')->nullable(),
            'sort_order' => fn (Blueprint $t) => $t->unsignedInteger('sort_order')->default(0),
            'status' => fn (Blueprint $t) => $t->string('status', 24)->default('PUBLISHED'),
        ] as $name => $define) {
            if (! $schema->hasColumn('corporate_plans', $name)) {
                $schema->table('corporate_plans', $define);
            }
        }
        if (! $schema->hasTable('corporate_travel_bookings')) {
            $schema->create('corporate_travel_bookings', function (Blueprint $table) {
                $table->id();
                $table->string('public_ref', 24)->nullable()->index();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->unsignedBigInteger('corporate_account_id')->nullable();
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->string('status', 32)->nullable();
                $table->timestamps();
            });
        }
        foreach ([
            'plan_key' => fn (Blueprint $t) => $t->string('plan_key', 40)->nullable(),
            'category' => fn (Blueprint $t) => $t->string('category', 40)->nullable(),
            'trip_kind' => fn (Blueprint $t) => $t->string('trip_kind', 24)->nullable(),
            'purpose' => fn (Blueprint $t) => $t->string('purpose', 80)->nullable(),
            'service_key' => fn (Blueprint $t) => $t->string('service_key', 40)->nullable(),
            'quote_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('quote_paise')->nullable(),
            'pickup_text' => fn (Blueprint $t) => $t->string('pickup_text', 220)->nullable(),
            'drop_text' => fn (Blueprint $t) => $t->string('drop_text', 220)->nullable(),
            'pickup_lat' => fn (Blueprint $t) => $t->decimal('pickup_lat', 10, 7)->nullable(),
            'pickup_lng' => fn (Blueprint $t) => $t->decimal('pickup_lng', 10, 7)->nullable(),
            'drop_lat' => fn (Blueprint $t) => $t->decimal('drop_lat', 10, 7)->nullable(),
            'drop_lng' => fn (Blueprint $t) => $t->decimal('drop_lng', 10, 7)->nullable(),
            'travel_date' => fn (Blueprint $t) => $t->date('travel_date')->nullable(),
            'pickup_time' => fn (Blueprint $t) => $t->string('pickup_time', 8)->nullable(),
            'passengers' => fn (Blueprint $t) => $t->unsignedInteger('passengers')->nullable(),
            'passengers_json' => fn (Blueprint $t) => $t->text('passengers_json')->nullable(),
            'requirements' => fn (Blueprint $t) => $t->text('requirements')->nullable(),
            'quote_snapshot' => fn (Blueprint $t) => $t->text('quote_snapshot')->nullable(),
            'payment_method' => fn (Blueprint $t) => $t->string('payment_method', 32)->nullable(),
            'payment_status' => fn (Blueprint $t) => $t->string('payment_status', 24)->nullable(),
            'send_invoice' => fn (Blueprint $t) => $t->boolean('send_invoice')->default(true),
            'ride_booking_id' => fn (Blueprint $t) => $t->unsignedBigInteger('ride_booking_id')->nullable(),
            'contact_name' => fn (Blueprint $t) => $t->string('contact_name', 120)->nullable(),
            'contact_phone' => fn (Blueprint $t) => $t->string('contact_phone', 20)->nullable(),
        ] as $name => $define) {
            if (! $schema->hasColumn('corporate_travel_bookings', $name)) {
                $schema->table('corporate_travel_bookings', $define);
            }
        }
        if ($db->table('corporate_plans')->count() === 0) {
            $now = now();
            $db->table('corporate_plans')->insert([
                [
                    'plan_key' => 'ON_DEMAND',
                    'title' => 'On-Demand Booking',
                    'subtitle' => 'Instant booking for business travel',
                    'pricing_mode' => 'VEHICLE',
                    'price_paise' => 0,
                    'price_label' => null,
                    'gst_percent' => 5,
                    'highlights' => json_encode(['Instant booking for business travel', 'Pay per trip', 'GST invoice available']),
                    'sort_order' => 1,
                    'status' => 'PUBLISHED',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'plan_key' => 'MONTHLY',
                    'title' => 'Monthly Plan',
                    'subtitle' => 'Fixed monthly billing',
                    'pricing_mode' => 'FIXED',
                    'price_paise' => 0,
                    'price_label' => null,
                    'gst_percent' => 5,
                    'highlights' => json_encode(['Fixed monthly billing', 'Best rates for regular travel', 'Dedicated account manager']),
                    'sort_order' => 2,
                    'status' => 'PUBLISHED',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'plan_key' => 'EMPLOYEE',
                    'title' => 'Employee Transport',
                    'subtitle' => 'Daily office commute',
                    'pricing_mode' => 'QUOTE',
                    'price_paise' => 0,
                    'price_label' => 'Custom Get Quote',
                    'gst_percent' => 5,
                    'highlights' => json_encode(['Daily pickup & drop', 'Multiple stops support', 'Route management']),
                    'sort_order' => 3,
                    'status' => 'PUBLISHED',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'plan_key' => 'EVENT_BULK',
                    'title' => 'Event & Bulk Travel',
                    'subtitle' => 'Conferences, training, events',
                    'pricing_mode' => 'QUOTE',
                    'price_paise' => 0,
                    'price_label' => 'Custom Get Quote',
                    'gst_percent' => 5,
                    'highlights' => json_encode(['Conferences, training, events', 'Multiple vehicles', 'Dedicated support']),
                    'sort_order' => 4,
                    'status' => 'PUBLISHED',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }
}
