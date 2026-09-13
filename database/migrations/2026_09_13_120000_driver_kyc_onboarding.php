<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'session_epoch')) {
                    $table->unsignedInteger('session_epoch')->default(0);
                }
                if (! Schema::hasColumn('users', 'force_logout_pending')) {
                    $table->boolean('force_logout_pending')->default(false);
                }
            });
        }

        if (Schema::hasTable('drivers')) {
            Schema::table('drivers', function (Blueprint $table) {
                if (! Schema::hasColumn('drivers', 'vehicle_family')) {
                    $table->string('vehicle_family', 16)->nullable();
                }
                if (! Schema::hasColumn('drivers', 'aadhaar_last4')) {
                    $table->string('aadhaar_last4', 4)->nullable();
                }
                if (! Schema::hasColumn('drivers', 'aadhaar_hash')) {
                    $table->string('aadhaar_hash', 64)->nullable();
                }
                if (! Schema::hasColumn('drivers', 'pan_last4')) {
                    $table->string('pan_last4', 8)->nullable();
                }
                if (! Schema::hasColumn('drivers', 'pan_hash')) {
                    $table->string('pan_hash', 64)->nullable();
                }
                if (! Schema::hasColumn('drivers', 'license_expires_at')) {
                    $table->date('license_expires_at')->nullable();
                }
            });
        }

    }

    public function down(): void
    {
        // Keep KYC columns; rollback is a no-op for live driver data.
    }
};
