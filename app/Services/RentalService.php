<?php

namespace App\Services;

use App\Models\Booking;
use App\Support\BookingStatus;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cab rental is a scheduled car handover, not a chauffeured one-way trip.
 *
 * GPS while RENTAL_IN_USE comes from the customer app (POST /bookings/{id}/rental-location).
 * The driver phone is only the car's location before handover. After handover the driver
 * watches those customer pings. A point older than the location stale window is "last seen".
 */
class RentalService
{
    public function isRental(object $row): bool
    {
        return strtoupper((string) ($row->product ?? '')) === 'RENTAL';
    }

    /**
     * @param  array<string, mixed>  $dto
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    public function stampQuote(array $dto, array $quote): array
    {
        $mode = strtoupper((string) ($dto['rentalMode'] ?? ''));
        if (! in_array($mode, ['HOUR', 'DAY'], true)) {
            $mode = ! empty($dto['rentalByDay']) ? 'DAY' : 'HOUR';
        }
        $hours = max(1, (int) ($dto['rentalHours'] ?? $dto['hours'] ?? 2));
        $days = max(1, (int) ($dto['rentalDays'] ?? $dto['days'] ?? 1));
        if ($mode === 'DAY') {
            $hours = max(1, (int) ($dto['hours'] ?? ($days * 24)));
        }
        $rates = $this->rates((string) ($dto['category'] ?? $quote['category'] ?? ''), $mode === 'DAY' ? $days * 24 : $hours);
        $stops = $this->stopsFrom($dto['stops'] ?? ($quote['stops'] ?? []));
        $plannedKm = (float) ($quote['billedKm'] ?? $quote['includedKm'] ?? $dto['distanceKm'] ?? 0);
        $total = (int) ($quote['totalPaise'] ?? 0);

        $quote['rentalMode'] = $mode;
        $quote['rentalHours'] = $mode === 'HOUR' ? $hours : null;
        $quote['rentalDays'] = $mode === 'DAY' ? $days : null;
        $quote['hours'] = $hours;
        $quote['extraHourPaise'] = $rates['hour'];
        $quote['extraDayPaise'] = $rates['day'];
        $quote['includedKm'] = $rates['includedKm'];
        $quote['plannedKm'] = $plannedKm > 0 ? $plannedKm : $rates['includedKm'];
        $quote['stops'] = $stops;
        $quote['stopCount'] = count($stops);
        $quote['baseRentalPaise'] = $total;
        $quote['extraChargePaise'] = 0;
        $quote['rentalPhase'] = 'scheduled';
        $quote['serviceType'] = 'Cab on rent';
        $quote['prebook'] = true;
        $quote['advanceAfterAccept'] = true;
        $quote['advancePercent'] = \App\Support\AdvancePayment::percent('RENTAL');
        $quote['advancePaise'] = \App\Support\AdvancePayment::paise($total, 'RENTAL');
        $quote['commissionSettled'] = false;
        $quote['rentalModeLabel'] = $mode === 'DAY' ? 'By day' : 'By hour';

        return $quote;
    }

    /**
     * @return array{hour: int, day: int, includedKm: float}
     */
    public function rates(string $category, int $hours): array
    {
        $category = strtoupper($category);
        $query = DB::table('fare_rules')->where('product', 'RENTAL')->where('active', 1);
        if ($category !== '') {
            $query->where('category', $category);
        }
        if ($hours > 0) {
            $query->where('rental_hours', $hours);
        }
        $rule = $query->orderByDesc('district_id')->first();
        if (! $rule) {
            $fallback = DB::table('fare_rules')->where('product', 'RENTAL')->where('active', 1);
            if ($category !== '') {
                $fallback->where('category', $category);
            }
            $rule = $fallback->orderBy('rental_hours')->first()
                ?: DB::table('fare_rules')->where('product', 'RENTAL')->where('active', 1)->orderBy('id')->first();
        }
        $hour = (int) ($rule->extra_hour_paise ?? 15000);
        if ($hour < 1) {
            $hour = 15000;
        }
        $day = $hour * 24;
        if ($rule && isset($rule->extra_day_paise) && (int) $rule->extra_day_paise > 0) {
            $day = (int) $rule->extra_day_paise;
        }

        return [
            'hour' => $hour,
            'day' => $day,
            'includedKm' => (float) ($rule->included_km ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function preview(Booking $booking, array $snapshot, ?\Illuminate\Support\Carbon $at = null): array
    {
        $at = $at ?? now();
        $mode = strtoupper((string) ($snapshot['rentalMode'] ?? 'HOUR'));
        $bookedMinutes = $this->bookedMinutes($snapshot, $mode);
        $assignedAt = $booking->trip_started_at
            ?: (! empty($snapshot['assignedAt']) ? \Illuminate\Support\Carbon::parse($snapshot['assignedAt']) : null);
        $returnedAt = $booking->trip_ended_at
            ?: (! empty($snapshot['returnedAt']) ? \Illuminate\Support\Carbon::parse($snapshot['returnedAt']) : null);
        $end = $returnedAt ?? ($assignedAt ? $at : null);
        $elapsed = ($assignedAt && $end) ? max(0, (int) $assignedAt->diffInMinutes($end)) : 0;
        $overtimeMinutes = max(0, $elapsed - $bookedMinutes);
        $remaining = max(0, $bookedMinutes - $elapsed);
        $hourRate = (int) ($snapshot['extraHourPaise'] ?? 0);
        $dayRate = (int) ($snapshot['extraDayPaise'] ?? 0);
        if ($hourRate < 1 || $dayRate < 1) {
            $rates = $this->rates((string) $booking->category, $mode === 'DAY' ? max(1, (int) ($snapshot['rentalDays'] ?? 1)) * 24 : max(1, (int) ($snapshot['rentalHours'] ?? 1)));
            $hourRate = $hourRate > 0 ? $hourRate : $rates['hour'];
            $dayRate = $dayRate > 0 ? $dayRate : $rates['day'];
        }
        if (! empty($snapshot['rentalFareFrozen'])) {
            $units = (int) ($snapshot['extraUnits'] ?? 0);
            $extra = (int) ($snapshot['extraChargePaise'] ?? 0);
        } elseif ($mode === 'DAY') {
            $units = $overtimeMinutes > 0 ? (int) ceil($overtimeMinutes / (24 * 60)) : 0;
            $extra = $this->withGst($snapshot, $units * $dayRate);
        } else {
            $units = $overtimeMinutes > 0 ? (int) ceil($overtimeMinutes / 60) : 0;
            $extra = $this->withGst($snapshot, $units * $hourRate);
        }
        $base = (int) ($snapshot['baseRentalPaise'] ?? $booking->quote_paise ?? 0);
        $status = $this->useStatus($booking, $elapsed, $bookedMinutes);

        return [
            'rentalMode' => $mode,
            'rentalHours' => $snapshot['rentalHours'] ?? ($mode === 'HOUR' ? (int) ($snapshot['hours'] ?? 0) : null),
            'rentalDays' => $snapshot['rentalDays'] ?? null,
            'bookedMinutes' => $bookedMinutes,
            'elapsedMinutes' => $elapsed,
            'remainingMinutes' => $remaining,
            'overtimeMinutes' => $overtimeMinutes,
            'overtime' => $overtimeMinutes > 0,
            'extraUnits' => $units,
            'extraHourPaise' => $hourRate,
            'extraDayPaise' => $dayRate,
            'extraChargePaise' => $extra,
            'baseRentalPaise' => $base,
            'totalPaise' => $base + ($overtimeMinutes > 0 || ! empty($snapshot['rentalFareFrozen']) ? $extra : 0),
            'assignedAt' => $assignedAt?->toIso8601String(),
            'returnedAt' => $returnedAt?->toIso8601String(),
            'status' => $status,
        ];
    }

    public function applyOvertime(Booking $booking): Booking
    {
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        if (! empty($snapshot['rentalFareFrozen'])) {
            return $booking;
        }
        $preview = $this->preview($booking, $snapshot);
        $km = $this->kmUsed($booking);
        $total = (int) $preview['baseRentalPaise'] + (int) $preview['extraChargePaise'];
        $snapshot['rentalMode'] = $preview['rentalMode'];
        $snapshot['extraHourPaise'] = $preview['extraHourPaise'];
        $snapshot['extraDayPaise'] = $preview['extraDayPaise'];
        $snapshot['baseRentalPaise'] = $preview['baseRentalPaise'];
        $snapshot['extraChargePaise'] = $preview['extraChargePaise'];
        $snapshot['extraUnits'] = $preview['extraUnits'];
        $snapshot['kmUsed'] = $km;
        $snapshot['returnedAt'] = now()->toIso8601String();
        $snapshot['assignedUntil'] = $snapshot['returnedAt'];
        $snapshot['rentalFareFrozen'] = true;
        $snapshot['overtime'] = $preview['overtime'];
        $snapshot['rentalPhase'] = 'returned';
        $snapshot['totalPaise'] = $total;
        $snapshot['totalRupees'] = $total / 100;
        if (isset($snapshot['breakdown']) && is_array($snapshot['breakdown'])) {
            $snapshot['breakdown']['extraHourPaise'] = $preview['extraChargePaise'];
            $snapshot['breakdown']['totalPaise'] = $total;
        }
        $booking->update([
            'quote_paise' => $total,
            'quote_snapshot' => $snapshot,
            'actual_distance_km' => $km,
        ]);

        return $booking->fresh() ?? $booking;
    }

    /**
     * @return array<string, mixed>
     */
    public function track(Booking $booking): array
    {
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $preview = $this->preview($booking, $snapshot);
        $point = null;
        $path = [];
        if (Schema::hasTable('trip_km_logs')) {
            $rows = DB::table('trip_km_logs')->where('booking_id', $booking->id)->orderByDesc('id')->limit(60)->get();
            $point = $rows->first();
            $path = $rows->reverse()->map(fn ($row) => [
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
                'recordedAt' => isset($row->recorded_at) ? (string) $row->recorded_at : null,
            ])->values()->all();
        }
        $staleSeconds = 45;
        try {
            $staleSeconds = app(RideSettingsService::class)->locationStaleSeconds();
        } catch (\Throwable) {
            $staleSeconds = 45;
        }
        $recordedAt = null;
        $stale = true;
        if ($point && ! empty($point->recorded_at)) {
            $recordedAt = \Illuminate\Support\Carbon::parse($point->recorded_at);
            $stale = $recordedAt->lt(now()->subSeconds($staleSeconds));
        }
        $km = $this->kmUsed($booking);

        return [
            'source' => 'customer_app',
            'lat' => $point ? (float) $point->lat : null,
            'lng' => $point ? (float) $point->lng : null,
            'recordedAt' => $recordedAt?->toIso8601String(),
            'lastSeen' => $recordedAt?->toIso8601String(),
            'stale' => $point ? $stale : true,
            'kmUsed' => $km,
            'path' => $path,
            'stops' => $this->stopsFrom($snapshot['stops'] ?? []),
            ...$preview,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentFields(Booking $row, bool $isCustomer): array
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $preview = $this->preview($row, $snapshot);
        $inUse = (string) $row->status === BookingStatus::RENTAL_IN_USE;
        $done = (string) $row->status === BookingStatus::COMPLETED;
        $showHandover = $isCustomer && in_array((string) $row->status, [
            BookingStatus::DRIVER_ACCEPTED,
            BookingStatus::LEGACY_ASSIGNED,
            'DRIVER_ARRIVING',
            BookingStatus::DRIVER_ARRIVED,
        ], true);
        $showReturn = $isCustomer && ($inUse || $done);

        return [
            'rentalMode' => $preview['rentalMode'],
            'rentalModeLabel' => $preview['rentalMode'] === 'DAY' ? 'By day' : 'By hour',
            'rentalHours' => $preview['rentalHours'],
            'rentalDays' => $preview['rentalDays'],
            'durationLabel' => $preview['rentalMode'] === 'DAY'
                ? ((int) ($preview['rentalDays'] ?? 1)).' day'.((int) ($preview['rentalDays'] ?? 1) === 1 ? '' : 's')
                : ((int) ($preview['rentalHours'] ?? $preview['bookedMinutes'] / 60)).' hours',
            'extraHourPaise' => $preview['extraHourPaise'],
            'extraDayPaise' => $preview['extraDayPaise'],
            'extraChargePaise' => $preview['extraChargePaise'],
            'extraChargeRupees' => ((int) $preview['extraChargePaise']) / 100,
            'assignedAt' => $preview['assignedAt'],
            'returnedAt' => $preview['returnedAt'] ?? ($snapshot['assignedUntil'] ?? null),
            'kmUsed' => $this->kmUsed($row),
            'plannedKm' => isset($snapshot['plannedKm']) ? (float) $snapshot['plannedKm'] : (isset($snapshot['includedKm']) ? (float) $snapshot['includedKm'] : null),
            'bookedMinutes' => $preview['bookedMinutes'],
            'elapsedMinutes' => $preview['elapsedMinutes'],
            'remainingMinutes' => $preview['remainingMinutes'],
            'overtime' => $preview['overtime'],
            'overtimeMinutes' => $preview['overtimeMinutes'],
            'rentalPhase' => $inUse ? 'in_use' : ($done ? 'returned' : ($snapshot['rentalPhase'] ?? 'scheduled')),
            'rentalStatus' => $preview['status'],
            'stops' => $this->stopsFrom($snapshot['stops'] ?? []),
            'handoverOtp' => $showHandover ? $row->start_otp : null,
            'returnOtp' => $showReturn ? $row->end_otp : null,
        ];
    }

    public function bookedMinutes(array $snapshot, ?string $mode = null): int
    {
        $mode = strtoupper((string) ($mode ?: ($snapshot['rentalMode'] ?? 'HOUR')));
        if ($mode === 'DAY') {
            return max(1, (int) ($snapshot['rentalDays'] ?? 1)) * 24 * 60;
        }
        $hours = (int) ($snapshot['rentalHours'] ?? $snapshot['hours'] ?? $snapshot['fareInputs']['hours'] ?? 1);

        return max(1, $hours) * 60;
    }

    public function kmUsed(Booking $booking): float
    {
        $stored = (float) ($booking->actual_distance_km ?? 0);
        if (! Schema::hasTable('trip_km_logs')) {
            return round($stored, 3);
        }
        $points = DB::table('trip_km_logs')->where('booking_id', $booking->id)->orderBy('id')->get(['lat', 'lng']);
        $km = 0.0;
        $prev = null;
        foreach ($points as $point) {
            if ($prev !== null) {
                $delta = Geo::haversineKm((float) $prev->lat, (float) $prev->lng, (float) $point->lat, (float) $point->lng);
                if ($delta >= 0.01 && $delta <= 8) {
                    $km += $delta;
                }
            }
            $prev = $point;
        }

        return round(max($stored, $km), 3);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function withGst(array $snapshot, int $extraPaise): int
    {
        if ($extraPaise < 1 || empty($snapshot['gstAppliesToFare'])) {
            return $extraPaise;
        }
        $gst = (int) round($extraPaise * ((float) ($snapshot['gstPercent'] ?? 0)) / 100);

        return $extraPaise + $gst;
    }

    private function useStatus(Booking $booking, int $elapsed, int $booked): string
    {
        $status = (string) $booking->status;
        if ($status === BookingStatus::COMPLETED) {
            return 'returned';
        }
        if ($status !== BookingStatus::RENTAL_IN_USE) {
            return 'scheduled';
        }
        if ($elapsed > $booked) {
            return 'overdue';
        }
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        if (($snapshot['rentalPhase'] ?? '') === 'returning') {
            return 'returning';
        }

        return 'in_use';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stopsFrom(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_values($raw) as $i => $stop) {
            if (! is_array($stop)) {
                continue;
            }
            $text = trim((string) ($stop['text'] ?? $stop['address'] ?? $stop['title'] ?? ''));
            if ($text === '' && empty($stop['lat'])) {
                continue;
            }
            $out[] = [
                'index' => $i,
                'text' => $text !== '' ? $text : 'Stop '.($i + 1),
                'lat' => isset($stop['lat']) ? (float) $stop['lat'] : null,
                'lng' => isset($stop['lng']) ? (float) $stop['lng'] : null,
            ];
        }

        return $out;
    }
}
