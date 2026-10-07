<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IncentiveService
{
    public function __construct(private readonly RideSettingsService $settings) {}

    public function ensureReferralCode(User $user): string
    {
        if (! Schema::hasColumn('users', 'referral_code')) {
            return '';
        }
        if (! empty($user->referral_code)) {
            return (string) $user->referral_code;
        }
        $prefix = $user->role === 'DRIVER' ? 'KD' : 'KC';
        for ($i = 0; $i < 12; $i++) {
            $code = $prefix.strtoupper(Str::random(6));
            if (! User::query()->where('referral_code', $code)->exists()) {
                User::query()->where('id', $user->id)->update(['referral_code' => $code]);
                $user->referral_code = $code;

                return $code;
            }
        }

        return '';
    }

    public function attachReferralCode(User $user, ?string $code): void
    {
        if (! Schema::hasColumn('users', 'referred_by_user_id')) {
            return;
        }
        if (! empty($user->referred_by_user_id)) {
            return;
        }
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return;
        }
        $referrer = User::query()->where('referral_code', $code)->first();
        if (! $referrer || (int) $referrer->id === (int) $user->id) {
            return;
        }
        if ($referrer->role !== $user->role) {
            return;
        }
        if ($user->phone && $referrer->phone && (string) $user->phone === (string) $referrer->phone) {
            return;
        }
        User::query()->where('id', $user->id)->update(['referred_by_user_id' => $referrer->id]);
        $user->referred_by_user_id = $referrer->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $actor): array
    {
        $this->ensureReferralCode($actor);
        $actor->refresh();
        $code = (string) ($actor->referral_code ?? '');
        $link = 'https://karnaride.in/?ref='.$code;
        $kindPrefix = $actor->role === 'DRIVER' ? 'driver' : 'customer';
        $referred = [];
        if (Schema::hasColumn('users', 'referred_by_user_id')) {
            $referred = User::query()
                ->where('referred_by_user_id', $actor->id)
                ->orderByDesc('id')
                ->limit(80)
                ->get()
                ->map(fn (User $row) => $this->presentReferral($actor, $row))
                ->all();
        }
        $mine = Schema::hasTable('incentives')
            ? DB::table('incentives')->where('user_id', $actor->id)->orderByDesc('id')->limit(40)->get()->map(fn ($row) => [
                'id' => (string) $row->id,
                'kind' => $row->kind,
                'status' => $row->status,
                'amountRupees' => ((int) $row->amount_paise) / 100,
                'note' => $row->note,
                'createdAt' => $row->created_at,
            ])->all()
            : [];
        $joiningPaid = collect($mine)->contains(fn ($row) => str_contains((string) $row['kind'], 'joining') && $row['status'] === 'paid');
        $wallet = $this->walletRow((int) $actor->id, $actor->role === 'DRIVER' ? 'DRIVER' : 'CUSTOMER');

        return [
            'referralCode' => $code,
            'shareLink' => $link,
            'role' => $actor->role,
            'joiningBonusRupees' => $actor->role === 'DRIVER' ? $this->settings->driverWelcomeBonusRupees() : $this->settings->customerJoiningCreditRupees(),
            'referralBonusRupees' => $actor->role === 'DRIVER' ? $this->settings->driverReferralBonusRupees() : $this->settings->customerReferralCreditRupees(),
            'driverReferralRequiredRides' => $this->settings->driverReferralRequiredRides(),
            'joiningStatus' => $joiningPaid ? 'paid' : 'pending_activity',
            'promoBalancePaise' => (int) ($wallet->promo_balance_paise ?? 0),
            'promoBalanceRupees' => ((int) ($wallet->promo_balance_paise ?? 0)) / 100,
            'walletBalanceRupees' => ((int) ($wallet->balance_paise ?? 0)) / 100,
            'referred' => $referred,
            'history' => $mine,
            'policy' => [
                'driverJoining' => '₹'.$this->settings->driverWelcomeBonusRupees().' after KYC, vehicle verification, approval and 1 completed ride.',
                'driverReferral' => '₹'.$this->settings->driverReferralBonusRupees().' after the referred driver completes '.$this->settings->driverReferralRequiredRides().' rides.',
                'customerJoining' => '₹'.$this->settings->customerJoiningCreditRupees().' ride credit after the first successful paid ride. Not withdrawable.',
                'customerReferral' => '₹'.$this->settings->customerReferralCreditRupees().' ride credit to you when a friend completes their first paid ride.',
                'promoCap' => 'Promotional credit is capped at ₹'.$this->settings->customerPromoMaxRupees().' or '.$this->settings->customerPromoMaxFarePercent().'% of fare per ride.',
            ],
            'kindPrefix' => $kindPrefix,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentReferral(User $actor, User $row): array
    {
        $rides = 0;
        if ($row->role === 'DRIVER') {
            $driver = Driver::query()->where('user_id', $row->id)->first();
            $rides = $driver ? $this->completedDriverRides((int) $driver->id) : 0;
        } else {
            $rides = $this->completedCustomerPaidRides((int) $row->id);
        }
        $needed = $actor->role === 'DRIVER' ? $this->settings->driverReferralRequiredRides() : 1;
        $paid = Schema::hasTable('incentives') && DB::table('incentives')
            ->where('user_id', $actor->id)
            ->where('related_user_id', $row->id)
            ->where('kind', $actor->role === 'DRIVER' ? 'driver_referral' : 'customer_referral')
            ->where('status', 'paid')
            ->exists();

        return [
            'id' => (string) $row->id,
            'name' => $row->name,
            'phone' => $row->phone,
            'status' => $paid ? 'bonus_paid' : ($rides >= $needed ? 'eligible' : 'in_progress'),
            'completedRides' => $rides,
            'requiredRides' => $needed,
        ];
    }

    public function onBookingCompleted(Booking $booking): void
    {
        $this->settlePromo($booking);
        $driver = $booking->driver_id ? Driver::query()->find($booking->driver_id) : null;
        if ($driver) {
            $this->maybeGrantDriverJoining($driver, (int) $booking->id);
            $this->maybeGrantDriverReferral($driver, (int) $booking->id);
        }
        $customer = User::query()->find($booking->customer_id);
        if ($customer && $this->isPaidCompleted($booking)) {
            $this->maybeGrantCustomerJoining($customer, (int) $booking->id);
            $this->maybeGrantCustomerReferral($customer, (int) $booking->id);
        }
    }

    public function quotePromoDiscount(int $customerId, int $farePaise): int
    {
        if ($farePaise <= 0 || $this->settings->firstRideFreeEnabled()) {
            return 0;
        }
        $wallet = $this->walletRow($customerId, 'CUSTOMER');
        $promo = (int) ($wallet->promo_balance_paise ?? 0);
        if ($promo < 1) {
            return 0;
        }
        $cap = min($promo, $this->settings->customerPromoMaxRupees() * 100);
        $byFare = (int) floor($farePaise * ($this->settings->customerPromoMaxFarePercent() / 100));
        $applied = min($cap, $byFare, $farePaise);

        return max(0, $applied);
    }

    public function settlePromo(Booking $booking): void
    {
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $live = is_array($snapshot['live'] ?? null) ? $snapshot['live'] : $snapshot;
        $applied = (int) ($live['promoCreditPaise'] ?? $snapshot['promoCreditPaise'] ?? 0);
        if ($applied < 1 || ! Schema::hasColumn('wallets', 'promo_balance_paise')) {
            return;
        }
        $wallet = $this->walletRow((int) $booking->customer_id, 'CUSTOMER');
        if (! $wallet) {
            return;
        }
        if (Schema::hasTable('wallet_ledger') && Schema::hasColumn('wallet_ledger', 'booking_id')) {
            $exists = DB::table('wallet_ledger')
                ->where('wallet_id', $wallet->id)
                ->where('booking_id', $booking->id)
                ->where('kind', 'promo_ride')
                ->exists();
            if ($exists) {
                return;
            }
        }
        $promo = (int) ($wallet->promo_balance_paise ?? 0);
        $use = min($promo, $applied);
        if ($use < 1) {
            return;
        }
        DB::table('wallets')->where('id', $wallet->id)->update([
            'promo_balance_paise' => $promo - $use,
            'updated_at' => now(),
        ]);
        $this->ledger((int) $wallet->id, (int) $booking->customer_id, 'CUSTOMER', 'debit', $use, (int) ($wallet->balance_paise ?? 0), (int) ($wallet->balance_paise ?? 0), 'promo_ride', (int) $booking->id, 'Promotional ride credit');
    }

    public function driverProgramCards(User $actor): array
    {
        $snap = $this->snapshot($actor);
        $driver = Driver::query()->where('user_id', $actor->id)->first();
        $rides = $driver ? $this->completedDriverRides((int) $driver->id) : 0;
        $joinPaid = $snap['joiningStatus'] === 'paid';

        return [
            [
                'title' => 'Joining bonus ₹'.$snap['joiningBonusRupees'],
                'subtitle' => $joinPaid ? 'Credited after your first completed ride' : 'Complete KYC, vehicle verification, then 1 ride',
                'progressTrips' => min(1, $rides),
                'targetTrips' => 1,
                'progress' => $joinPaid ? 1 : min(1, $rides),
                'bonusRupees' => $snap['joiningBonusRupees'],
                'awarded' => $joinPaid,
                'earnedRupees' => $joinPaid ? $snap['joiningBonusRupees'] : 0,
            ],
            [
                'title' => 'Refer a driver ₹'.$snap['referralBonusRupees'],
                'subtitle' => 'Paid when they complete '.$snap['driverReferralRequiredRides'].' rides. Code '.$snap['referralCode'],
                'progressTrips' => collect($snap['referred'])->where('status', 'bonus_paid')->count(),
                'targetTrips' => max(1, count($snap['referred'])),
                'progress' => 0,
                'bonusRupees' => $snap['referralBonusRupees'],
                'awarded' => false,
                'earnedRupees' => collect($snap['history'])->where('kind', 'driver_referral')->sum('amountRupees'),
            ],
        ];
    }

    private function maybeGrantDriverJoining(Driver $driver, int $bookingId): void
    {
        if (! $this->settings->driverWelcomeBonusEnabled()) {
            return;
        }
        $rupees = $this->settings->driverWelcomeBonusRupees();
        if ($rupees < 1) {
            return;
        }
        if (Schema::hasColumn('drivers', 'welcome_bonus_at') && $driver->welcome_bonus_at) {
            return;
        }
        if ($this->alreadyPaid((int) $driver->user_id, 'driver_joining')) {
            return;
        }
        $user = User::query()->find($driver->user_id);
        if (! $user || strtoupper((string) $user->status) !== 'ACTIVE') {
            return;
        }
        $kyc = strtolower((string) ($driver->kyc_status ?? ''));
        if (! in_array($kyc, ['approved', 'verified', 'active'], true)) {
            return;
        }
        $hasVehicle = Schema::hasTable('vehicles') && DB::table('vehicles')->where('driver_id', $driver->id)->exists();
        if (! $hasVehicle) {
            return;
        }
        if ($this->completedDriverRides((int) $driver->id) < 1) {
            return;
        }
        $paise = $rupees * 100;
        $this->creditCash((int) $driver->user_id, 'DRIVER', $paise, 'welcome_bonus', $bookingId, 'Driver joining bonus');
        $this->recordIncentive((int) $driver->user_id, 'driver_joining', $paise, $bookingId, null, 'Driver joining bonus after first completed ride');
        $patch = [];
        if (Schema::hasColumn('drivers', 'welcome_bonus_paise')) {
            $patch['welcome_bonus_paise'] = $paise;
        }
        if (Schema::hasColumn('drivers', 'welcome_bonus_at')) {
            $patch['welcome_bonus_at'] = now();
        }
        if ($patch !== []) {
            $driver->update($patch);
        }
    }

    private function maybeGrantDriverReferral(Driver $newDriver, int $bookingId): void
    {
        if (! Schema::hasColumn('users', 'referred_by_user_id')) {
            return;
        }
        $newUser = User::query()->find($newDriver->user_id);
        $referrerId = (int) ($newUser->referred_by_user_id ?? 0);
        if ($referrerId < 1 || $referrerId === (int) $newUser->id) {
            return;
        }
        $needed = $this->settings->driverReferralRequiredRides();
        if ($this->completedDriverRides((int) $newDriver->id) < $needed) {
            return;
        }
        if ($this->alreadyPaid($referrerId, 'driver_referral', (int) $newUser->id)) {
            return;
        }
        $rupees = $this->settings->driverReferralBonusRupees();
        if ($rupees < 1) {
            return;
        }
        $paise = $rupees * 100;
        $this->creditCash($referrerId, 'DRIVER', $paise, 'driver_referral', $bookingId, 'Driver referral bonus');
        $this->recordIncentive($referrerId, 'driver_referral', $paise, $bookingId, (int) $newUser->id, 'Referral bonus after 10 completed rides');
    }

    private function maybeGrantCustomerJoining(User $customer, int $bookingId): void
    {
        $rupees = $this->settings->customerJoiningCreditRupees();
        if ($rupees < 1 || $this->alreadyPaid((int) $customer->id, 'customer_joining')) {
            return;
        }
        if ($this->completedCustomerPaidRides((int) $customer->id) < 1) {
            return;
        }
        $paise = $rupees * 100;
        $this->creditPromo((int) $customer->id, $paise, $bookingId, 'Customer joining ride credit');
        $this->recordIncentive((int) $customer->id, 'customer_joining', $paise, $bookingId, null, 'Joining ride credit after first paid ride');
    }

    private function maybeGrantCustomerReferral(User $referred, int $bookingId): void
    {
        $referrerId = (int) ($referred->referred_by_user_id ?? 0);
        if ($referrerId < 1) {
            return;
        }
        if ($this->alreadyPaid($referrerId, 'customer_referral', (int) $referred->id)) {
            return;
        }
        $rupees = $this->settings->customerReferralCreditRupees();
        if ($rupees < 1) {
            return;
        }
        $paise = $rupees * 100;
        $this->creditPromo($referrerId, $paise, $bookingId, 'Customer referral ride credit');
        $this->recordIncentive($referrerId, 'customer_referral', $paise, $bookingId, (int) $referred->id, 'Referral credit after friend’s first paid ride');
    }

    private function isPaidCompleted(Booking $booking): bool
    {
        $fare = (int) ($booking->final_fare_paise ?? $booking->quote_paise ?? 0);
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $live = is_array($snapshot['live'] ?? null) ? $snapshot['live'] : $snapshot;
        if (! empty($live['firstRideFree'])) {
            return false;
        }

        return $fare > 0;
    }

    private function completedDriverRides(int $driverId): int
    {
        return Booking::query()->where('driver_id', $driverId)->whereIn('status', ['COMPLETED', 'completed'])->count();
    }

    private function completedCustomerPaidRides(int $customerId): int
    {
        return Booking::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['COMPLETED', 'completed'])
            ->where(function ($q) {
                $q->where('final_fare_paise', '>', 0)->orWhere('quote_paise', '>', 0);
            })
            ->count();
    }

    private function alreadyPaid(int $userId, string $kind, ?int $related = null): bool
    {
        if (! Schema::hasTable('incentives')) {
            return false;
        }
        $q = DB::table('incentives')->where('user_id', $userId)->where('kind', $kind)->where('status', 'paid');
        if ($related) {
            $q->where('related_user_id', $related);
        }

        return $q->exists();
    }

    private function recordIncentive(int $userId, string $kind, int $paise, ?int $bookingId, ?int $related, string $note): void
    {
        if (! Schema::hasTable('incentives')) {
            return;
        }
        DB::table('incentives')->insert(array_filter([
            'user_id' => $userId,
            'kind' => $kind,
            'amount_paise' => $paise,
            'status' => 'paid',
            'related_user_id' => $related,
            'booking_id' => $bookingId,
            'note' => $note,
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($key) => Schema::hasColumn('incentives', $key), ARRAY_FILTER_USE_KEY));
    }

    private function creditCash(int $userId, string $ownerType, int $paise, string $kind, ?int $bookingId, string $note): void
    {
        $wallet = $this->ensureWallet($userId, $ownerType);
        if (! $wallet) {
            return;
        }
        $before = (int) $wallet->balance_paise;
        $after = $before + $paise;
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_paise' => $after, 'updated_at' => now()]);
        $this->ledger((int) $wallet->id, $userId, $ownerType, 'credit', $paise, $before, $after, $kind, $bookingId, $note);
    }

    private function creditPromo(int $userId, int $paise, ?int $bookingId, string $note): void
    {
        $wallet = $this->ensureWallet($userId, 'CUSTOMER');
        if (! $wallet) {
            return;
        }
        $promo = (int) ($wallet->promo_balance_paise ?? 0);
        $update = ['updated_at' => now()];
        if (Schema::hasColumn('wallets', 'promo_balance_paise')) {
            $update['promo_balance_paise'] = $promo + $paise;
        } else {
            $before = (int) $wallet->balance_paise;
            $update['balance_paise'] = $before + $paise;
            $this->ledger((int) $wallet->id, $userId, 'CUSTOMER', 'credit', $paise, $before, $before + $paise, 'promo_credit', $bookingId, $note);

            return;
        }
        DB::table('wallets')->where('id', $wallet->id)->update($update);
        $this->ledger((int) $wallet->id, $userId, 'CUSTOMER', 'credit', $paise, (int) $wallet->balance_paise, (int) $wallet->balance_paise, 'promo_credit', $bookingId, $note);
    }

    private function ledger(int $walletId, int $userId, string $account, string $direction, int $paise, int $before, int $after, string $kind, ?int $bookingId, string $note): void
    {
        if (! Schema::hasTable('wallet_ledger')) {
            return;
        }
        DB::table('wallet_ledger')->insert(array_filter([
            'public_ref' => strtoupper(substr($kind, 0, 2)).strtoupper(Str::random(8)),
            'wallet_id' => $walletId,
            'booking_id' => $bookingId,
            'owner_user_id' => $userId,
            'account' => $account,
            'direction' => $direction,
            'amount_paise' => $paise,
            'balance_before_paise' => $before,
            'balance_after_paise' => $after,
            'kind' => $kind,
            'status' => 'posted',
            'note' => $note,
            'created_at' => now(),
        ], fn ($key) => Schema::hasColumn('wallet_ledger', $key), ARRAY_FILTER_USE_KEY));
    }

    private function walletRow(int $userId, string $ownerType): ?object
    {
        if (! Schema::hasTable('wallets')) {
            return null;
        }
        $q = DB::table('wallets')->where('owner_user_id', $userId);
        if (Schema::hasColumn('wallets', 'owner_type')) {
            $q->where(function ($inner) use ($ownerType) {
                $inner->where('owner_type', $ownerType)->orWhereNull('owner_type');
            });
        }

        return $q->orderByDesc('id')->first();
    }

    private function ensureWallet(int $userId, string $ownerType): ?object
    {
        $row = $this->walletRow($userId, $ownerType);
        if ($row) {
            return $row;
        }
        if (! Schema::hasTable('wallets')) {
            return null;
        }
        $id = DB::table('wallets')->insertGetId(array_filter([
            'owner_user_id' => $userId,
            'owner_type' => $ownerType,
            'balance_paise' => 0,
            'promo_balance_paise' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($key) => Schema::hasColumn('wallets', $key), ARRAY_FILTER_USE_KEY));

        return DB::table('wallets')->where('id', $id)->first();
    }
}
