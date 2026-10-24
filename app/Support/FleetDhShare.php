<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-fleet share of the District Head commission. Null means the 50% default.
 */
final class FleetDhShare
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('fleet_owners')) {
            return;
        }
        if (! Schema::hasColumn('fleet_owners', 'dh_share_percent')) {
            Schema::table('fleet_owners', function ($table) {
                $table->decimal('dh_share_percent', 5, 2)->nullable();
            });
        }
        if (! Schema::hasColumn('fleet_owners', 'dh_share_paise')) {
            Schema::table('fleet_owners', function ($table) {
                $table->unsignedInteger('dh_share_paise')->nullable();
            });
        }
    }

    /**
     * @return array{percent: ?float, paise: ?int}
     */
    public static function resolve(int $fleetOwnerId): array
    {
        if ($fleetOwnerId < 1) {
            return ['percent' => null, 'paise' => null];
        }
        self::ensure();
        $row = DB::table('fleet_owners')->where('id', $fleetOwnerId)->first();
        if (! $row) {
            return ['percent' => null, 'paise' => null];
        }
        $percent = null;
        if (Schema::hasColumn('fleet_owners', 'dh_share_percent') && $row->dh_share_percent !== null && $row->dh_share_percent !== '') {
            $percent = (float) $row->dh_share_percent;
        }
        $paise = null;
        if (Schema::hasColumn('fleet_owners', 'dh_share_paise') && $row->dh_share_paise !== null && (int) $row->dh_share_paise > 0) {
            $paise = (int) $row->dh_share_paise;
        }

        return ['percent' => $percent, 'paise' => $paise];
    }
}
