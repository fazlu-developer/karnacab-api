<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class DistrictLocator
{
    /**
     * PIN and locality first. Google calls every NCT address "New Delhi", which is a city name, not Central/New Delhi district.
     */
    public static function resolve(?float $lat, ?float $lng, ?string $address, ?int $stateId = null): ?int
    {
        return self::matchLocality($address, $stateId)
            ?? self::matchPin($address, $stateId)
            ?? self::nearestHq($lat, $lng, $stateId)
            ?? self::matchName($address, $stateId);
    }

    public static function matchName(?string $address, ?int $stateId = null): ?int
    {
        $hay = strtolower(trim((string) $address));
        if ($hay === '') {
            return null;
        }
        try {
            $q = self::districts()->orderByRaw('CHAR_LENGTH(name) desc');
            if ($stateId) {
                $q->where('state_id', $stateId);
            }
            foreach ($q->get(['id', 'name']) as $row) {
                $name = strtolower(trim((string) $row->name));
                if (strlen($name) < 4 || in_array($name, self::genericCityNames(), true)) {
                    continue;
                }
                if ($name === $hay || preg_match('/\b'.preg_quote($name, '/').'\b/u', $hay)) {
                    return (int) $row->id;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public static function matchLocality(?string $address, ?int $stateId = null): ?int
    {
        $hay = strtolower((string) $address);
        if ($hay === '') {
            return null;
        }
        foreach (self::localities() as $needle => $districtName) {
            if (str_contains($hay, $needle)) {
                return self::idByName($districtName, $stateId);
            }
        }

        return null;
    }

    public static function matchPin(?string $address, ?int $stateId = null): ?int
    {
        if (! preg_match('/\b(\d{6})\b/', (string) $address, $m)) {
            return null;
        }
        $pin = $m[1];
        foreach (self::pinPrefixes() as $prefix => $districtName) {
            if (str_starts_with($pin, (string) $prefix)) {
                return self::idByName($districtName, $stateId);
            }
        }

        return null;
    }

    public static function nearestHq(?float $lat, ?float $lng, ?int $stateId = null): ?int
    {
        if ($lat === null || $lng === null) {
            return null;
        }
        $bestId = null;
        $bestKm = 80.0;
        foreach (self::headquarters() as $name => $point) {
            $km = self::haversineKm($lat, $lng, $point[0], $point[1]);
            if ($km >= $bestKm) {
                continue;
            }
            $id = self::idByName($name, $stateId);
            if (! $id) {
                continue;
            }
            $bestKm = $km;
            $bestId = $id;
        }

        return $bestId;
    }

    private static function idByName(string $name, ?int $stateId): ?int
    {
        try {
            $q = self::districts()->whereRaw('LOWER(name) = ?', [strtolower($name)]);
            if ($stateId) {
                $q->where('state_id', $stateId);
            }
            $id = $q->value('id');

            return $id ? (int) $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function districts()
    {
        try {
            if (config('database.connections.platform') && Schema::connection('platform')->hasTable('districts')) {
                return DB::connection('platform')->table('districts');
            }
        } catch (Throwable) {
        }

        return DB::table('districts');
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }

    /**
     * Longest PIN prefix first. 852216 (Mangrauni) and 852202 are Saharsa, not Araria.
     *
     * @return array<string, string>
     */
    private static function pinPrefixes(): array
    {
        return [
            '110090' => 'North East Delhi',
            '110094' => 'North East Delhi',
            '110093' => 'North East Delhi',
            '110095' => 'North East Delhi',
            '110053' => 'Shahdara',
            '110032' => 'Shahdara',
            '110051' => 'East Delhi',
            '110091' => 'East Delhi',
            '110092' => 'East Delhi',
            '110031' => 'East Delhi',
            '110006' => 'Central Delhi',
            '110002' => 'Central Delhi',
            '110005' => 'Central Delhi',
            '110001' => 'New Delhi',
            '110011' => 'New Delhi',
            '110021' => 'New Delhi',
            '85211' => 'Madhepura',
            '85213' => 'Supaul',
            '8522' => 'Saharsa',
            '8543' => 'Araria',
            '8541' => 'Katihar',
            '8542' => 'Kishanganj',
            '8544' => 'Purnia',
            '800' => 'Patna',
            '801' => 'Patna',
            '803' => 'Nalanda',
            '804' => 'Jehanabad',
            '805' => 'Gaya',
        ];
    }

    /**
     * @return list<string>
     */
    private static function genericCityNames(): array
    {
        return ['new delhi', 'delhi', 'nct of delhi', 'national capital territory of delhi'];
    }

    /**
     * Longest / most specific locality first.
     *
     * @return array<string, string>
     */
    private static function localities(): array
    {
        return [
            'old mustafabad' => 'North East Delhi',
            'new mustafabad' => 'North East Delhi',
            'mustafabad' => 'North East Delhi',
            'karawal nagar' => 'North East Delhi',
            'gokalpuri' => 'North East Delhi',
            'gokalpur' => 'North East Delhi',
            'khajuri khas' => 'North East Delhi',
            'bhajanpura' => 'North East Delhi',
            'yamuna vihar' => 'North East Delhi',
            'nehru vihar' => 'North East Delhi',
            'jafrabad' => 'Shahdara',
            'seelampur' => 'Shahdara',
            'shahdara' => 'Shahdara',
            'connaught place' => 'New Delhi',
            'rajiv chowk' => 'New Delhi',
        ];
    }

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    private static function headquarters(): array
    {
        return [
            'Araria' => [26.148, 87.517],
            'Arwal' => [25.250, 84.680],
            'Aurangabad' => [24.750, 84.370],
            'Banka' => [24.880, 86.920],
            'Begusarai' => [25.420, 86.130],
            'Bhagalpur' => [25.240, 86.980],
            'Bhojpur' => [25.560, 84.660],
            'Buxar' => [25.560, 83.980],
            'Darbhanga' => [26.170, 85.900],
            'East Champaran' => [26.650, 84.920],
            'Gaya' => [24.780, 85.000],
            'Gopalganj' => [26.470, 84.440],
            'Jamui' => [24.920, 86.220],
            'Jehanabad' => [25.210, 84.990],
            'Kaimur' => [25.050, 83.620],
            'Katihar' => [25.540, 87.570],
            'Khagaria' => [25.500, 86.480],
            'Kishanganj' => [26.100, 87.950],
            'Lakhisarai' => [25.170, 86.090],
            'Madhepura' => [25.920, 86.790],
            'Madhubani' => [26.370, 86.070],
            'Munger' => [25.370, 86.470],
            'Muzaffarpur' => [26.120, 85.390],
            'Nalanda' => [25.200, 85.520],
            'Nawada' => [24.880, 85.540],
            'Patna' => [25.610, 85.140],
            'Purnia' => [25.780, 87.470],
            'Rohtas' => [24.950, 84.030],
            'Saharsa' => [25.880, 86.600],
            'Samastipur' => [25.860, 85.780],
            'Saran' => [25.780, 84.750],
            'Sheikhpura' => [25.140, 85.850],
            'Sheohar' => [26.520, 85.300],
            'Sitamarhi' => [26.590, 85.500],
            'Siwan' => [26.220, 84.360],
            'Supaul' => [26.120, 86.600],
            'Vaishali' => [25.680, 85.310],
            'West Champaran' => [26.800, 84.500],
            'Central Delhi' => [28.651, 77.231],
            'East Delhi' => [28.628, 77.295],
            'New Delhi' => [28.613, 77.209],
            'North Delhi' => [28.676, 77.222],
            'North East Delhi' => [28.700, 77.274],
            'North West Delhi' => [28.732, 77.163],
            'Shahdara' => [28.673, 77.289],
            'South Delhi' => [28.549, 77.200],
            'South East Delhi' => [28.562, 77.273],
            'South West Delhi' => [28.609, 77.091],
            'West Delhi' => [28.666, 77.102],
        ];
    }
}
