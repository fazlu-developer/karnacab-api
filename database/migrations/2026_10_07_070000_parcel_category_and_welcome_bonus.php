<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parcel_shipments') && Schema::hasColumn('parcel_shipments', 'category')) {
            try {
                DB::statement('ALTER TABLE parcel_shipments MODIFY category VARCHAR(32) NULL');
            } catch (\Throwable) {
            }
        }
        if (Schema::hasTable('drivers')) {
            Schema::table('drivers', function (Blueprint $table) {
                if (! Schema::hasColumn('drivers', 'welcome_bonus_paise')) {
                    $table->unsignedInteger('welcome_bonus_paise')->nullable();
                }
                if (! Schema::hasColumn('drivers', 'welcome_bonus_at')) {
                    $table->timestamp('welcome_bonus_at')->nullable();
                }
                if (! Schema::hasColumn('drivers', 'welcome_bonus_seen_at')) {
                    $table->timestamp('welcome_bonus_seen_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        //
    }
};
