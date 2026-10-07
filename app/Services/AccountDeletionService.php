<?php

namespace App\Services;

use App\Models\User;
use App\Support\BookingStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class AccountDeletionService
{
    public function deleteCustomer(User $user): array
    {
        abort_unless($user->role === 'CUSTOMER', 422, 'Use the partner app support flow to close a driver or fleet account.');

        $this->assertNoOpenTrips((int) $user->id);

        DB::transaction(function () use ($user) {
            $id = (int) $user->id;
            if (Schema::hasColumn('users', 'session_epoch')) {
                $user->update(['session_epoch' => ((int) ($user->session_epoch ?? 0)) + 1]);
            }

            $this->deleteRows('user_places', 'user_id', $id);
            $this->deleteRows('saved_places', 'user_id', $id);
            $this->deleteRows('device_tokens', 'user_id', $id);
            $this->deleteRows('push_devices', 'user_id', $id);
            $this->deleteRows('family_members', 'user_id', $id);
            $this->deleteRows('emergency_contacts', 'user_id', $id);

            if (! empty($user->avatar_path)) {
                try {
                    app(KycDocumentService::class)->deleteFile((string) $user->avatar_path);
                } catch (Throwable) {
                }
            }

            $this->detachCustomer($id);
            $this->wipeWallets($id);

            try {
                User::query()->where('id', $id)->delete();
            } catch (Throwable) {
                $tombstone = [
                    'name' => 'Deleted user',
                    'email' => 'deleted-'.$id.'@deleted.karnacab.local',
                    'status' => 'DELETED',
                    'updated_at' => now(),
                ];
                foreach (['phone' => null, 'avatar_path' => null, 'last_lat' => null, 'last_lng' => null, 'password_hash' => Hash::make(bin2hex(random_bytes(16)))] as $column => $value) {
                    if (Schema::hasColumn('users', $column)) {
                        $tombstone[$column] = $value;
                    }
                }
                User::query()->where('id', $id)->update($tombstone);
            }
        });

        return [
            'ok' => true,
            'deleted' => true,
            'message' => 'Your KarnaRide customer account and associated personal data have been deleted.',
        ];
    }

    private function assertNoOpenTrips(int $userId): void
    {
        if (! Schema::hasTable('bookings') || ! Schema::hasColumn('bookings', 'customer_id')) {
            return;
        }
        $open = DB::table('bookings')
            ->where('customer_id', $userId)
            ->whereNotIn('status', BookingStatus::terminal())
            ->exists();
        if ($open) {
            throw new ConflictHttpException('Finish or cancel your open trip before deleting this account.');
        }
    }

    private function detachCustomer(int $userId): void
    {
        foreach ([
            'bookings',
            'invoices',
            'parcel_shipments',
            'support_tickets',
            'safety_incidents',
            'corporate_bookings',
            'bulk_bookings',
            'wallet_ledger',
            'promo_redemptions',
        ] as $table) {
            $this->nullColumn($table, 'customer_id', $userId);
            $this->nullColumn($table, 'user_id', $userId);
        }
    }

    private function wipeWallets(int $userId): void
    {
        if (! Schema::hasTable('wallets') || ! Schema::hasColumn('wallets', 'owner_user_id')) {
            return;
        }
        $walletIds = DB::table('wallets')->where('owner_user_id', $userId)->pluck('id');
        if (Schema::hasTable('wallet_ledger') && Schema::hasColumn('wallet_ledger', 'wallet_id') && $walletIds->isNotEmpty()) {
            DB::table('wallet_ledger')->whereIn('wallet_id', $walletIds)->delete();
        }
        DB::table('wallets')->where('owner_user_id', $userId)->delete();
    }

    private function deleteRows(string $table, string $column, int $userId): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        DB::table($table)->where($column, $userId)->delete();
    }

    private function nullColumn(string $table, string $column, int $userId): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        try {
            DB::table($table)->where($column, $userId)->update([$column => null]);
        } catch (Throwable) {
        }
    }
}
