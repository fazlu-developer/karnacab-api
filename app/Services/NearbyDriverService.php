<?php

namespace App\Services;

use App\Support\BookingStatus;
use App\Support\Geo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    ): array {
        $radius = $radiusKm ?? $this->settings->radiusKm();
        $staleAfter = now()->subSeconds($this->settings->locationStaleSeconds());
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
            ->whereIn('d.duty_status', ['online', 'ONLINE'])
            ->where(function ($q) {
                $q->whereIn(DB::raw('LOWER(d.kyc_status)'), ['approved', 'active', 'verified', 'pending', 'under_review', 'submitted'])
                    ->orWhereNotNull('v.id');
            })
            ->whereNotNull('u.last_lat')
            ->whereNotNull('u.last_lng')
            ->where(function ($q) use ($staleAfter) {
                $q->whereNull('u.location_updated_at')
                    ->orWhere('u.location_updated_at', '>=', $staleAfter);
            })
            ->where(function ($q) use ($category) {
                $q->where('v.category', $category)
                    ->orWhereRaw('UPPER(v.category) = ?', [strtoupper($category)]);
            })
            ->whereNotExists(function ($q) use ($busyStatuses) {
                $q->select(DB::raw(1))
                    ->from('bookings as b')
                    ->whereColumn('b.driver_id', 'd.id')
                    ->whereIn('b.status', $busyStatuses);
            });
        $localOnly = ! $includeOutside && $districtId && strtoupper((string) $product) === 'LOCAL_CAB';
        if ($localOnly) {
            $rowsQuery = $rowsQuery->where(function ($q) use ($districtId) {
                $q->where('u.district_id', $districtId)
                    ->orWhereNull('u.district_id');
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

        return $rows
            ->map(function ($row) use ($lat, $lng) {
                $driverLat = (float) $row->last_lat;
                $driverLng = (float) $row->last_lng;
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
            ->filter(fn (array $row) => $includeOutside || $row['distanceKm'] <= $radius)
            ->sortBy('distanceKm')
            ->values()
            ->all();
    }

    public function publicMarkers(float $lat, float $lng, string $category): Collection
    {
        $radius = $this->settings->radiusKm();

        return collect($this->search($lat, $lng, $category, $radius))
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
