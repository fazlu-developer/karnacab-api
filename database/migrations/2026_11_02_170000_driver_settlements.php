<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('driver_settlements')) {
            return;
        }
        Schema::create('driver_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->index();
            $table->unsignedBigInteger('driver_id')->nullable()->index();
            $table->unsignedBigInteger('driver_user_id')->nullable();
            $table->unsignedInteger('payable_paise')->default(0);
            $table->unsignedInteger('paid_paise')->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->json('payouts')->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'driver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_settlements');
    }
};
