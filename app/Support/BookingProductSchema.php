<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BookingProductSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('bookings') || ! Schema::hasColumn('bookings', 'product')) {
            return;
        }
        try {
            $col = DB::selectOne("SHOW COLUMNS FROM bookings LIKE 'product'");
            $type = strtolower((string) ($col->Type ?? ''));
            if (str_contains($type, 'enum') || (preg_match('/varchar\((\d+)\)/', $type, $m) && (int) $m[1] < 24)) {
                DB::statement("ALTER TABLE bookings MODIFY product VARCHAR(40) NOT NULL DEFAULT 'LOCAL_CAB'");
            }
        } catch (\Throwable) {
        }
    }
}
