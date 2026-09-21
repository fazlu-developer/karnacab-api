<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
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
        $inBbox = $lat !== null && $lng !== null && self::allowsBbox($lat, $lng, $live);
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
            'message' => ($hasFix && ! $allowed) ? self::comingSoonMessage($detected) : (! $hasFix ? self::locationRequiredMessage() : null),
        ];
    }

    /**
     * A trip is bookable when pickup OR drop is inside an active KarnaCab service state.
     *
     * @param  array<string, mixed>  $input
     * @return array{allowed: bool, comingSoon: bool, pickupState: ?string, dropState: ?string, message: ?string, serviceStates: list<string>}
     */
    public static function trip(array $input): array
    {
        $pickupLat = self::coord($input, ['pickupLat', 'pickup_lat', 'originLat']);
        $pickupLng = self::coord($input, ['pickupLng', 'pickup_lng', 'originLng']);
        $dropLat = self::coord($input, ['dropLat', 'drop_lat', 'destLat']);
        $dropLng = self::coord($input, ['dropLng', 'drop_lng', 'destLng']);
        $pickupText = isset($input['pickupText']) ? (string) $input['pickupText'] : (string) ($input['pickup_text'] ?? '');
        $dropText = isset($input['dropText']) ? (string) $input['dropText'] : (string) ($input['drop_text'] ?? '');

        $hasPoint = $pickupLat !== null || $dropLat !== null || $pickupText !== '' || $dropText !== '';
        if (! $hasPoint) {
            return [
                'allowed' => true,
                'comingSoon' => false,
                'pickupState' => null,
                'dropState' => null,
                'message' => null,
                'serviceStates' => self::states(),
            ];
        }

        $pickup = self::resolve(
            $pickupLat,
            $pickupLng,
            isset($input['pickupState']) ? (string) $input['pickupState'] : null,
            $pickupText !== '' ? $pickupText : null,
        );
        $drop = self::resolve(
            $dropLat,
            $dropLng,
            isset($input['dropState']) ? (string) $input['dropState'] : null,
            $dropText !== '' ? $dropText : null,
        );
        $allowed = $pickup['allowed'] || $drop['allowed'];
        $detected = $drop['state'] ?: $pickup['state'];

        return [
            'allowed' => $allowed,
            'comingSoon' => ! $allowed,
            'pickupState' => $pickup['state'],
            'dropState' => $drop['state'],
            'message' => $allowed ? null : self::comingSoonMessage($detected),
            'serviceStates' => self::states(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function assertTrip(array $input): void
    {
        $trip = self::trip($input);
        if ($trip['comingSoon']) {
            throw new UnprocessableEntityHttpException((string) $trip['message']);
        }
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
        $live = self::liveStateNames();
        if (self::inDelhi($lat, $lng) && self::matchesLive('Delhi', $live)) {
            return 'Delhi';
        }
        if (self::inBihar($lat, $lng) && self::matchesLive('Bihar', $live)) {
            return 'Bihar';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $name) => self::canonicalState($name) ?? $name,
            self::liveStateNames(),
        ), fn (string $name) => ! in_array($name, ['National Capital Territory of Delhi', 'NCT of Delhi'], true))));
    }

    public static function comingSoonMessage(?string $detected = null): string
    {
        $live = implode(' and ', self::states()) ?: 'live KarnaCab states';
        if ($detected) {
            return 'Coming soon: KarnaCab is not live in '.$detected.' yet. Service is available when pickup or destination is in '.$live.'.';
        }

        return 'Coming soon: KarnaCab is not live for this pickup and destination. We currently operate in '.$live.'.';
    }

    public static function locationRequiredMessage(): string
    {
        return 'Allow location access so KarnaCab can confirm whether your state is live. Otherwise this city stays Coming soon.';
    }

    /**
     * @return list<string>
     */
    private static function liveStateNames(): array
    {
        $fallback = ['Bihar', 'Delhi', 'National Capital Territory of Delhi', 'NCT of Delhi'];
        try {
            if (! Schema::hasTable('states')) {
                return $fallback;
            }
            $q = DB::table('states')->orderBy('name');
            if (Schema::hasColumn('states', 'status')) {
                $q->whereIn('status', ['ACTIVE', 'LIVE', 'active', 'live']);
            }
            $fromDb = $q->pluck('name')->filter()->map(fn ($n) => (string) $n)->all();
            if ($fromDb !== []) {
                return array_values(array_unique($fromDb));
            }
        } catch (Throwable) {
        }

        return $fallback;
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
        foreach (self::liveStateNames() as $name) {
            $canonical = self::canonicalState($name) ?? $name;
            if ($canonical !== '' && preg_match('/\b'.preg_quote($canonical, '/').'\b/i', $address)) {
                return $canonical;
            }
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

    /**
     * @param  list<string>  $live
     */
    private static function allowsBbox(float $lat, float $lng, array $live): bool
    {
        if (self::inDelhi($lat, $lng) && self::matchesLive('Delhi', $live)) {
            return true;
        }

        return self::inBihar($lat, $lng) && self::matchesLive('Bihar', $live);
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

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     */
    private static function coord(array $input, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($input[$key]) && $input[$key] !== '' && is_numeric($input[$key])) {
                return (float) $input[$key];
            }
        }

        return null;
    }
}
