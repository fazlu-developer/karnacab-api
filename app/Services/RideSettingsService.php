<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RideSettingsService
{
    public const RADIUS_KEY = 'driver_search_radius_km';
    public const LEGACY_RADIUS_KEY = 'driver_offer_radius_km';
    public const TIMEOUT_KEY = 'ride_request_timeout_seconds';
    public const LOCATION_STALE_KEY = 'driver_location_stale_seconds';

    public function radiusKm(): float
    {
        $value = $this->get(self::RADIUS_KEY);
        if ($value === null || $value === '') {
            $value = $this->get(self::LEGACY_RADIUS_KEY);
        }

        $km = is_numeric($value) ? (float) $value : 10.0;

        return max(1, min(100, $km));
    }

    public function requestTimeoutSeconds(): int
    {
        $value = $this->get(self::TIMEOUT_KEY);
        $seconds = is_numeric($value) ? (int) $value : 30;

        return max(10, min(300, $seconds));
    }

    public function locationStaleSeconds(): int
    {
        $value = $this->get(self::LOCATION_STALE_KEY);
        $seconds = is_numeric($value) ? (int) $value : 3600;

        return max(30, min(3600, $seconds));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $this->ensureDefaults();
        return [
            'driverSearchRadiusKm' => $this->radiusKm(),
            'rideRequestTimeoutSeconds' => $this->requestTimeoutSeconds(),
            'driverLocationStaleSeconds' => $this->locationStaleSeconds(),
        ];
    }

    public function update(array $input): array
    {
        if (isset($input['driverSearchRadiusKm']) || isset($input['radiusKm'])) {
            $km = (float) ($input['driverSearchRadiusKm'] ?? $input['radiusKm']);
            $this->put(self::RADIUS_KEY, (string) max(1, min(100, $km)));
            $this->put(self::LEGACY_RADIUS_KEY, (string) max(1, min(100, $km)));
        }
        if (isset($input['rideRequestTimeoutSeconds']) || isset($input['timeoutSeconds'])) {
            $seconds = (int) ($input['rideRequestTimeoutSeconds'] ?? $input['timeoutSeconds']);
            $this->put(self::TIMEOUT_KEY, (string) max(10, min(300, $seconds)));
        }

        return $this->present();
    }

    public function ensureDefaults(): void
    {
        if ($this->get(self::RADIUS_KEY) === null) {
            $legacy = $this->get(self::LEGACY_RADIUS_KEY);
            $this->put(self::RADIUS_KEY, $legacy !== null && $legacy !== '' ? (string) $legacy : '10');
        }
        if ($this->get(self::TIMEOUT_KEY) === null) {
            $this->put(self::TIMEOUT_KEY, '30');
        }
    }

    private function get(string $key): ?string
    {
        if (! Schema::hasTable('system_settings')) {
            return null;
        }
        $value = DB::table('system_settings')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    private function put(string $key, string $value): void
    {
        $row = ['key' => $key, 'value' => $value];
        if (Schema::hasColumn('system_settings', 'updated_at')) {
            $row['updated_at'] = now();
        }
        $existing = DB::table('system_settings')->where('key', $key)->first();
        if ($existing) {
            DB::table('system_settings')->where('key', $key)->update(
                array_diff_key($row, ['key' => true]),
            );

            return;
        }
        if (Schema::hasColumn('system_settings', 'created_at')) {
            $row['created_at'] = now();
        }
        DB::table('system_settings')->insert($row);
    }
}
