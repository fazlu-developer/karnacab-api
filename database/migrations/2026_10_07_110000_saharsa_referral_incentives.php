<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'referral_code')) {
                    $table->string('referral_code', 16)->nullable()->unique();
                }
                if (! Schema::hasColumn('users', 'referred_by_user_id')) {
                    $table->unsignedBigInteger('referred_by_user_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('wallets') && ! Schema::hasColumn('wallets', 'promo_balance_paise')) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->unsignedInteger('promo_balance_paise')->default(0);
            });
        }

        if (! Schema::hasTable('incentives')) {
            Schema::create('incentives', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('kind', 40);
                $table->unsignedInteger('amount_paise')->default(0);
                $table->string('status', 20)->default('paid');
                $table->unsignedBigInteger('related_user_id')->nullable()->index();
                $table->unsignedBigInteger('booking_id')->nullable()->index();
                $table->string('note', 255)->nullable();
                $table->timestamps();
                $table->index(['kind', 'status']);
            });
        }

        $now = now();
        $settings = [
            'driver_welcome_bonus_rupees' => '100',
            'driver_welcome_bonus_enabled' => '1',
            'customer_first_ride_free_enabled' => '0',
            'driver_referral_bonus_rupees' => '150',
            'driver_referral_required_rides' => '10',
            'customer_joining_credit_rupees' => '50',
            'customer_referral_credit_rupees' => '50',
            'customer_promo_max_rupees' => '50',
            'customer_promo_max_fare_percent' => '50',
        ];
        if (Schema::hasTable('system_settings')) {
            foreach ($settings as $key => $value) {
                $exists = DB::table('system_settings')->where('key', $key)->exists();
                if ($exists) {
                    DB::table('system_settings')->where('key', $key)->update(['value' => $value, 'updated_at' => $now]);
                } else {
                    DB::table('system_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        //
    }
};
