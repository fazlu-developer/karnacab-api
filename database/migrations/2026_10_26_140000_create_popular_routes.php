<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('popular_routes')) {
            return;
        }
        Schema::create('popular_routes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('from_title');
            $table->string('from_address')->nullable();
            $table->decimal('from_lat', 10, 7);
            $table->decimal('from_lng', 10, 7);
            $table->string('to_title');
            $table->string('to_address')->nullable();
            $table->decimal('to_lat', 10, 7);
            $table->decimal('to_lng', 10, 7);
            $table->unsignedInteger('distance_km')->default(0);
            $table->unsignedInteger('from_fare')->default(0);
            $table->string('service')->default('all');
            $table->string('state')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('popular_routes');
    }
};
