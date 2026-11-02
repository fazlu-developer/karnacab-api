<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_sessions')) {
            Schema::create('app_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('jti', 64)->unique();
                $table->string('role', 32)->nullable();
                $table->string('device_type', 80)->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamp('logged_in_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_sessions');
    }
};
