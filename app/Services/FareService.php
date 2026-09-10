<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FareService
{
    public function quote(array $input): array
    {
        $product = $input['product'];
        $category = $input['category'];
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
        $stopCount = $product === 'MULTI_STOP' ? max(0, (int) ($input['stopCount'] ?? 0)) : 0;
        $stopPaise = $stopCount * ($rule->stop_paise ?? 0);
        $nights = $product === 'ROUND_WAY' ? max(0, (int) ($input['nightStayNights'] ?? 0)) : 0;
        $nightStayPaise = $nights * ($rule->night_stay_paise ?? 0);
        $driverAllowPaise = $product === 'ROUND_WAY' ? (int) $rule->driver_allow_paise : 0;
        $preNight = $basePaise + $distancePaise + $extraPaise + $waitingPaise + $driverAllowPaise + $nightStayPaise + $rentalExtraPaise + $stopPaise;
        $nightPaise = ! empty($input['night']) ? (int) round(($preNight * $rule->night_percent) / 100) : 0;
        $taxable = $preNight + $nightPaise;
        $toll = $rule->apply_toll ? (int) ($input['tollPaise'] ?? 0) : 0;
        $parking = $rule->apply_parking ? (int) ($input['parkingPaise'] ?? 0) : 0;
        $gstBase = $rule->apply_gst_to_base ? $taxable : 0;
        $gstPaise = (int) round(($gstBase * $rule->gst_percent) / 100);
        $beforeDiscount = $taxable + $toll + $parking + $gstPaise;
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
                'totalPaise' => $totalPaise,
            ],
            'totalPaise' => $totalPaise,
            'totalRupees' => $totalPaise / 100,
            'listRupees' => $beforeDiscount / 100,
            'discountRupees' => $discountPaise / 100,
            'note' => 'Quote from admin fare_rules. Driver is not assigned.',
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
            'vehicleTypes' => config('karnacab.vehicle_types'),
            'rentalHours' => [2, 4, 6, 8, 12],
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

    public function directions(float $oLat, float $oLng, float $dLat, float $dLng, array $waypoints = []): array
    {
        $key = (string) env('GOOGLE_MAPS_API', '');
        if ($key === '') {
            return ['distanceKm' => 1, 'durationSeconds' => 180, 'polyline' => null];
        }
        $wp = collect($waypoints)->map(fn ($p) => $p['lat'].','.$p['lng'])->implode('|');
        $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/directions/json', array_filter([
            'origin' => $oLat.','.$oLng,
            'destination' => $dLat.','.$dLng,
            'waypoints' => $wp ?: null,
            'key' => $key,
            'mode' => 'driving',
        ]));
        $legKm = 0;
        $seconds = 0;
        $poly = null;
        foreach ($response->json('routes.0.legs') ?? [] as $leg) {
            $legKm += ($leg['distance']['value'] ?? 0) / 1000;
            $seconds += $leg['duration']['value'] ?? 0;
        }
        $poly = $response->json('routes.0.overview_polyline.points');

        return ['distanceKm' => max(1, round($legKm, 2)), 'durationSeconds' => $seconds, 'polyline' => $poly];
    }

    public function autocomplete(string $q, ?string $types = null): array
    {
        $key = (string) env('GOOGLE_MAPS_API', '');
        if ($key === '' || $q === '') {
            return ['suggestions' => []];
        }
        $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/place/autocomplete/json', [
            'input' => $q,
            'key' => $key,
            'components' => 'country:in',
            'types' => $types,
        ]);

        return ['suggestions' => collect($response->json('predictions') ?? [])->map(fn ($row) => [
            'placeId' => $row['place_id'] ?? null,
            'description' => $row['description'] ?? '',
        ])->all()];
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
}
