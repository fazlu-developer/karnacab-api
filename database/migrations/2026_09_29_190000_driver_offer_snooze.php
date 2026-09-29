<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_driver_requests')) {
            Schema::table('booking_driver_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('booking_driver_requests', 'reject_count')) {
                    $table->unsignedTinyInteger('reject_count')->default(0);
                }
                if (! Schema::hasColumn('booking_driver_requests', 'snooze_until')) {
                    $table->timestamp('snooze_until')->nullable();
                }
            });
        }

        if (! Schema::hasTable('offer_dismissals')) {
            Schema::create('offer_dismissals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('driver_id');
                $table->string('kind', 24);
                $table->string('target_id', 64);
                $table->unsignedTinyInteger('reject_count')->default(0);
                $table->timestamp('snooze_until')->nullable();
                $table->timestamps();
                $table->unique(['driver_id', 'kind', 'target_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_dismissals');
    }
};
