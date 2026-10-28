<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdvancePayment
{
    /** Services that can take an admin-set advance. Ambulance stays free. */
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
        'TRAVEL' => 'Tour & Travel',
        'BULK' => 'Bulk booking',
        'CORPORATE' => 'Corporate',
    ];

    public static function defaultPercents(): array
    {
        return [
            'LOCAL_CAB' => 0,
            'ONE_WAY' => 10,
            'ROUND_WAY' => 10,
            'SCHEDULE' => 10,
            'AIRPORT' => 10,
            'RAILWAY' => 10,
            'RENTAL' => 10,
            'MULTI_STOP' => 10,
            'PARCEL' => 10,
            'TRAVEL' => 30,
            'BULK' => 10,
            'CORPORATE' => 10,
        ];
    }

    public static function defaults(): array
    {
        $out = [];
        foreach (self::defaultPercents() as $key => $pct) {
            $out[$key] = $pct > 0;
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    public static function percentMap(): array
    {
        $defaults = self::defaultPercents();
        if (! Schema::hasTable('system_settings')) {
            return $defaults;
        }
        $percentsRaw = DB::table('system_settings')->where('key', 'advance_payment_percents')->value('value');
        $percents = is_string($percentsRaw) ? json_decode($percentsRaw, true) : null;
        $flagsRaw = DB::table('system_settings')->where('key', 'advance_payment_products')->value('value');
        $flags = is_string($flagsRaw) ? json_decode($flagsRaw, true) : null;
        $out = [];
        foreach ($defaults as $key => $fallback) {
            if (is_array($percents) && array_key_exists($key, $percents) && is_numeric($percents[$key])) {
                $out[$key] = max(0, min(100, (int) round((float) $percents[$key])));
                continue;
            }
            if (is_array($flags) && array_key_exists($key, $flags)) {
                $out[$key] = self::truthy($flags[$key]) ? $fallback : 0;
                continue;
            }
            $out[$key] = $fallback;
        }

        return $out;
    }

    public static function percent(string $product): int
    {
        $product = self::normalize($product);
        if ($product === 'AMBULANCE') {
            return 0;
        }

        return (int) (self::percentMap()[$product] ?? 0);
    }

    public static function paise(int $totalPaise, string $product): int
    {
        $pct = self::percent($product);
        if ($pct < 1 || $totalPaise < 1) {
            return 0;
        }

        return (int) round($totalPaise * $pct / 100);
    }

    public static function enabledMap(): array
    {
        $out = [];
        foreach (self::percentMap() as $key => $pct) {
            $out[$key] = $pct > 0;
        }

        return $out;
    }

    public static function enabled(string $product): bool
    {
        return self::percent($product) > 0;
    }

    /** Nearby scheduled products collect the advance after the driver accepts. */
    public static function payAfterCompleteUnlessScheduled(string $product): bool
    {
        $product = self::normalize($product);

        return in_array($product, [
            'ONE_WAY', 'ROUND_WAY', 'SCHEDULE', 'AIRPORT', 'RAILWAY', 'RENTAL', 'MULTI_STOP', 'PARCEL',
        ], true);
    }

    public static function normalize(string $product): string
    {
        $product = strtoupper($product);
        if ($product === 'CITY_RIDE' || $product === 'CITY') {
            return 'LOCAL_CAB';
        }
        if ($product === 'TOUR') {
            return 'TRAVEL';
        }

        return $product;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
