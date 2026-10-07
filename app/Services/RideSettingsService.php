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
    public const DRIVER_WELCOME_BONUS_ON = 'driver_welcome_bonus_enabled';
    public const DRIVER_WELCOME_BONUS_RUPEES = 'driver_welcome_bonus_rupees';
    public const FIRST_RIDE_FREE_ON = 'customer_first_ride_free_enabled';
    public const DRIVER_REFERRAL_RUPEES = 'driver_referral_bonus_rupees';
    public const DRIVER_REFERRAL_RIDES = 'driver_referral_required_rides';
    public const CUSTOMER_JOINING_RUPEES = 'customer_joining_credit_rupees';
    public const CUSTOMER_REFERRAL_RUPEES = 'customer_referral_credit_rupees';
    public const CUSTOMER_PROMO_MAX_RUPEES = 'customer_promo_max_rupees';
    public const CUSTOMER_PROMO_MAX_PERCENT = 'customer_promo_max_fare_percent';

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
        $seconds = is_numeric($value) ? (int) $value : 600;

        return max(10, min(900, $seconds));
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

    public function driverWelcomeBonusEnabled(): bool
    {
        $value = $this->get(self::DRIVER_WELCOME_BONUS_ON);

        return $value === null || $value === '' ? true : in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }

    public function driverWelcomeBonusRupees(): int
    {
        $value = $this->get(self::DRIVER_WELCOME_BONUS_RUPEES);
        $rupees = is_numeric($value) ? (int) $value : 100;

        return max(0, min(100000, $rupees));
    }

    public function firstRideFreeEnabled(): bool
    {
        $value = $this->get(self::FIRST_RIDE_FREE_ON);

        return $value === null || $value === '' ? false : in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }

    public function driverReferralBonusRupees(): int
    {
        $value = $this->get(self::DRIVER_REFERRAL_RUPEES);
        $rupees = is_numeric($value) ? (int) $value : 150;

        return max(0, min(100000, $rupees));
    }

    public function driverReferralRequiredRides(): int
    {
        $value = $this->get(self::DRIVER_REFERRAL_RIDES);
        $rides = is_numeric($value) ? (int) $value : 10;

        return max(1, min(500, $rides));
    }

    public function customerJoiningCreditRupees(): int
    {
        $value = $this->get(self::CUSTOMER_JOINING_RUPEES);
        $rupees = is_numeric($value) ? (int) $value : 50;

        return max(0, min(100000, $rupees));
    }

    public function customerReferralCreditRupees(): int
    {
        $value = $this->get(self::CUSTOMER_REFERRAL_RUPEES);
        $rupees = is_numeric($value) ? (int) $value : 50;

        return max(0, min(100000, $rupees));
    }

    public function customerPromoMaxRupees(): int
    {
        $value = $this->get(self::CUSTOMER_PROMO_MAX_RUPEES);
        $rupees = is_numeric($value) ? (int) $value : 50;

        return max(0, min(100000, $rupees));
    }

    public function customerPromoMaxFarePercent(): float
    {
        $value = $this->get(self::CUSTOMER_PROMO_MAX_PERCENT);
        $pct = is_numeric($value) ? (float) $value : 50;

        return max(0, min(100, $pct));
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
            'driverWelcomeBonusEnabled' => $this->driverWelcomeBonusEnabled(),
            'driverWelcomeBonusRupees' => $this->driverWelcomeBonusRupees(),
            'customerFirstRideFreeEnabled' => $this->firstRideFreeEnabled(),
            'driverReferralBonusRupees' => $this->driverReferralBonusRupees(),
            'driverReferralRequiredRides' => $this->driverReferralRequiredRides(),
            'customerJoiningCreditRupees' => $this->customerJoiningCreditRupees(),
            'customerReferralCreditRupees' => $this->customerReferralCreditRupees(),
            'customerPromoMaxRupees' => $this->customerPromoMaxRupees(),
            'customerPromoMaxFarePercent' => $this->customerPromoMaxFarePercent(),
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
            $this->put(self::TIMEOUT_KEY, (string) max(10, min(900, $seconds)));
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
        if (array_key_exists('driverWelcomeBonusEnabled', $input)) {
            $this->put(self::DRIVER_WELCOME_BONUS_ON, $input['driverWelcomeBonusEnabled'] ? '1' : '0');
        }
        if (isset($input['driverWelcomeBonusRupees'])) {
            $this->put(self::DRIVER_WELCOME_BONUS_RUPEES, (string) max(0, min(100000, (int) $input['driverWelcomeBonusRupees'])));
        }
        if (array_key_exists('customerFirstRideFreeEnabled', $input)) {
            $this->put(self::FIRST_RIDE_FREE_ON, $input['customerFirstRideFreeEnabled'] ? '1' : '0');
        }
        if (isset($input['driverReferralBonusRupees'])) {
            $this->put(self::DRIVER_REFERRAL_RUPEES, (string) max(0, min(100000, (int) $input['driverReferralBonusRupees'])));
        }
        if (isset($input['driverReferralRequiredRides'])) {
            $this->put(self::DRIVER_REFERRAL_RIDES, (string) max(1, min(500, (int) $input['driverReferralRequiredRides'])));
        }
        if (isset($input['customerJoiningCreditRupees'])) {
            $this->put(self::CUSTOMER_JOINING_RUPEES, (string) max(0, min(100000, (int) $input['customerJoiningCreditRupees'])));
        }
        if (isset($input['customerReferralCreditRupees'])) {
            $this->put(self::CUSTOMER_REFERRAL_RUPEES, (string) max(0, min(100000, (int) $input['customerReferralCreditRupees'])));
        }
        if (isset($input['customerPromoMaxRupees'])) {
            $this->put(self::CUSTOMER_PROMO_MAX_RUPEES, (string) max(0, min(100000, (int) $input['customerPromoMaxRupees'])));
        }
        if (isset($input['customerPromoMaxFarePercent'])) {
            $this->put(self::CUSTOMER_PROMO_MAX_PERCENT, (string) max(0, min(100, (float) $input['customerPromoMaxFarePercent'])));
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
            $this->put(self::TIMEOUT_KEY, '600');
        }
        if ($this->get(self::WALLET_MIN_PAISE_KEY) === null) {
            $this->put(self::WALLET_MIN_PAISE_KEY, '0');
        }
        $percent = $this->get(self::WALLET_MIN_FARE_PERCENT_KEY);
        if ($percent === null || $percent === '' || (float) $percent <= 0) {
            $this->put(self::WALLET_MIN_FARE_PERCENT_KEY, '10');
        }
        if ($this->get(self::WALLET_COVER_COMMISSION_KEY) === null) {
            $this->put(self::WALLET_COVER_COMMISSION_KEY, '1');
        }
        if ($this->get(self::DRIVER_WELCOME_BONUS_ON) === null) {
            $this->put(self::DRIVER_WELCOME_BONUS_ON, '1');
        }
        if ($this->get(self::DRIVER_WELCOME_BONUS_RUPEES) === null) {
            $this->put(self::DRIVER_WELCOME_BONUS_RUPEES, '100');
        }
        if ($this->get(self::FIRST_RIDE_FREE_ON) === null) {
            $this->put(self::FIRST_RIDE_FREE_ON, '0');
        }
        if ($this->get(self::DRIVER_REFERRAL_RUPEES) === null) {
            $this->put(self::DRIVER_REFERRAL_RUPEES, '150');
        }
        if ($this->get(self::DRIVER_REFERRAL_RIDES) === null) {
            $this->put(self::DRIVER_REFERRAL_RIDES, '10');
        }
        if ($this->get(self::CUSTOMER_JOINING_RUPEES) === null) {
            $this->put(self::CUSTOMER_JOINING_RUPEES, '50');
        }
        if ($this->get(self::CUSTOMER_REFERRAL_RUPEES) === null) {
            $this->put(self::CUSTOMER_REFERRAL_RUPEES, '50');
        }
        if ($this->get(self::CUSTOMER_PROMO_MAX_RUPEES) === null) {
            $this->put(self::CUSTOMER_PROMO_MAX_RUPEES, '50');
        }
        if ($this->get(self::CUSTOMER_PROMO_MAX_PERCENT) === null) {
            $this->put(self::CUSTOMER_PROMO_MAX_PERCENT, '50');
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
