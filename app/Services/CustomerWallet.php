<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CustomerWallet
{
    public function find(int $userId): ?object
    {
        if (! Schema::hasTable('wallets')) {
            return null;
        }
        $query = DB::table('wallets')->where('owner_user_id', $userId);
        if (Schema::hasColumn('wallets', 'owner_type')) {
            $query->where('owner_type', 'CUSTOMER');
        }

        return $query->orderBy('id')->first();
    }

    public function balancePaise(int $userId): int
    {
        return (int) ($this->find($userId)->balance_paise ?? 0);
    }

    public function debit(int $userId, int $amountPaise, string $note, ?int $bookingId = null, string $kind = 'trip'): void
    {
        if ($amountPaise <= 0 || ! Schema::hasTable('wallets')) {
            return;
        }
        $wallet = $this->find($userId);
        abort_unless($wallet, 422, 'Wallet not found');
        if ($bookingId && Schema::hasTable('wallet_ledger') && Schema::hasColumn('wallet_ledger', 'booking_id')) {
            $exists = DB::table('wallet_ledger')
                ->where('wallet_id', $wallet->id)
                ->where('booking_id', $bookingId)
                ->where('direction', 'debit')
                ->exists();
            if ($exists) {
                return;
            }
        }
        $before = (int) $wallet->balance_paise;
        abort_unless($before >= $amountPaise, 422, 'Insufficient wallet balance');
        $after = $before - $amountPaise;
        DB::table('wallets')->where('id', $wallet->id)->update([
            'balance_paise' => $after,
            'updated_at' => now(),
        ]);
        if (! Schema::hasTable('wallet_ledger')) {
            return;
        }
        $row = [
            'public_ref' => 'WD'.strtoupper(Str::random(10)),
            'wallet_id' => $wallet->id,
            'booking_id' => $bookingId,
            'owner_user_id' => $userId,
            'account' => 'CUSTOMER',
            'direction' => 'debit',
            'amount_paise' => $amountPaise,
            'balance_before_paise' => $before,
            'balance_after_paise' => $after,
            'kind' => $kind,
            'status' => 'posted',
            'note' => $note,
            'created_at' => now(),
        ];
        DB::table('wallet_ledger')->insert(array_filter(
            $row,
            fn ($key) => Schema::hasColumn('wallet_ledger', $key),
            ARRAY_FILTER_USE_KEY,
        ));
    }
}
