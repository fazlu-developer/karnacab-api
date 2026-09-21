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
    public const WALLET_MIN_PAISE_KEY = 'driver_wallet_min_paise';
    public const WALLET_MIN_FARE_PERCENT_KEY = 'driver_wallet_min_fare_percent';
    public const WALLET_COVER_COMMISSION_KEY = 'driver_wallet_must_cover_commission';

    public function radiusKm(): float
    {
        $value = $this->get(self::RADIUS_KEY);
        if ($value === null || $value === '') {
            $value = $this->get(self::LEGACY_RADIUS_KEY);
        }

        $km = is_numeric($value) ? (float) $value : 20.0;

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

    public function driverWalletMinPaise(): int
    {
        $value = $this->get(self::WALLET_MIN_PAISE_KEY);
        $paise = is_numeric($value) ? (int) $value : 0;

        return max(0, min(50000000, $paise));
    }

    public function driverWalletMinFarePercent(): float
    {
        $value = $this->get(self::WALLET_MIN_FARE_PERCENT_KEY);
        $pct = is_numeric($value) ? (float) $value : 0;

        return max(0, min(100, $pct));
    }

    public function driverWalletMustCoverCommission(): bool
    {
        $value = $this->get(self::WALLET_COVER_COMMISSION_KEY);

        return $value === null || $value === '' ? true : in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }

    /**
     * @return array{requiredPaise: int, commissionPaise: int, commissionPercent: float, eligible: bool, reason: ?string}
     */
    public function walletEligibility(int $balancePaise, int $farePaise): array
    {
        $percent = Schema::hasTable('commission_rules')
            ? (float) (DB::table('commission_rules')->where('active', 1)->orderBy('id')->value('percent') ?? 10)
            : 10.0;
        $commission = (int) round(max(0, $farePaise) * ($percent / 100));
        $required = $this->driverWalletMinPaise();
        $byPercent = (int) round(max(0, $farePaise) * ($this->driverWalletMinFarePercent() / 100));
        $required = max($required, $byPercent);
        if ($this->driverWalletMustCoverCommission()) {
            $required = max($required, $commission);
        }
        $eligible = $balancePaise >= $required;

        return [
            'requiredPaise' => $required,
            'commissionPaise' => $commission,
            'commissionPercent' => $percent,
            'eligible' => $eligible,
            'reason' => $eligible ? null : 'Add money to your wallet before accepting this booking. Required ₹'.number_format($required / 100, 0).', available ₹'.number_format($balancePaise / 100, 0).'.',
        ];
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
            'driverWalletMinPaise' => $this->driverWalletMinPaise(),
            'driverWalletMinFarePercent' => $this->driverWalletMinFarePercent(),
            'driverWalletMustCoverCommission' => $this->driverWalletMustCoverCommission(),
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
        if (isset($input['driverWalletMinPaise']) || isset($input['driverWalletMinRupees'])) {
            $paise = isset($input['driverWalletMinPaise'])
                ? (int) $input['driverWalletMinPaise']
                : (int) round(((float) $input['driverWalletMinRupees']) * 100);
            $this->put(self::WALLET_MIN_PAISE_KEY, (string) max(0, $paise));
        }
        if (isset($input['driverWalletMinFarePercent'])) {
            $this->put(self::WALLET_MIN_FARE_PERCENT_KEY, (string) max(0, min(100, (float) $input['driverWalletMinFarePercent'])));
        }
        if (array_key_exists('driverWalletMustCoverCommission', $input)) {
            $this->put(self::WALLET_COVER_COMMISSION_KEY, $input['driverWalletMustCoverCommission'] ? '1' : '0');
        }

        return $this->present();
    }

    public function ensureDefaults(): void
    {
        if ($this->get(self::RADIUS_KEY) === null) {
            $legacy = $this->get(self::LEGACY_RADIUS_KEY);
            $this->put(self::RADIUS_KEY, $legacy !== null && $legacy !== '' ? (string) $legacy : '20');
        }
        if ($this->get(self::TIMEOUT_KEY) === null) {
            $this->put(self::TIMEOUT_KEY, '30');
        }
        if ($this->get(self::WALLET_MIN_PAISE_KEY) === null) {
            $this->put(self::WALLET_MIN_PAISE_KEY, '0');
        }
        if ($this->get(self::WALLET_MIN_FARE_PERCENT_KEY) === null) {
            $this->put(self::WALLET_MIN_FARE_PERCENT_KEY, '0');
        }
        if ($this->get(self::WALLET_COVER_COMMISSION_KEY) === null) {
            $this->put(self::WALLET_COVER_COMMISSION_KEY, '1');
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
