<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(private readonly FareService $fares) {}

    public function create(User $actor, array $dto): array
    {
        $quote = $this->fares->quote([
            'product' => $dto['product'],
            'category' => $dto['category'],
            'distanceKm' => $dto['distanceKm'] ?? 1,
            'districtId' => $dto['districtId'] ?? $actor->district_id,
            'hours' => $dto['hours'] ?? null,
            'night' => $dto['night'] ?? false,
            'stopCount' => isset($dto['stops']) ? count($dto['stops']) : ($dto['stopCount'] ?? 0),
            'roundTrip' => $dto['roundTrip'] ?? true,
            'waitMinutes' => $dto['waitMinutes'] ?? 0,
            'tollPaise' => $dto['tollPaise'] ?? 0,
            'parkingPaise' => $dto['parkingPaise'] ?? 0,
        ]);
        $booking = Booking::query()->create([
            'public_ref' => strtoupper(Str::random(8)),
            'customer_id' => $actor->id,
            'district_id' => $dto['districtId'] ?? $actor->district_id,
            'product' => $dto['product'],
            'category' => $dto['category'],
            'status' => 'REQUESTED',
            'pickup_text' => $dto['pickupText'] ?? $dto['pickup'] ?? 'Pickup',
            'drop_text' => $dto['dropText'] ?? $dto['drop'] ?? 'Drop',
            'pickup_lat' => $dto['pickupLat'] ?? null,
            'pickup_lng' => $dto['pickupLng'] ?? null,
            'drop_lat' => $dto['dropLat'] ?? null,
            'drop_lng' => $dto['dropLng'] ?? null,
            'distance_km' => $quote['billedKm'],
            'quote_paise' => $quote['totalPaise'],
            'quote_snapshot' => $quote,
            'scheduled_at' => $dto['scheduledAt'] ?? null,
            'return_at' => $dto['returnAt'] ?? null,
            'flight_number' => $dto['flightNumber'] ?? null,
            'train_number' => $dto['trainNumber'] ?? null,
            'terminal' => $dto['terminal'] ?? null,
            'start_otp' => (string) random_int(1000, 9999),
            'end_otp' => (string) random_int(1000, 9999),
        ]);

        return $this->present($booking);
    }

    public function list(User $actor)
    {
        $q = Booking::query()->orderByDesc('id');
        if ($actor->role === 'CUSTOMER' || $actor->role === 'CORPORATE') {
            $q->where('customer_id', $actor->id);
        } elseif ($actor->role === 'DRIVER') {
            $driver = Driver::query()->where('user_id', $actor->id)->first();
            $q->where(function ($inner) use ($driver) {
                $inner->where('status', 'REQUESTED');
                if ($driver) {
                    $inner->orWhere('driver_id', $driver->id);
                }
            });
        }

        return $q->limit(100)->get()->map(fn ($row) => $this->present($row))->values()->all();
    }

    public function one(User $actor, string $id): array
    {
        return $this->present($this->findVisible($actor, $id));
    }

    public function accept(User $actor, string $id): array
    {
        $booking = Booking::query()->findOrFail($id);
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        $booking->update(['status' => 'ASSIGNED', 'driver_id' => $driver->id]);

        return $this->present($booking->fresh());
    }

    public function reject(User $actor, string $id): array
    {
        $booking = Booking::query()->findOrFail($id);

        return $this->present($booking);
    }

    public function lifecycle(User $actor, string $id, string $status): array
    {
        $map = [
            'cancel' => 'CANCELLED',
            'cancelled' => 'CANCELLED',
            'start' => 'STARTED',
            'arrive' => 'DRIVER_ARRIVED',
            'complete' => 'COMPLETED',
            'assign' => 'ASSIGNED',
        ];
        $status = $map[strtolower($status)] ?? strtoupper($status);
        $booking = $this->findVisible($actor, $id);
        $booking->update(['status' => $status]);

        return $this->present($booking->fresh());
    }

    private function lifecycleLabel(string $status): string
    {
        return match ($status) {
            'REQUESTED', 'DRIVER_SEARCHING' => 'driver_searching',
            'ASSIGNED', 'DRIVER_ASSIGNED' => 'driver_assigned',
            'DRIVER_ARRIVING' => 'driver_arriving',
            'DRIVER_ARRIVED' => 'driver_arrived',
            'STARTED', 'ONGOING' => 'started',
            'COMPLETED' => 'completed',
            'CANCELLED' => 'cancelled',
            default => strtolower($status),
        };
    }

    public function present(Booking $row): array
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $total = $snapshot['totalPaise'] ?? (int) $row->quote_paise;

        return [
            'id' => (string) $row->id,
            'publicRef' => $row->public_ref,
            'customerId' => (string) $row->customer_id,
            'product' => $row->product,
            'category' => $row->category,
            'status' => $row->status,
            'lifecycle' => $this->lifecycleLabel($row->status),
            'pickupText' => $row->pickup_text,
            'dropText' => $row->drop_text,
            'pickupLat' => $row->pickup_lat,
            'pickupLng' => $row->pickup_lng,
            'dropLat' => $row->drop_lat,
            'dropLng' => $row->drop_lng,
            'distanceKm' => $row->distance_km,
            'quotePaise' => $row->quote_paise,
            'driverId' => $row->driver_id ? (string) $row->driver_id : null,
            'scheduledAt' => optional($row->scheduled_at)?->toIso8601String(),
            'fare' => [
                'source' => 'server',
                'totalPaise' => $total,
                'totalRupees' => $total / 100,
                'currency' => 'INR',
                'breakdown' => $snapshot['breakdown'] ?? null,
            ],
        ];
    }

    private function findVisible(User $actor, string $id): Booking
    {
        $booking = Booking::query()->findOrFail($id);
        if (in_array($actor->role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return $booking;
        }
        if ((string) $booking->customer_id === (string) $actor->id) {
            return $booking;
        }
        $driver = Driver::query()->where('user_id', $actor->id)->first();
        if ($driver && (string) $booking->driver_id === (string) $driver->id) {
            return $booking;
        }
        abort(403, 'Forbidden');
    }
}
