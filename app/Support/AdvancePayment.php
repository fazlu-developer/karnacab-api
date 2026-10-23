<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdvancePayment
{
    /** Services that can take a 10% advance. City ride stays off until an admin turns it on. */
    public const PRODUCTS = [
        'LOCAL_CAB' => 'City ride',
        'ONE_WAY' => 'One way',
        'ROUND_WAY' => 'Round way',
        'SCHEDULE' => 'Schedule ride',
        'AIRPORT' => 'Airport transfer',
        'RAILWAY' => 'Railway transfer',
        'RENTAL' => 'Cab rental',
        'MULTI_STOP' => 'Multi stop',
        'PARCEL' => 'Parcel',
    ];

    public static function defaults(): array
    {
        return [
            'LOCAL_CAB' => false,
            'ONE_WAY' => true,
            'ROUND_WAY' => true,
            'SCHEDULE' => true,
            'AIRPORT' => true,
            'RAILWAY' => true,
            'RENTAL' => true,
            'MULTI_STOP' => true,
            'PARCEL' => true,
        ];
    }

    public static function enabledMap(): array
    {
        $defaults = self::defaults();
        if (! Schema::hasTable('system_settings')) {
            return $defaults;
        }
        $raw = DB::table('system_settings')->where('key', 'advance_payment_products')->value('value');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded)) {
            return $defaults;
        }
        $out = [];
        foreach ($defaults as $key => $fallback) {
            $out[$key] = array_key_exists($key, $decoded) ? self::truthy($decoded[$key]) : $fallback;
        }

        return $out;
    }

    public static function enabled(string $product): bool
    {
        $product = strtoupper($product);
        if ($product === 'CITY_RIDE' || $product === 'CITY') {
            $product = 'LOCAL_CAB';
        }
        if ($product === 'LOCAL_CAB') {
            return false;
        }
        if (! array_key_exists($product, self::PRODUCTS)) {
            return false;
        }

        return (bool) (self::enabledMap()[$product] ?? false);
    }

    /** No 10% at create. A future date collects 10% after the driver accepts. */
    public static function payAfterCompleteUnlessScheduled(string $product): bool
    {
        $product = strtoupper($product);

        return in_array($product, [
            'ONE_WAY', 'ROUND_WAY', 'SCHEDULE', 'AIRPORT', 'RAILWAY', 'RENTAL', 'MULTI_STOP', 'PARCEL',
        ], true);
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
