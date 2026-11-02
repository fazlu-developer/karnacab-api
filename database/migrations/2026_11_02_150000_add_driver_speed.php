<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'last_speed')) {
            Schema::table('users', function (Blueprint $table) {
                $table->decimal('last_speed', 8, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'last_speed')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('last_speed');
            });
        }
    }
};
