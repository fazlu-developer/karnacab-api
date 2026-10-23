<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_campaigns')) {
            return;
        }
        Schema::table('ad_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('ad_campaigns', 'image_url')) {
                $table->string('image_url', 500)->nullable();
            }
            if (! Schema::hasColumn('ad_campaigns', 'placement')) {
                $table->string('placement', 40)->nullable()->default('home');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ad_campaigns')) {
            return;
        }
        Schema::table('ad_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('ad_campaigns', 'placement')) {
                $table->dropColumn('placement');
            }
        });
    }
};
