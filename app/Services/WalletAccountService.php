<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WalletAccountService
{
    public function ensureSchema(): void
    {
        if (! Schema::hasTable('wallets')) {
            return;
        }
        $missing = [];
        foreach (['joining_credit_limit_paise', 'joining_credit_paise', 'joining_credit_used_paise', 'frozen_paise'] as $column) {
            if (! Schema::hasColumn('wallets', $column)) {
                $missing[] = $column;
            }
        }
        if ($missing !== []) {
            Schema::table('wallets', function ($table) use ($missing) {
                if (in_array('joining_credit_limit_paise', $missing, true)) {
                    $table->unsignedBigInteger('joining_credit_limit_paise')->default(0);
                }
                if (in_array('joining_credit_paise', $missing, true)) {
                    $table->unsignedBigInteger('joining_credit_paise')->default(0);
                }
                if (in_array('joining_credit_used_paise', $missing, true)) {
                    $table->unsignedBigInteger('joining_credit_used_paise')->default(0);
                }
                if (in_array('frozen_paise', $missing, true)) {
                    $table->unsignedBigInteger('frozen_paise')->default(0);
                }
            });
        }
        if (! Schema::hasTable('wallet_withdrawals')) {
            Schema::create('wallet_withdrawals', function ($table) {
                $table->id();
                $table->string('public_ref', 24)->unique();
                $table->unsignedBigInteger('wallet_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('amount_paise');
                $table->string('status', 24)->default('requested');
                $table->string('upi_or_account', 120)->nullable();
                $table->string('transfer_ref', 64)->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function ensureWallet(int $userId, string $ownerType): ?object
    {
        $this->ensureSchema();
        if (! Schema::hasTable('wallets')) {
            return null;
        }
        $q = DB::table('wallets')->where('owner_user_id', $userId);
        if (Schema::hasColumn('wallets', 'owner_type')) {
            $q->where(function ($inner) use ($ownerType) {
                $inner->where('owner_type', $ownerType)->orWhereNull('owner_type');
            });
        }
        $wallet = $q->orderByDesc('id')->first();
        if ($wallet) {
            return $wallet;
        }
        $id = DB::table('wallets')->insertGetId($this->filter('wallets', [
            'owner_user_id' => $userId,
            'owner_type' => $ownerType,
            'balance_paise' => 0,
            'joining_credit_limit_paise' => 0,
            'joining_credit_paise' => 0,
            'joining_credit_used_paise' => 0,
            'frozen_paise' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return DB::table('wallets')->where('id', $id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?object $wallet, string $ownerType = 'CUSTOMER'): array
    {
        $balance = (int) ($wallet->balance_paise ?? 0);
        $joining = (int) ($wallet->joining_credit_paise ?? 0);
        $limit = (int) ($wallet->joining_credit_limit_paise ?? 0);
        $used = (int) ($wallet->joining_credit_used_paise ?? 0);
        $frozen = (int) ($wallet->frozen_paise ?? 0);
        $available = max(0, $balance - $frozen);

        return [
            'balancePaise' => $balance,
            'balanceRupees' => $balance / 100,
            'joiningCreditPaise' => $joining,
            'joiningCreditRupees' => $joining / 100,
            'joiningCreditLimitPaise' => $limit,
            'joiningCreditLimitRupees' => $limit / 100,
            'joiningCreditUsedPaise' => $used,
            'joiningCreditUsedRupees' => $used / 100,
            'frozenPaise' => $frozen,
            'frozenRupees' => $frozen / 100,
            'availablePaise' => $available,
            'availableRupees' => $available / 100,
            'ownerType' => $wallet->owner_type ?? $ownerType,
        ];
    }

    public function grantJoiningCredit(int $userId, string $ownerType, int $paise, string $note = 'KarnaRide joining credit'): bool
    {
        if ($paise < 1) {
            return false;
        }
        $wallet = $this->ensureWallet($userId, $ownerType);
        if (! $wallet) {
            return false;
        }
        $remaining = (int) ($wallet->joining_credit_paise ?? 0);
        $limit = (int) ($wallet->joining_credit_limit_paise ?? 0);
        $newRemaining = $remaining + $paise;
        $newLimit = max($limit, $newRemaining);
        DB::table('wallets')->where('id', $wallet->id)->update($this->filter('wallets', [
            'joining_credit_paise' => $newRemaining,
            'joining_credit_limit_paise' => $newLimit,
            'updated_at' => now(),
        ]));
        $this->ledger($wallet, $userId, $ownerType, 'CREDIT', $paise, (int) $wallet->balance_paise, (int) $wallet->balance_paise, 'joining_credit', $note);

        return true;
    }

    public function creditBonus(int $userId, string $ownerType, int $paise, string $kind = 'bonus', string $note = 'Wallet bonus'): bool
    {
        if ($paise < 1) {
            return false;
        }
        $wallet = $this->ensureWallet($userId, $ownerType);
        if (! $wallet) {
            return false;
        }
        if (Schema::hasTable('wallet_ledger')) {
            $exists = DB::table('wallet_ledger')
                ->where('wallet_id', $wallet->id)
                ->whereIn('kind', [$kind, 'joining_bonus', 'driver_joining', 'bonus'])
                ->where(function ($q) {
                    $q->where('direction', 'CREDIT')->orWhere('direction', 'credit');
                })
                ->exists();
            if ($exists) {
                return false;
            }
        }
        $before = (int) ($wallet->balance_paise ?? 0);
        $after = $before + $paise;
        DB::table('wallets')->where('id', $wallet->id)->update($this->filter('wallets', [
            'balance_paise' => $after,
            'updated_at' => now(),
        ]));
        $fresh = DB::table('wallets')->where('id', $wallet->id)->first() ?? $wallet;
        $this->ledger($fresh, $userId, $ownerType, 'CREDIT', $paise, $before, $after, $kind, $note);

        return true;
    }

    public function debitCommission(int $userId, int $bookingId, int $commissionPaise, int $grossPaise): void
    {
        if ($commissionPaise <= 0) {
            return;
        }
        $wallet = $this->ensureWallet($userId, 'DRIVER');
        if (! $wallet) {
            return;
        }
        if (Schema::hasTable('wallet_ledger')) {
            $exists = DB::table('wallet_ledger')->where('booking_id', $bookingId)->where('wallet_id', $wallet->id)->where('kind', 'commission')->exists();
            if ($exists) {
                return;
            }
        }
        $joining = (int) ($wallet->joining_credit_paise ?? 0);
        $used = (int) ($wallet->joining_credit_used_paise ?? 0);
        $fromCredit = min($joining, $commissionPaise);
        $fromCash = $commissionPaise - $fromCredit;
        $before = (int) $wallet->balance_paise;
        $after = $before - $fromCash;
        $update = [
            'balance_paise' => $after,
            'updated_at' => now(),
        ];
        if ($fromCredit > 0) {
            $update['joining_credit_paise'] = $joining - $fromCredit;
            $update['joining_credit_used_paise'] = $used + $fromCredit;
        }
        DB::table('wallets')->where('id', $wallet->id)->update($this->filter('wallets', $update));
        $note = $fromCredit > 0
            ? 'Trip commission ₹'.number_format($commissionPaise / 100, 0).' (₹'.number_format($fromCredit / 100, 0).' from joining credit)'
            : 'Admin commission on cash trip';
        $this->ledger($wallet, $userId, 'DRIVER', 'DEBIT', $commissionPaise, $before, $after, 'commission', $note, $bookingId, $commissionPaise, $grossPaise);
    }

    /**
     * @return array<string, mixed>
     */
    public function requestWithdraw(User $actor, float $rupees, ?string $destination = null): array
    {
        abort_unless($actor->role === 'DRIVER', 403, 'Only drivers can withdraw wallet cash.');
        $paise = (int) round($rupees * 100);
        abort_unless($paise >= 50000, 422, 'Minimum withdrawal is ₹500.');
        $wallet = $this->ensureWallet((int) $actor->id, 'DRIVER');
        abort_unless($wallet, 422, 'Wallet not found.');
        $available = max(0, (int) $wallet->balance_paise - (int) ($wallet->frozen_paise ?? 0));
        abort_unless($available >= $paise, 422, 'Not enough withdrawable cash. Joining credit cannot be withdrawn.');
        $frozen = (int) ($wallet->frozen_paise ?? 0) + $paise;
        DB::table('wallets')->where('id', $wallet->id)->update($this->filter('wallets', [
            'frozen_paise' => $frozen,
            'updated_at' => now(),
        ]));
        $id = DB::table('wallet_withdrawals')->insertGetId([
            'public_ref' => 'WW'.strtoupper(Str::random(10)),
            'wallet_id' => $wallet->id,
            'user_id' => $actor->id,
            'amount_paise' => $paise,
            'status' => 'requested',
            'upi_or_account' => $destination,
            'note' => 'Driver withdrawal requested. Amount frozen until admin settles.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $this->presentWithdrawal(DB::table('wallet_withdrawals')->where('id', $id)->first());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function withdrawalsFor(int $userId): array
    {
        $this->ensureSchema();
        if (! Schema::hasTable('wallet_withdrawals')) {
            return [];
        }

        return DB::table('wallet_withdrawals')->where('user_id', $userId)->orderByDesc('id')->limit(40)->get()->map(fn ($row) => $this->presentWithdrawal($row))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentWithdrawal(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'publicRef' => $row->public_ref,
            'amountPaise' => (int) $row->amount_paise,
            'amountRupees' => ((int) $row->amount_paise) / 100,
            'status' => $row->status,
            'transferRef' => $row->transfer_ref,
            'note' => $row->note,
            'createdAt' => $row->created_at,
            'settledAt' => $row->settled_at ?? null,
        ];
    }

    public function recordCashTrip(int $userId, int $bookingId, int $earningPaise, int $commissionPaise, int $grossPaise, string $note = 'Cash trip collected'): void
    {
        if ($earningPaise <= 0 && $grossPaise <= 0) {
            return;
        }
        $wallet = $this->ensureWallet($userId, 'DRIVER');
        if (! $wallet || ! Schema::hasTable('wallet_ledger')) {
            return;
        }
        $exists = DB::table('wallet_ledger')
            ->where('booking_id', $bookingId)
            ->where('wallet_id', $wallet->id)
            ->whereIn('kind', ['cash_trip', 'cash'])
            ->exists();
        if ($exists) {
            return;
        }
        $balance = (int) $wallet->balance_paise;
        $this->ledger(
            $wallet,
            $userId,
            'DRIVER',
            'CREDIT',
            max(0, $earningPaise),
            $balance,
            $balance,
            'cash_trip',
            $note,
            $bookingId,
            $commissionPaise,
            $grossPaise,
        );
    }

    public function ledger(
        object $wallet,
        int $userId,
        string $account,
        string $direction,
        int $paise,
        int $before,
        int $after,
        string $kind,
        string $note,
        ?int $bookingId = null,
        int $commissionPaise = 0,
        int $grossPaise = 0,
    ): void {
        if ($paise <= 0 && $kind !== 'withdraw_hold') {
            return;
        }
        if (! Schema::hasTable('wallet_ledger')) {
            return;
        }
        DB::table('wallet_ledger')->insert($this->filter('wallet_ledger', [
            'public_ref' => strtoupper(substr($kind, 0, 2)).strtoupper(Str::random(10)),
            'wallet_id' => $wallet->id,
            'booking_id' => $bookingId,
            'owner_user_id' => $userId,
            'account' => $account,
            'direction' => strtoupper($direction) === 'DEBIT' ? 'DEBIT' : 'CREDIT',
            'amount_paise' => max(0, $paise),
            'commission_paise' => $commissionPaise,
            'gross_paise' => $grossPaise ?: $paise,
            'balance_before_paise' => $before,
            'balance_after_paise' => $after,
            'kind' => $kind,
            'status' => 'posted',
            'note' => $note,
            'created_at' => now(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function filter(string $table, array $row): array
    {
        if (! Schema::hasTable($table)) {
            return $row;
        }

        return array_filter($row, fn ($key) => Schema::hasColumn($table, $key), ARRAY_FILTER_USE_KEY);
    }
}
