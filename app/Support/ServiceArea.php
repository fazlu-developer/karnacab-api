<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ServiceArea
{
    /**
     * @return array{allowed: bool, comingSoon: bool, state: ?string, stateId: ?int, districtId: ?int, message: ?string}
     */
    public static function resolve(?float $lat, ?float $lng, ?string $stateHint = null, ?string $address = null): array
    {
        $live = self::liveStateNames();
        $detected = self::canonicalState($stateHint)
            ?: self::stateFromAddress($address)
            ?: self::reverseState($lat, $lng)
            ?: self::label($lat, $lng);

        $inLiveState = $detected !== null && self::matchesLive($detected, $live);
        $inBbox = $lat !== null && $lng !== null && self::allowsBbox($lat, $lng);
        $allowed = $inLiveState || $inBbox;
        if ($allowed) {
            $detected = $detected ?: self::label($lat, $lng);
        }

        $stateId = self::stateIdFor($detected);
        $districtId = self::districtIdNear($lat, $lng, $stateId);
        $hasFix = $lat !== null && $lng !== null;

        return [
            'allowed' => $allowed,
            'comingSoon' => $hasFix && ! $allowed,
            'state' => $detected,
            'stateId' => $stateId,
            'districtId' => $districtId,
            'message' => ($hasFix && ! $allowed) ? self::comingSoonMessage($detected) : null,
        ];
    }

    public static function allows(?float $lat, ?float $lng, ?string $stateHint = null, ?string $address = null): bool
    {
        return self::resolve($lat, $lng, $stateHint, $address)['allowed'];
    }

    public static function label(?float $lat, ?float $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }
        if (self::inDelhi($lat, $lng)) {
            return 'Delhi';
        }
        if (self::inBihar($lat, $lng)) {
            return 'Bihar';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return array_values(array_unique(array_map(
            fn (string $name) => self::canonicalState($name) ?? $name,
            self::liveStateNames(),
        )));
    }

    public static function comingSoonMessage(?string $detected = null): string
    {
        $live = implode(' and ', self::states()) ?: 'Bihar and Delhi';
        if ($detected) {
            return 'KarnaCab is not live in '.$detected.' yet. We currently operate in '.$live.'.';
        }

        return 'KarnaCab is coming soon in your city. We currently operate in '.$live.'.';
    }

    /**
     * @return list<string>
     */
    private static function liveStateNames(): array
    {
        $names = ['Bihar', 'Delhi', 'National Capital Territory of Delhi', 'NCT of Delhi'];
        try {
            if (Schema::hasTable('states')) {
                $q = DB::table('states')->orderBy('name');
                if (Schema::hasColumn('states', 'status')) {
                    $q->whereIn('status', ['ACTIVE', 'LIVE', 'active', 'live']);
                }
                $fromDb = $q->pluck('name')->filter()->map(fn ($n) => (string) $n)->all();
                if ($fromDb !== []) {
                    $names = array_merge($names, $fromDb);
                }
            }
        } catch (Throwable) {
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $live
     */
    private static function matchesLive(string $name, array $live): bool
    {
        $canonical = self::canonicalState($name) ?? $name;
        foreach ($live as $row) {
            $liveName = self::canonicalState($row) ?? $row;
            if (strcasecmp($canonical, $liveName) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function canonicalState(?string $name): ?string
    {
        $raw = strtolower(trim((string) $name));
        if ($raw === '') {
            return null;
        }
        $raw = str_replace(['-', '_'], ' ', $raw);
        if (str_contains($raw, 'delhi') || $raw === 'nct' || str_contains($raw, 'nct of')) {
            return 'Delhi';
        }
        if (str_contains($raw, 'bihar')) {
            return 'Bihar';
        }

        return ucwords($raw);
    }

    private static function stateFromAddress(?string $address): ?string
    {
        if (! $address) {
            return null;
        }
        if (preg_match('/\b(bihar|delhi|nct)\b/i', $address, $m)) {
            return self::canonicalState($m[1]);
        }

        return null;
    }

    private static function reverseState(?float $lat, ?float $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }
        $key = (string) config('karnacab.google_maps_key', env('GOOGLE_MAPS_API', ''));
        if ($key === '') {
            return null;
        }
        try {
            $response = Http::timeout(4)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => $lat.','.$lng,
                'key' => $key,
                'result_type' => 'administrative_area_level_1',
            ]);
            if (! $response->ok()) {
                return null;
            }
            $components = $response->json('results.0.address_components') ?? [];
            foreach ($components as $component) {
                $types = $component['types'] ?? [];
                if (in_array('administrative_area_level_1', $types, true)) {
                    return self::canonicalState((string) ($component['long_name'] ?? ''));
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private static function allowsBbox(float $lat, float $lng): bool
    {
        return self::inDelhi($lat, $lng) || self::inBihar($lat, $lng);
    }

    private static function inDelhi(float $lat, float $lng): bool
    {
        return $lat >= 28.20 && $lat <= 28.95 && $lng >= 76.70 && $lng <= 77.55;
    }

    private static function inBihar(float $lat, float $lng): bool
    {
        return $lat >= 24.20 && $lat <= 27.70 && $lng >= 83.19 && $lng <= 88.35;
    }

    private static function stateIdFor(?string $name): ?int
    {
        $canonical = self::canonicalState($name);
        if (! $canonical) {
            return null;
        }
        try {
            if (! Schema::hasTable('states')) {
                return null;
            }
            $id = DB::table('states')->where('name', 'like', $canonical.'%')->value('id');

            return $id ? (int) $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function districtIdNear(?float $lat, ?float $lng, ?int $stateId): ?int
    {
        if (! $stateId) {
            return null;
        }
        try {
            if (! Schema::hasTable('districts')) {
                return null;
            }
            $q = DB::table('districts')->where('state_id', $stateId)->orderBy('name');
            $id = $q->value('id');

            return $id ? (int) $id : null;
        } catch (Throwable) {
            return null;
        }
    }
}
