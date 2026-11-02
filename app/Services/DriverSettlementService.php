<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DriverSettlementService
{
    public function ensure(): void
    {
        if (Schema::hasTable('driver_settlements')) {
            return;
        }
        Schema::create('driver_settlements', function ($table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->index();
            $table->unsignedBigInteger('driver_id')->nullable()->index();
            $table->unsignedBigInteger('driver_user_id')->nullable();
            $table->unsignedInteger('payable_paise')->default(0);
            $table->unsignedInteger('paid_paise')->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->json('payouts')->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'driver_id']);
        });
    }

    public function openPending(Booking $booking, int $driverId, ?int $driverUserId, int $payablePaise): void
    {
        if ($payablePaise < 1 || $driverId < 1) {
            return;
        }
        $this->ensure();
        $existing = DB::table('driver_settlements')->where('booking_id', $booking->id)->where('driver_id', $driverId)->first();
        if ($existing) {
            return;
        }
        DB::table('driver_settlements')->insert([
            'booking_id' => $booking->id,
            'driver_id' => $driverId,
            'driver_user_id' => $driverUserId,
            'payable_paise' => $payablePaise,
            'paid_paise' => 0,
            'status' => 'pending',
            'payouts' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function forBooking(int $bookingId): array
    {
        if (! Schema::hasTable('driver_settlements')) {
            return [];
        }

        return DB::table('driver_settlements')->where('booking_id', $bookingId)->orderBy('id')->get()->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'driverId' => $row->driver_id ? (int) $row->driver_id : null,
                'payablePaise' => (int) $row->payable_paise,
                'paidPaise' => (int) $row->paid_paise,
                'remainingPaise' => max(0, (int) $row->payable_paise - (int) $row->paid_paise),
                'status' => (string) $row->status,
                'payouts' => json_decode((string) ($row->payouts ?? '[]'), true) ?: [],
            ];
        })->all();
    }
}
