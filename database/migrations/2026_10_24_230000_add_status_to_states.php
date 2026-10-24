<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('states') || Schema::hasColumn('states', 'status')) {
            return;
        }

        Schema::table('states', function (Blueprint $table) {
            $table->string('status', 24)->default('ACTIVE')->after('name');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('states') && Schema::hasColumn('states', 'status')) {
            Schema::table('states', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
