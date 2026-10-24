<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CorporatePlanSchema
{
    public static function ensure(?string $connection = null): void
    {
        $schema = filled($connection) ? Schema::connection($connection) : Schema::getConnection()->getSchemaBuilder();
        $db = filled($connection) ? DB::connection($connection) : DB::connection();
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
                $table->unsignedInteger('duration_days')->nullable();
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
            'duration_days' => fn (Blueprint $t) => $t->unsignedInteger('duration_days')->nullable(),
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
            'starts_on' => fn (Blueprint $t) => $t->date('starts_on')->nullable(),
            'ends_on' => fn (Blueprint $t) => $t->date('ends_on')->nullable(),
            'duration_days' => fn (Blueprint $t) => $t->unsignedInteger('duration_days')->nullable(),
            'pay10_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('pay10_paise')->default(0),
            'pay90_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('pay90_paise')->default(0),
            'paid_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('paid_paise')->default(0),
            'driver_id' => fn (Blueprint $t) => $t->unsignedBigInteger('driver_id')->nullable(),
            'driver_user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('driver_user_id')->nullable(),
            'vehicle_id' => fn (Blueprint $t) => $t->unsignedBigInteger('vehicle_id')->nullable(),
            'fleet_owner_id' => fn (Blueprint $t) => $t->unsignedBigInteger('fleet_owner_id')->nullable(),
            'fleet_owner_user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('fleet_owner_user_id')->nullable(),
            'driver_name' => fn (Blueprint $t) => $t->string('driver_name', 120)->nullable(),
            'driver_phone' => fn (Blueprint $t) => $t->string('driver_phone', 20)->nullable(),
            'plate' => fn (Blueprint $t) => $t->string('plate', 20)->nullable(),
            'state_id' => fn (Blueprint $t) => $t->unsignedBigInteger('state_id')->nullable(),
            'district_id' => fn (Blueprint $t) => $t->unsignedBigInteger('district_id')->nullable(),
            'district_head_user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('district_head_user_id')->nullable(),
            'start_otp' => fn (Blueprint $t) => $t->string('start_otp', 8)->nullable(),
            'driver_share_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('driver_share_paise')->default(0),
            'commission_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('commission_paise')->default(0),
            'commission_percent' => fn (Blueprint $t) => $t->decimal('commission_percent', 5, 2)->nullable(),
            'district_head_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('district_head_paise')->nullable(),
            'fleet_owner_paise' => fn (Blueprint $t) => $t->unsignedBigInteger('fleet_owner_paise')->nullable(),
            'shares_posted_at' => fn (Blueprint $t) => $t->timestamp('shares_posted_at')->nullable(),
            'invoice_no' => fn (Blueprint $t) => $t->string('invoice_no', 24)->nullable(),
            'approved_at' => fn (Blueprint $t) => $t->timestamp('approved_at')->nullable(),
            'paid_full_at' => fn (Blueprint $t) => $t->timestamp('paid_full_at')->nullable(),
            'assigned_at' => fn (Blueprint $t) => $t->timestamp('assigned_at')->nullable(),
            'reject_reason' => fn (Blueprint $t) => $t->string('reject_reason', 240)->nullable(),
            'renewed_from_id' => fn (Blueprint $t) => $t->unsignedBigInteger('renewed_from_id')->nullable(),
            'stops_json' => fn (Blueprint $t) => $t->text('stops_json')->nullable(),
            'distance_km' => fn (Blueprint $t) => $t->decimal('distance_km', 8, 2)->nullable(),
            'comped' => fn (Blueprint $t) => $t->boolean('comped')->default(false),
            'renew_requested_at' => fn (Blueprint $t) => $t->timestamp('renew_requested_at')->nullable(),
            'expiry_notified_at' => fn (Blueprint $t) => $t->timestamp('expiry_notified_at')->nullable(),
        ] as $name => $define) {
            if (! $schema->hasColumn('corporate_travel_bookings', $name)) {
                $schema->table('corporate_travel_bookings', $define);
            }
        }
        if (! $schema->hasTable('corporate_daily_trips')) {
            $schema->create('corporate_daily_trips', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('corporate_booking_id')->index();
                $table->date('trip_date')->index();
                $table->string('pickup_time', 8)->nullable();
                $table->string('status', 24)->default('scheduled');
                $table->string('phase', 24)->default('idle');
                $table->unsignedBigInteger('driver_id')->nullable();
                $table->unsignedInteger('stop_index')->default(0);
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('departed_at')->nullable();
                $table->timestamp('wait_started_at')->nullable();
                $table->timestamp('return_started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('skip_reason', 180)->nullable();
                $table->timestamp('reminded_at')->nullable();
                $table->timestamps();
            });
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
        $now = now();
        foreach ([
            ['DAY_7', '7 Day Corporate', 'Same driver, every day for 7 days', 7, 5],
            ['DAY_15', '15 Day Corporate', 'Same driver, every day for 15 days', 15, 6],
            ['DAY_30', '30 Day Corporate', 'Same driver, every day for 30 days', 30, 7],
        ] as [$key, $title, $subtitle, $days, $sort]) {
            $existing = $db->table('corporate_plans')->where('plan_key', $key)->first();
            $row = [
                'plan_key' => $key,
                'title' => $title,
                'subtitle' => $subtitle,
                'pricing_mode' => 'VEHICLE',
                'price_paise' => 0,
                'price_label' => $days.' days',
                'gst_percent' => 5,
                'duration_days' => $days,
                'highlights' => json_encode(['One car every day', 'Pay 10% then 90% to KarnaRide', 'Assigned driver, live tracking']),
                'sort_order' => $sort,
                'status' => 'PUBLISHED',
                'updated_at' => $now,
            ];
            if ($existing) {
                if ((int) ($existing->duration_days ?? 0) < 1) {
                    $db->table('corporate_plans')->where('id', $existing->id)->update([
                        'duration_days' => $days,
                        'updated_at' => $now,
                    ]);
                }
            } else {
                $row['created_at'] = $now;
                $db->table('corporate_plans')->insert($row);
            }
        }
    }
}
