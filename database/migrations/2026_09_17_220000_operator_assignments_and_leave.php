<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_driver_assignments')) {
            Schema::create('vehicle_driver_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('fleet_owner_id');
                $table->unsignedBigInteger('vehicle_id');
                $table->unsignedBigInteger('driver_id');
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->string('status', 24)->default('ACTIVE');
                $table->string('reason', 191)->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('unassigned_at')->nullable();
                $table->timestamps();
                $table->index(['fleet_owner_id', 'status']);
                $table->index(['vehicle_id', 'status']);
                $table->index(['driver_id', 'status']);
            });
        }

        if (! Schema::hasTable('driver_leave_requests')) {
            Schema::create('driver_leave_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('fleet_owner_id');
                $table->unsignedBigInteger('driver_id');
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->string('status', 24)->default('PENDING');
                $table->string('reason', 500)->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 500)->nullable();
                $table->timestamps();
                $table->index(['fleet_owner_id', 'status']);
                $table->index('driver_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_leave_requests');
        Schema::dropIfExists('vehicle_driver_assignments');
    }
};
