<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }
        Schema::table('bookings', function ($table) {
            if (! Schema::hasColumn('bookings', 'patient_photo_url')) {
                $table->string('patient_photo_url', 512)->nullable();
            }
            if (! Schema::hasColumn('bookings', 'is_emergency')) {
                $table->boolean('is_emergency')->default(false);
            }
        });
        $this->widenCategory('bookings');
        $this->widenCategory('vehicles');
        if (! Schema::hasTable('catalog_services')) {
            return;
        }
        $exists = DB::table('catalog_services')
            ->where('category_key', 'AMBULANCE')
            ->orWhere('slug', 'ambulance')
            ->exists();
        if ($exists) {
            return;
        }
        $now = now();
        DB::table('catalog_services')->insert(array_filter([
            'title' => 'Ambulance',
            'slug' => 'ambulance',
            'service_group' => 'RIDE',
            'category_key' => 'AMBULANCE',
            'icon_key' => Schema::hasColumn('catalog_services', 'icon_key') ? 'ambulance' : null,
            'sort_order' => 80,
            'subtitle' => 'Free emergency ambulance',
            'featured' => Schema::hasColumn('catalog_services', 'featured') ? 0 : null,
            'active' => Schema::hasColumn('catalog_services', 'active') ? 1 : null,
            'seats' => Schema::hasColumn('catalog_services', 'seats') ? 2 : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], fn ($value) => $value !== null));
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }
        Schema::table('bookings', function ($table) {
            if (Schema::hasColumn('bookings', 'is_emergency')) {
                $table->dropColumn('is_emergency');
            }
            if (Schema::hasColumn('bookings', 'patient_photo_url')) {
                $table->dropColumn('patient_photo_url');
            }
        });
    }

    private function widenCategory(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'category')) {
            return;
        }
        try {
            $col = DB::selectOne('SHOW COLUMNS FROM '.$table." LIKE 'category'");
            $type = strtolower((string) ($col->Type ?? ''));
            if (str_contains($type, 'enum')) {
                DB::statement('ALTER TABLE '.$table.' MODIFY category VARCHAR(32) NOT NULL');
            }
        } catch (\Throwable) {
        }
    }
};
