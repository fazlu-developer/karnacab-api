<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OfferDismissal
{
    public function ensure(): void
    {
        if (! Schema::hasTable('offer_dismissals')) {
            Schema::create('offer_dismissals', function ($table) {
                $table->id();
                $table->unsignedBigInteger('driver_id');
                $table->string('kind', 24);
                $table->string('target_id', 64);
                $table->unsignedTinyInteger('reject_count')->default(0);
                $table->timestamp('snooze_until')->nullable();
                $table->timestamps();
                $table->unique(['driver_id', 'kind', 'target_id']);
            });
        }
    }

    public function record(int $driverId, string $kind, string $targetId): void
    {
        $this->ensure();
        $now = now();
        $row = DB::table('offer_dismissals')
            ->where('driver_id', $driverId)
            ->where('kind', $kind)
            ->where('target_id', $targetId)
            ->first();
        $count = ((int) ($row->reject_count ?? 0)) + 1;
        $final = $count >= 2;
        DB::table('offer_dismissals')->updateOrInsert(
            ['driver_id' => $driverId, 'kind' => $kind, 'target_id' => $targetId],
            [
                'reject_count' => $count,
                'snooze_until' => $final ? null : $now->copy()->addMinutes(2),
                'updated_at' => $now,
                'created_at' => $row->created_at ?? $now,
            ],
        );
    }

    public function hidden(int $driverId, string $kind, string $targetId): bool
    {
        if (! Schema::hasTable('offer_dismissals')) {
            return false;
        }
        $row = DB::table('offer_dismissals')
            ->where('driver_id', $driverId)
            ->where('kind', $kind)
            ->where('target_id', $targetId)
            ->first();
        if (! $row) {
            return false;
        }
        if ((int) $row->reject_count >= 2) {
            return true;
        }
        if ($row->snooze_until && now()->lt($row->snooze_until)) {
            return true;
        }

        return false;
    }
}
