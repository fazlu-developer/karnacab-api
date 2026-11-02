<?php

namespace App\Services;

use App\Support\BookingStatus;
use App\Support\Geo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NearbyDriverService
{
    public function __construct(private readonly RideSettingsService $settings) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function search(
        float $lat,
        float $lng,
        string $category,
        ?float $radiusKm = null,
        bool $includeOutside = false,
        ?int $districtId = null,
        ?string $product = null,
        bool $anyCategory = false,
    ): array {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return [];
        }
        $radius = $radiusKm ?? $this->settings->radiusKm();
        $staleAfter = now()->subSeconds($this->settings->locationStaleSeconds());
        $latPad = $radius / 110;
        $lngPad = $radius / max(1, 110 * cos(deg2rad($lat)));
        $busyStatuses = BookingStatus::openForDriver();

        $rowsQuery = DB::table('drivers as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('vehicles as v', function ($join) {
                $join->on('v.driver_id', '=', 'd.id')
                    ->where(function ($q) {
                        $q->whereNull('v.status')->orWhereIn('v.status', ['ACTIVE', 'active', 'APPROVED', 'approved']);
                    });
            })
            ->where('d.online', 1)
            ->whereIn('d.duty_status', ['online', 'ONLINE', 'available', 'AVAILABLE'])
            ->whereIn(DB::raw('LOWER(d.kyc_status)'), ['approved', 'active', 'verified'])
            ->whereNotNull('v.id')
            ->whereNotNull('u.last_lat')
            ->whereNotNull('u.last_lng')
            ->whereNotNull('u.location_updated_at')
            ->where('u.location_updated_at', '>=', $staleAfter)
            ->whereBetween('u.last_lat', [$lat - $latPad, $lat + $latPad])
            ->whereBetween('u.last_lng', [$lng - $lngPad, $lng + $lngPad])
            ->when(! $anyCategory, function ($q) use ($category, $product) {
                $family = strtoupper((string) $product) === 'PARCEL'
                    ? \App\Support\VehicleCategory::parcelOfferFamily((string) $category)
                    : \App\Support\VehicleCategory::family((string) $category);
                $q->where(function ($inner) use ($family) {
                    $inner->whereIn('v.category', $family);
                    foreach ($family as $item) {
                        $inner->orWhereRaw('UPPER(v.category) = ?', [$item]);
                    }
                });
            })
            ->whereNotExists(function ($q) use ($busyStatuses) {
                $q->select(DB::raw(1))
                    ->from('bookings as b')
                    ->whereColumn('b.driver_id', 'd.id')
                    ->whereIn('b.status', $busyStatuses);
            });
        if (Schema::hasTable('parcel_shipments')) {
            $rowsQuery->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('parcel_shipments as ps')
                    ->whereColumn('ps.driver_id', 'd.id')
                    ->whereIn('ps.status', ['assigned', 'picked_up', 'in_transit', 'out_for_delivery', 'arrived']);
            });
        }
        $rows = $rowsQuery
            ->select(array_values(array_filter([
                'd.id as driver_id',
                'd.user_id',
                'd.rating_avg',
                'd.duty_status',
                'u.name',
                'u.phone',
                'u.last_lat',
                'u.last_lng',
                Schema::hasColumn('users', 'last_heading') ? 'u.last_heading' : null,
                'u.location_updated_at',
                'v.id as vehicle_id',
                'v.category as vehicle_category',
                'v.registration_no',
            ])))
            ->get();

        $matches = $rows
            ->map(function ($row) use ($lat, $lng) {
                $driverLat = (float) $row->last_lat;
                $driverLng = (float) $row->last_lng;
                if ($driverLat < -90 || $driverLat > 90 || $driverLng < -180 || $driverLng > 180 || ($driverLat == 0.0 && $driverLng == 0.0)) {
                    return null;
                }
                $km = Geo::haversineKm($lat, $lng, $driverLat, $driverLng);

                return [
                    'driverId' => (int) $row->driver_id,
                    'userId' => (int) $row->user_id,
                    'name' => $row->name,
                    'ratingAvg' => (float) $row->rating_avg,
                    'lat' => $driverLat,
                    'lng' => $driverLng,
                    'heading' => isset($row->last_heading) && $row->last_heading !== null ? (float) $row->last_heading : null,
                    'distanceKm' => $km,
                    'vehicleId' => $row->vehicle_id ? (int) $row->vehicle_id : null,
                    'vehicleCategory' => $row->vehicle_category,
                    'registrationNo' => $row->registration_no,
                    'locationUpdatedAt' => $row->location_updated_at,
                ];
            })
            ->filter(fn ($row) => is_array($row) && ($includeOutside || $row['distanceKm'] <= $radius))
            ->sortBy('distanceKm')
            ->values()
            ->all();

        if ($matches === [] && strtoupper((string) $product) === 'PARCEL') {
            Log::info('parcel.nearby_empty', [
                'category' => strtoupper($category),
                'radiusKm' => $radius,
                'lat' => $lat,
                'lng' => $lng,
                'dropped' => $this->parcelDrops($lat, $lng, $category, $radius),
            ]);
        }

        return $matches;
    }

    /**
     * Drivers inside the radius who the parcel search did not offer, and why.
     *
     * @return list<array{driverId: int, reason: string, km: float, kyc: string, duty: string, category: string}>
     */
    private function parcelDrops(float $lat, float $lng, string $category, float $radius): array
    {
        $wanted = strtoupper(trim($category));
        $rows = DB::table('drivers as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('vehicles as v', 'v.driver_id', '=', 'd.id')
            ->whereNotNull('u.last_lat')
            ->whereNotNull('u.last_lng')
            ->select('d.id as driver_id', 'd.online', 'd.duty_status', 'd.kyc_status', 'd.fleet_owner_id', 'u.last_lat', 'u.last_lng', 'u.location_updated_at', 'v.category as vehicle_category', 'v.id as vehicle_id')
            ->orderByDesc('d.id')
            ->limit(80)
            ->get();
        $staleAfter = now()->subSeconds($this->settings->locationStaleSeconds());
        $dropped = [];
        foreach ($rows as $row) {
            $km = Geo::haversineKm($lat, $lng, (float) $row->last_lat, (float) $row->last_lng);
            if ($km > $radius) {
                continue;
            }
            $kyc = strtolower((string) $row->kyc_status);
            $duty = strtolower((string) $row->duty_status);
            $vehicle = strtoupper((string) ($row->vehicle_category ?? ''));
            $reason = 'eligible';
            if (! $row->online) {
                $reason = 'offline';
            } elseif (! in_array($duty, ['online', 'available'], true)) {
                $reason = 'duty_'.$duty;
            } elseif (! in_array($kyc, ['approved', 'active', 'verified'], true)) {
                $reason = 'kyc_'.$kyc;
            } elseif (! $row->vehicle_id) {
                $reason = 'no_vehicle';
            } elseif ($vehicle === '' || ! \App\Support\VehicleCategory::parcelCompatible($vehicle, $wanted)) {
                $reason = 'vehicle_'.$vehicle;
            } elseif ($row->location_updated_at && \Illuminate\Support\Carbon::parse($row->location_updated_at)->lt($staleAfter)) {
                $reason = 'stale_location';
            } else {
                $reason = 'busy_or_territory';
            }
            $dropped[] = [
                'driverId' => (int) $row->driver_id,
                'reason' => $reason,
                'km' => round($km, 2),
                'kyc' => $kyc,
                'duty' => $duty,
                'category' => $vehicle,
            ];
            if (count($dropped) >= 12) {
                break;
            }
        }

        return $dropped;
    }

    /**
     * Nearest fresh driver for each vehicle category inside the maximum radius.
     *
     * @return array<string, array{count: int, distanceKm: float, driverId: int}>
     */
    public function nearestByCategory(float $lat, float $lng, ?string $product = null): array
    {
        $grouped = [];
        foreach ($this->search($lat, $lng, '', $this->settings->radiusKm(), false, null, $product, true) as $row) {
            $key = strtoupper((string) ($row['vehicleCategory'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (! isset($grouped[$key])) {
                $grouped[$key] = ['count' => 0, 'distanceKm' => (float) $row['distanceKm'], 'driverId' => (int) $row['driverId']];
            }
            $grouped[$key]['count']++;
        }

        return $grouped;
    }

    public function publicMarkers(float $lat, float $lng, string $category, ?string $product = null): Collection
    {
        $radius = $this->settings->radiusKm();

        return collect($this->search($lat, $lng, $category, $radius, false, null, $product))
            ->map(fn (array $row) => [
                'driverId' => $row['driverId'],
                'lat' => $row['lat'],
                'lng' => $row['lng'],
                    'heading' => $row['heading'],
                    'category' => strtoupper((string) ($row['vehicleCategory'] ?? $category)),
                    'vehicleCategory' => strtoupper((string) ($row['vehicleCategory'] ?? $category)),
                'distanceKm' => round($row['distanceKm'], 2),
            ])
            ->values();
    }
}
