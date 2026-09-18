<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('districts')) {
            Schema::table('districts', function (Blueprint $table) {
                if (! Schema::hasColumn('districts', 'code')) {
                    $table->string('code', 32)->nullable()->after('name');
                }
                if (! Schema::hasColumn('districts', 'status')) {
                    $table->string('status', 24)->default('ACTIVE')->after('code');
                }
            });
        }

        if (Schema::hasTable('fleet_owners')) {
            Schema::table('fleet_owners', function (Blueprint $table) {
                if (! Schema::hasColumn('fleet_owners', 'state_id')) {
                    $table->unsignedInteger('state_id')->nullable()->index();
                }
                if (! Schema::hasColumn('fleet_owners', 'district_id')) {
                    $table->unsignedInteger('district_id')->nullable()->index();
                }
                if (! Schema::hasColumn('fleet_owners', 'franchise_id')) {
                    $table->unsignedBigInteger('franchise_id')->nullable()->index();
                }
                if (! Schema::hasColumn('fleet_owners', 'status')) {
                    $table->string('status', 24)->default('ACTIVE');
                }
                if (! Schema::hasColumn('fleet_owners', 'address')) {
                    $table->string('address', 255)->nullable();
                }
            });
        }

        if (Schema::hasTable('drivers')) {
            Schema::table('drivers', function (Blueprint $table) {
                if (! Schema::hasColumn('drivers', 'driver_type')) {
                    $table->string('driver_type', 32)->nullable()->index();
                }
                if (! Schema::hasColumn('drivers', 'state_id')) {
                    $table->unsignedInteger('state_id')->nullable()->index();
                }
                if (! Schema::hasColumn('drivers', 'district_id')) {
                    $table->unsignedInteger('district_id')->nullable()->index();
                }
            });
            try {
                DB::table('drivers')->whereNull('driver_type')->whereNotNull('fleet_owner_id')->update(['driver_type' => 'fleet_driver']);
                DB::table('drivers')->whereNull('driver_type')->whereNull('fleet_owner_id')->update(['driver_type' => 'individual_driver']);
            } catch (\Throwable) {
                // Backfill is best-effort on older snapshots.
            }
        }

        if (Schema::hasTable('vehicles')) {
            Schema::table('vehicles', function (Blueprint $table) {
                if (! Schema::hasColumn('vehicles', 'state_id')) {
                    $table->unsignedInteger('state_id')->nullable()->index();
                }
                if (! Schema::hasColumn('vehicles', 'individual_driver_id')) {
                    $table->unsignedBigInteger('individual_driver_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('platform_audit_events')) {
            Schema::table('platform_audit_events', function (Blueprint $table) {
                if (! Schema::hasColumn('platform_audit_events', 'old_data')) {
                    $table->json('old_data')->nullable();
                }
                if (! Schema::hasColumn('platform_audit_events', 'new_data')) {
                    $table->json('new_data')->nullable();
                }
                if (! Schema::hasColumn('platform_audit_events', 'actor_role')) {
                    $table->string('actor_role', 32)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Additive columns are left in place to avoid dropping live operational data.
    }
};
