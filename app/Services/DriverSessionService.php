<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Driver;
use App\Models\User;
use App\Support\BookingStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DriverSessionService
{
    public function hasActiveBooking(int $driverId): bool
    {
        return Booking::query()
            ->where('driver_id', $driverId)
            ->whereIn('status', BookingStatus::openForDriver())
            ->exists();
    }

    public function revokeNow(User $user, ?string $reason = null): void
    {
        $this->ensureColumns();
        $updates = ['updated_at' => now()];
        if (Schema::hasColumn('users', 'session_epoch')) {
            $updates['session_epoch'] = ((int) ($user->session_epoch ?? 0)) + 1;
        }
        if (Schema::hasColumn('users', 'force_logout_pending')) {
            $updates['force_logout_pending'] = 0;
        }
        if (Schema::hasColumn('users', 'status')) {
            $updates['status'] = 'SUSPENDED';
        }
        User::query()->where('id', $user->id)->update($updates);
        Driver::query()->where('user_id', $user->id)->update([
            'online' => 0,
            'duty_status' => 'offline',
            'updated_at' => now(),
        ]);
        if ($reason) {
            Driver::query()->where('user_id', $user->id)->update(['kyc_rejected_reason' => $reason]);
        }
    }

    public function deactivateOrDefer(User $user, Driver $driver, string $reason): string
    {
        $this->ensureColumns();
        Driver::query()->where('id', $driver->id)->update([
            'online' => 0,
            'kyc_rejected_reason' => $reason,
            'updated_at' => now(),
        ]);

        if ($this->hasActiveBooking((int) $driver->id)) {
            $payload = ['updated_at' => now()];
            if (Schema::hasColumn('users', 'force_logout_pending')) {
                $payload['force_logout_pending'] = 1;
            }
            User::query()->where('id', $user->id)->update($payload);

            return 'deferred';
        }

        $this->revokeNow($user, $reason);

        return 'revoked';
    }

    public function finishPendingLogout(User $user): void
    {
        $this->ensureColumns();
        if (! Schema::hasColumn('users', 'force_logout_pending')) {
            return;
        }
        $fresh = User::query()->find($user->id);
        if (! $fresh || ! $fresh->force_logout_pending) {
            return;
        }
        $driver = Driver::query()->where('user_id', $fresh->id)->first();
        if ($driver && $this->hasActiveBooking((int) $driver->id)) {
            return;
        }
        $this->revokeNow($fresh, $fresh->driver?->kyc_rejected_reason);
    }

    public function notifyAdmins(string $title, string $body, array $entity = []): void
    {
        if (! Schema::hasTable('user_notifications')) {
            return;
        }
        $adminIds = DB::table('users')->whereIn('role', ['ADMIN', 'SUPER_ADMIN'])->pluck('id');
        $now = now();
        foreach ($adminIds as $id) {
            DB::table('user_notifications')->insert([
                'user_id' => $id,
                'title' => substr($title, 0, 160),
                'body' => substr($body, 0, 500),
                'kind' => 'document_expired',
                'entity_type' => $entity['type'] ?? 'driver',
                'entity_id' => $entity['id'] ?? null,
                'created_at' => $now,
            ]);
        }
    }

    private function ensureColumns(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'session_epoch')) {
            Schema::table('users', function ($table) {
                $table->unsignedInteger('session_epoch')->default(0);
            });
        }
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'force_logout_pending')) {
            Schema::table('users', function ($table) {
                $table->boolean('force_logout_pending')->default(false);
            });
        }
    }
}
