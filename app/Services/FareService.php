<?php

namespace App\Services;

use App\Support\Geo;
use App\Support\ServiceArea;
use App\Support\ServiceMaintenance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FareService
{
    public function quote(array $input): array
    {
        ServiceArea::assertTrip($input);
        $product = strtoupper((string) ($input['product'] ?? 'LOCAL_CAB'));
        ServiceMaintenance::assertOpen($product, $input);
        if (in_array($product, ['LOCAL', 'TRIP', 'CAB'], true)) {
            $product = 'LOCAL_CAB';
        }
        if (empty($input['lockProduct'])) {
            $product = self::cityOrOutstation($product, (float) ($input['distanceKm'] ?? 0));
        }
        $category = strtoupper((string) ($input['category'] ?? 'BIKE'));
        $hours = ($product === 'RENTAL') ? ($input['hours'] ?? 8) : ($input['hours'] ?? null);
        $query = DB::table('fare_rules')->where('product', $product)->where('category', $category)->where('active', 1);
        if (! empty($input['districtId'])) {
            $query->where(function ($q) use ($input) {
                $q->where('district_id', $input['districtId'])->orWhereNull('district_id');
            });
        }
        if ($product === 'RENTAL' && $hours) {
            $query->where('rental_hours', $hours);
        }
        $rule = $query->orderByDesc('district_id')->first();
        if (! $rule && in_array($product, ['ONE_WAY', 'SCHEDULE', 'AIRPORT', 'RAILWAY', 'PARCEL'], true)) {
            $rule = DB::table('fare_rules')->where('active', 1)->where('product', 'LOCAL_CAB')->where('category', $category)->orderByDesc('district_id')->first();
        }
        if (! $rule && $product === 'MULTI_STOP') {
            $rule = DB::table('fare_rules')->where('active', 1)->where('product', 'LOCAL_CAB')->where('category', $category)->orderByDesc('district_id')->first();
        }
        if (! $rule) {
            $fallback = DB::table('fare_rules')->where('active', 1)->where('product', $product);
            $rule = $fallback->orderByDesc('district_id')->first()
                ?: DB::table('fare_rules')->where('active', 1)->orderBy('id')->first();
        }
        if (! $rule) {
            throw new NotFoundHttpException('No fare rule for that product and vehicle');
        }
        $minKm = (float) $rule->min_km;
        $includedKm = (float) $rule->included_km;
        $distanceKm = max((float) ($input['distanceKm'] ?? 1), $minKm);
        if ($product === 'ROUND_WAY' && ($input['roundTrip'] ?? true) !== false) {
            $distanceKm = max($distanceKm * 2, $minKm);
        }
        $extraKm = max(0, $distanceKm - $includedKm);
        $packagePaise = (int) round($includedKm * $rule->per_km_paise);
        $basePaise = (int) round(min($minKm, $includedKm) * $rule->per_km_paise);
        $distancePaise = $packagePaise - $basePaise;
        $extraPaise = (int) round($extraKm * $rule->extra_km_paise);
        $waitingPaise = ((int) ($input['waitMinutes'] ?? 0)) * $rule->waiting_paise_per_min;
        $extraHours = $product === 'RENTAL' ? max(0, (int) ($input['extraHours'] ?? 0)) : 0;
        $rentalExtraPaise = $extraHours * ($rule->extra_hour_paise ?? 15000);
        $stopCount = in_array($product, ['MULTI_STOP', 'RENTAL'], true) ? max(0, (int) ($input['stopCount'] ?? 0)) : 0;
        $stopPaise = $stopCount * ($rule->stop_paise ?? 5000);
        $nights = $product === 'ROUND_WAY' ? max(0, (int) ($input['nightStayNights'] ?? 0)) : 0;
        $nightStayPaise = $nights * ($rule->night_stay_paise ?? 0);
        $driverAllowPaise = $product === 'ROUND_WAY' ? (int) $rule->driver_allow_paise : 0;
        $preNight = $basePaise + $distancePaise + $extraPaise + $waitingPaise + $driverAllowPaise + $nightStayPaise + $rentalExtraPaise + $stopPaise;
        $nightPaise = ! empty($input['night']) ? (int) round(($preNight * $rule->night_percent) / 100) : 0;
        $taxable = $preNight + $nightPaise;
        $toll = 0;
        $parking = 0;
        $gstBase = $rule->apply_gst_to_base ? $taxable : 0;
        $gstPct = (float) ($rule->gst_percent ?? app(RideSettingsService::class)->gstPercent());
        $gstPaise = (int) round(($gstBase * $gstPct) / 100);
        $beforeDiscount = $taxable + $gstPaise;
        $discountPaise = min($beforeDiscount, (int) $rule->discount_paise + (int) round(($beforeDiscount * $rule->discount_percent) / 100));
        $totalPaise = max(0, $beforeDiscount - $discountPaise);

        return [
            'currency' => 'INR',
            'product' => $product,
            'category' => $category,
            'billedKm' => $distanceKm,
            'extraKm' => $extraKm,
            'hours' => $rule->rental_hours ?? $hours,
            'source' => 'server',
            'breakdown' => [
                'basePaise' => $basePaise,
                'distancePaise' => $distancePaise,
                'extraPaise' => $extraPaise,
                'waitingPaise' => $waitingPaise,
                'nightPaise' => $nightPaise,
                'tollPaise' => $toll,
                'parkingPaise' => $parking,
                'gstPaise' => $gstPaise,
                'discountPaise' => $discountPaise,
                'stopPaise' => $stopPaise,
                'extraHourPaise' => $rentalExtraPaise,
                'driverAllowPaise' => $driverAllowPaise,
                'totalPaise' => $totalPaise,
            ],
            'totalPaise' => $totalPaise,
            'totalRupees' => $totalPaise / 100,
            'listRupees' => $beforeDiscount / 100,
            'discountRupees' => $discountPaise / 100,
            'note' => 'Quote from admin fare_rules. Driver is not assigned.',
            'extrasNote' => 'Toll tax and parking are extra. You pay them separately; they are not included in this fare.',
        ];
    }

    public function rentalPackages(?int $districtId = null): array
    {
        $q = DB::table('fare_rules')->where('product', 'RENTAL')->where('active', 1);
        if ($districtId) {
            $q->where(function ($inner) use ($districtId) {
                $inner->where('district_id', $districtId)->orWhereNull('district_id');
            });
        }

        return $q->orderBy('rental_hours')->get()->map(fn ($row) => [
            'hours' => $row->rental_hours,
            'category' => $row->category,
            'includedKm' => (float) $row->included_km,
            'pricePaise' => (int) round(((float) $row->included_km) * $row->per_km_paise),
            'priceRupees' => round(((float) $row->included_km) * $row->per_km_paise) / 100,
            'extraKmPaise' => $row->extra_km_paise,
            'extraHourPaise' => $row->extra_hour_paise ?? 15000,
        ])->all();
    }

    public function rideCatalog(): array
    {
        return [
            'rideTypes' => config('karnacab.ride_types'),
            'vehicleTypes' => array_values(app(VehicleCatalogService::class)->allTypes()),
            'vehicleFamilies' => app(VehicleCatalogService::class)->families(),
            'rentalHours' => array_values(array_unique(array_filter(array_map(
                fn ($row) => (int) ($row['hours'] ?? 0),
                $this->rentalPackages(),
            )))) ?: [2, 4, 6, 8, 12],
            'rentalPackages' => $this->rentalPackages(),
            'schedule' => ['reminderMinutes' => 60],
            'transfer' => [
                'airport' => ['placeTypes' => ['airport'], 'requiresFlightNumber' => true],
                'railway' => ['placeTypes' => ['train_station'], 'requiresTrainNumber' => true],
                'multiStop' => ['minStops' => 1, 'maxStops' => 3],
            ],
            'lifecycle' => collect(['REQUESTED', 'ASSIGNED', 'ARRIVED', 'STARTED', 'COMPLETED', 'CANCELLED'])
                ->map(fn ($key) => ['key' => $key, 'label' => $key])->all(),
            'note' => 'Fares are calculated server-side from fare_rules.',
        ];
    }

    /**
     * City ride is 30 km or less. A longer trip is an outstation one-way ride.
     */
    public static function cityOrOutstation(string $product, float $distanceKm): string
    {
        $product = strtoupper($product);
        if (! in_array($product, ['LOCAL_CAB', 'ONE_WAY', 'OUTSTATION'], true) || $distanceKm <= 0) {
            return $product === 'OUTSTATION' ? 'ONE_WAY' : $product;
        }

        return $distanceKm > 30 ? 'ONE_WAY' : 'LOCAL_CAB';
    }

    public function roadDistanceKm(float $oLat, float $oLng, float $dLat, float $dLng): ?float
    {
        $key = (string) env('GOOGLE_MAPS_API', '');
        if ($key === '') {
            return null;
        }
        try {
            $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/directions/json', [
                'origin' => $oLat.','.$oLng,
                'destination' => $dLat.','.$dLng,
                'key' => $key,
                'mode' => 'driving',
            ]);
        } catch (\Throwable) {
            return null;
        }
        $meters = $response->json('routes.0.legs.0.distance.value');
        if (! is_numeric($meters)) {
            return null;
        }

        return round(((float) $meters) / 1000, 2);
    }

    /**
     * @param  list<array{lat: float, lng: float}>|mixed  $raw
     * @return list<array{lat: float, lng: float}>
     */
    public function parseWaypoints(mixed $raw): array
    {
        $points = [];
        if (is_string($raw) && trim($raw) !== '') {
            foreach (preg_split('/\|/', $raw) ?: [] as $pair) {
                $pair = trim(str_ireplace('via:', '', $pair));
                $parts = preg_split('/\s*,\s*/', $pair) ?: [];
                if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                    $points[] = ['lat' => (float) $parts[0], 'lng' => (float) $parts[1]];
                }
            }

            return $points;
        }
        if (! is_array($raw)) {
            return [];
        }
        foreach ($raw as $item) {
            if (is_string($item)) {
                $points = array_merge($points, $this->parseWaypoints($item));
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $lat = $item['lat'] ?? $item['latitude'] ?? null;
            $lng = $item['lng'] ?? $item['longitude'] ?? null;
            if (is_numeric($lat) && is_numeric($lng)) {
                $points[] = ['lat' => (float) $lat, 'lng' => (float) $lng];
            }
        }

        return $points;
    }

    /**
     * Driving km from first stop through every waypoint to the last stop.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function withResolvedTripDistance(array $input): array
    {
        $product = strtoupper((string) ($input['product'] ?? ''));
        $stops = $this->parseWaypoints($input['waypoints'] ?? $input['stops'] ?? []);
        $needsPath = $stops !== [] || in_array($product, ['MULTI_STOP', 'RENTAL'], true);
        $oLat = isset($input['pickupLat']) ? (float) $input['pickupLat'] : null;
        $oLng = isset($input['pickupLng']) ? (float) $input['pickupLng'] : null;
        $dLat = isset($input['dropLat']) ? (float) $input['dropLat'] : null;
        $dLng = isset($input['dropLng']) ? (float) $input['dropLng'] : null;
        if (! $needsPath || $oLat === null || $oLng === null || $dLat === null || $dLng === null) {
            return $input;
        }
        $route = $this->directions($oLat, $oLng, $dLat, $dLng, $stops);
        $input['distanceKm'] = (float) ($route['distanceKm'] ?? $input['distanceKm'] ?? 1);
        if (! empty($route['durationMinutes'])) {
            $input['durationMinutes'] = $route['durationMinutes'];
        }
        if (! empty($route['polyline']) && empty($input['polyline'])) {
            $input['polyline'] = $route['polyline'];
        }
        $input['stopCount'] = max((int) ($input['stopCount'] ?? 0), count($stops));

        return $input;
    }

    public function directions(float $oLat, float $oLng, float $dLat, float $dLng, array $waypoints = []): array
    {
        $points = $this->parseWaypoints($waypoints);
        $fallback = $this->pathEstimate($oLat, $oLng, $dLat, $dLng, $points);
        $key = (string) env('GOOGLE_MAPS_API', '');
        if ($key === '') {
            return $fallback;
        }
        $wp = collect($points)->map(fn ($p) => 'via:'.$p['lat'].','.$p['lng'])->implode('|');
        try {
            $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/directions/json', array_filter([
                'origin' => $oLat.','.$oLng,
                'destination' => $dLat.','.$dLng,
                'waypoints' => $wp ?: null,
                'key' => $key,
                'mode' => 'driving',
            ], fn ($value) => $value !== null && $value !== ''));
        } catch (\Throwable) {
            return $fallback;
        }
        $legs = $response->json('routes.0.legs') ?? [];
        if ($points !== [] && count($legs) < 2) {
            return $fallback;
        }
        $legKm = 0.0;
        $seconds = 0;
        foreach ($legs as $leg) {
            $legKm += ((float) ($leg['distance']['value'] ?? 0)) / 1000;
            $seconds += (int) ($leg['duration']['value'] ?? 0);
        }
        if ($legKm <= 0) {
            return $fallback;
        }
        $poly = $response->json('routes.0.overview_polyline.points');

        return [
            'distanceKm' => max(1, round($legKm, 2)),
            'durationSeconds' => $seconds,
            'durationMinutes' => max(1, $seconds > 0 ? (int) round($seconds / 60) : (int) max(1, round($legKm * 2))),
            'polyline' => $poly,
            'legCount' => count($legs),
        ];
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $waypoints
     * @return array<string, mixed>
     */
    private function pathEstimate(float $oLat, float $oLng, float $dLat, float $dLng, array $waypoints): array
    {
        $chain = array_merge(
            [['lat' => $oLat, 'lng' => $oLng]],
            $waypoints,
            [['lat' => $dLat, 'lng' => $dLng]],
        );
        $km = 0.0;
        for ($i = 1; $i < count($chain); $i++) {
            $km += Geo::haversineKm(
                (float) $chain[$i - 1]['lat'],
                (float) $chain[$i - 1]['lng'],
                (float) $chain[$i]['lat'],
                (float) $chain[$i]['lng'],
            );
        }
        $road = max(1, round($km * 1.25, 2));

        return [
            'distanceKm' => $road,
            'durationSeconds' => (int) max(180, round($road * 120)),
            'durationMinutes' => max(1, (int) round($road * 2)),
            'polyline' => null,
            'legCount' => max(1, count($chain) - 1),
        ];
    }

    public function autocomplete(string $q, ?string $types = null): array
    {
        $key = (string) env('GOOGLE_MAPS_API', '');
        if ($key === '' || $q === '') {
            return ['suggestions' => []];
        }
        $params = [
            'input' => $q,
            'key' => $key,
            'components' => 'country:in',
            'language' => 'en',
        ];
        if (is_string($types) && trim($types) !== '') {
            $params['types'] = $types;
        }
        $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/place/autocomplete/json', $params);

        return ['suggestions' => collect($response->json('predictions') ?? [])->map(function ($row) {
            $fmt = is_array($row['structured_formatting'] ?? null) ? $row['structured_formatting'] : [];
            $description = (string) ($row['description'] ?? '');

            return [
                'placeId' => $row['place_id'] ?? null,
                'title' => (string) ($fmt['main_text'] ?? explode(',', $description)[0] ?? $description),
                'subtitle' => (string) ($fmt['secondary_text'] ?? ''),
                'address' => $description,
                'description' => $description,
            ];
        })->all()];
    }

    public function placeDetails(string $placeId): array
    {
        $key = (string) env('GOOGLE_MAPS_API', '');
        $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/place/details/json', [
            'place_id' => $placeId,
            'key' => $key,
            'fields' => 'geometry,formatted_address,name',
        ]);
        $result = $response->json('result') ?? [];

        return [
            'placeId' => $placeId,
            'name' => $result['name'] ?? '',
            'address' => $result['formatted_address'] ?? '',
            'lat' => $result['geometry']['location']['lat'] ?? null,
            'lng' => $result['geometry']['location']['lng'] ?? null,
        ];
    }

    public function reverseGeocode(float $lat, float $lng): array
    {
        $empty = [
            'title' => '',
            'subtitle' => '',
            'address' => '',
            'lat' => $lat,
            'lng' => $lng,
            'state' => null,
        ];
        $key = (string) env('GOOGLE_MAPS_API', '');
        if ($key === '') {
            return $empty;
        }
        $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'latlng' => $lat.','.$lng,
            'key' => $key,
            'language' => 'en',
        ]);
        $results = $response->json('results');
        if (! is_array($results) || $results === []) {
            return $empty;
        }
        $row = $results[0];
        $formatted = trim((string) ($row['formatted_address'] ?? ''));
        $parts = array_values(array_filter(array_map('trim', explode(',', $formatted))));
        $title = $parts[0] ?? '';
        $subtitle = implode(', ', array_slice($parts, 1));
        $state = null;
        foreach ($row['address_components'] ?? [] as $component) {
            $types = $component['types'] ?? [];
            if (is_array($types) && in_array('administrative_area_level_1', $types, true)) {
                $state = $component['long_name'] ?? null;
                break;
            }
        }

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'address' => $formatted,
            'lat' => $lat,
            'lng' => $lng,
            'state' => $state,
        ];
    }
}
