<?php

namespace App\Support;

class TripFareDetail
{
    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>
     */
    public static function fromRecord(object $row, ?array $snapshot = null, ?int $totalPaise = null, ?int $commissionPaise = null, ?int $earningPaise = null): array
    {
        $snapshot = $snapshot ?? self::snapshot($row);
        $total = $totalPaise ?? (int) ($row->final_fare_paise ?? $snapshot['totalPaise'] ?? $row->quote_paise ?? 0);
        $commission = $commissionPaise ?? (int) ($row->commission_paise ?? $snapshot['commissionPaise'] ?? (int) round($total * 0.1));
        $earn = $earningPaise ?? (int) ($row->driver_earning_paise ?? $snapshot['driverEarningPaise'] ?? max(0, $total - $commission));

        return self::build($row, $snapshot, $total, $commission, $earn);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function build(object $row, array $snapshot, int $totalPaise, int $commissionPaise, int $earningPaise): array
    {
        $quote = is_array($snapshot['final'] ?? null)
            ? $snapshot['final']
            : (is_array($snapshot['live'] ?? null) ? $snapshot['live'] : $snapshot);
        $breakdown = is_array($quote['breakdown'] ?? null)
            ? $quote['breakdown']
            : (is_array($snapshot['breakdown'] ?? null) ? $snapshot['breakdown'] : []);
        $product = strtoupper((string) ($row->product ?? $quote['product'] ?? ''));
        $isRound = $product === 'ROUND_WAY';
        $waitMinutes = self::waitingMinutes($row, $snapshot);
        $waitRate = (int) ($snapshot['waitingPaisePerMin'] ?? $quote['waitingPaisePerMin'] ?? 0);
        $waitingPaise = (int) ($breakdown['waitingPaise'] ?? 0);
        if ($waitingPaise < 1 && $waitMinutes > 0 && $waitRate > 0) {
            $waitingPaise = $waitMinutes * $waitRate;
        }
        $allowPaise = (int) ($breakdown['driverAllowPaise'] ?? 0);
        $nightStayPaise = (int) ($breakdown['nightStayPaise'] ?? 0);
        $gstPaise = (int) ($breakdown['gstPaise'] ?? 0);
        $rideChargePaise = max(0, $totalPaise - $waitingPaise - $allowPaise - $nightStayPaise);
        $roundFarePaise = max(0, $totalPaise - $waitingPaise);
        $bookedKm = (float) ($snapshot['bookedDistanceKm'] ?? $row->distance_km ?? 0);
        $billedKm = (float) ($quote['billedKm'] ?? $snapshot['liveBilledKm'] ?? $snapshot['billedKm'] ?? $row->distance_km ?? 0);
        $drivenKm = (float) ($row->actual_distance_km ?? 0);
        $oneWayKm = (float) ($quote['oneWayKm'] ?? 0);
        if ($oneWayKm <= 0) {
            $oneWayKm = $bookedKm > 0 ? $bookedKm : ($isRound && $billedKm > 0 ? $billedKm / 2 : $billedKm);
        }
        $roundKm = $billedKm;
        if ($isRound) {
            if ($roundKm <= $oneWayKm + 0.2) {
                $roundKm = max($oneWayKm * 2, $billedKm);
            }
            $roundKm = max($roundKm, $oneWayKm * 2);
        }
        $waitLabel = self::waitingLabel($waitMinutes);
        $advancePaid = (int) ($snapshot['advancePaidPaise'] ?? 0);
        $mode = strtoupper((string) ($row->payment_mode ?? 'CASH'));
        $cash = $mode === 'CASH' || $mode === 'COD';

        $lines = [];
        $lines[] = self::line('service', 'Service', text: self::serviceLabel($product));
        if ($isRound) {
            $lines[] = self::line('oneWayKm', 'One way distance', text: self::kmText($oneWayKm));
            $lines[] = self::line('roundKm', 'Round trip billed (×2)', text: self::kmText($roundKm));
            if ($drivenKm >= 0.5 && abs($drivenKm - $oneWayKm) >= 0.3 && abs($drivenKm - $roundKm) >= 0.3) {
                $lines[] = self::line('drivenKm', 'Distance driven', text: self::kmText($drivenKm));
            }
            $lines[] = self::line('rideCharge', 'Ride charges', rupees: $rideChargePaise / 100);
            $lines[] = self::line('allowance', 'Driver allowance', rupees: $allowPaise / 100);
            $lines[] = self::line('waiting', $waitMinutes > 0 ? 'Waiting charge ('.$waitLabel.')' : 'Waiting charge', rupees: $waitingPaise / 100);
            if ($nightStayPaise > 0) {
                $lines[] = self::line('nightStay', 'Night stay', rupees: $nightStayPaise / 100);
            }
        } else {
            $shownKm = $drivenKm >= 0.5 ? $drivenKm : ($billedKm > 0 ? $billedKm : $bookedKm);
            $lines[] = self::line('distance', 'Distance', text: self::kmText($shownKm));
            $lines[] = self::line('rideCharge', 'Ride charges', rupees: $rideChargePaise / 100);
            if ($waitMinutes > 0 || $waitingPaise > 0 || $product === 'LOCAL_CAB') {
                $lines[] = self::line('waiting', $waitMinutes > 0 ? 'Waiting charge ('.$waitLabel.')' : 'Waiting charge', rupees: $waitingPaise / 100);
            }
            if ($allowPaise > 0) {
                $lines[] = self::line('allowance', 'Driver allowance', rupees: $allowPaise / 100);
            }
            if ($nightStayPaise > 0) {
                $lines[] = self::line('nightStay', 'Night stay', rupees: $nightStayPaise / 100);
            }
        }
        if ($gstPaise > 0) {
            $lines[] = self::line('gst', 'GST (included)', rupees: $gstPaise / 100);
        }
        $lines[] = self::line('total', 'Total fare', rupees: $totalPaise / 100);
        if ($advancePaid > 0 && $advancePaid < $totalPaise) {
            $lines[] = self::line('advance', '10% already paid', rupees: $advancePaid / 100);
            $lines[] = self::line('payDriver', $cash ? 'Pay driver in cash' : 'Remaining amount', rupees: ($totalPaise - $advancePaid) / 100);
        }

        $driverLines = $lines;
        $driverLines[] = self::line('commission', 'Platform commission', rupees: -($commissionPaise / 100));
        $driverLines[] = self::line('keep', 'You keep', rupees: $earningPaise / 100);

        $customerNote = $isRound
            ? 'Ride charges are one-way km × 2. Driver allowance and waiting between one side complete and return start are added to this total. No separate waiting payment.'
            : 'Ride charges for this '.strtolower(self::serviceLabel($product)).' are shown above.';
        if ($advancePaid > 0 && $advancePaid < $totalPaise) {
            $customerNote .= ' 10% was already paid when the driver accepted.';
        }
        if ($cash) {
            $customerNote .= $advancePaid > 0 && $advancePaid < $totalPaise
                ? ' Pay the remaining amount in cash to the driver.'
                : ' Pay this total in cash to the driver.';
        } elseif ($mode === 'WALLET') {
            $customerNote .= ' Paid from wallet.';
        } else {
            $customerNote .= ' Online payment is settled.';
        }

        $driverNote = $isRound
            ? 'Collect the updated total. Ride charges + allowance + waiting. Commission is from this total. Waiting is not a separate cash collection.'
            : 'Collect the updated total from the customer. Commission is deducted after the trip.';

        return [
            'product' => $product,
            'serviceLabel' => self::serviceLabel($product),
            'oneWayKm' => round($oneWayKm, 2),
            'roundKm' => round($isRound ? $roundKm : $oneWayKm, 2),
            'drivenKm' => round($drivenKm, 3),
            'waitingMinutes' => $waitMinutes,
            'waitingLabel' => $waitLabel,
            'waitingRupees' => $waitingPaise / 100,
            'allowanceRupees' => $allowPaise / 100,
            'rideChargeRupees' => $rideChargePaise / 100,
            'roundFareRupees' => $roundFarePaise / 100,
            'totalRupees' => $totalPaise / 100,
            'advancePaidRupees' => $advancePaid / 100,
            'lines' => $lines,
            'driverLines' => $driverLines,
            'customerNote' => $customerNote,
            'driverNote' => $driverNote,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function waitingMinutes(object $row, array $snapshot): int
    {
        $stored = (int) ($row->wait_minutes ?? $snapshot['waitingMinutes'] ?? $snapshot['waitMinutes'] ?? 0);
        if (empty($snapshot['oneSideCompletedAt'])) {
            return max(0, $stored);
        }
        try {
            $from = \Illuminate\Support\Carbon::parse($snapshot['oneSideCompletedAt']);
            $to = \Illuminate\Support\Carbon::parse($snapshot['returnStartedAt'] ?? now());
        } catch (\Throwable) {
            return max(0, $stored);
        }

        return max($stored, (int) floor(abs($from->diffInSeconds($to)) / 60));
    }

    public static function waitingLabel(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        if ($hours > 0 && $mins > 0) {
            return $hours.' hour'.($hours === 1 ? '' : 's').' '.$mins.' min';
        }
        if ($hours > 0) {
            return $hours.' hour'.($hours === 1 ? '' : 's');
        }

        return $mins.' min';
    }

    public static function kmText(float $km): string
    {
        if ($km <= 0) {
            return '—';
        }

        return number_format($km, $km < 10 ? 2 : 1).' km';
    }

    public static function serviceLabel(string $product): string
    {
        return match (strtoupper($product)) {
            'ROUND_WAY' => 'Round trip',
            'ONE_WAY' => 'One way',
            'LOCAL_CAB' => 'City ride',
            'RENTAL' => 'Cab on rent',
            'AIRPORT' => 'Airport',
            'RAILWAY' => 'Railway',
            'MULTI_STOP' => 'Multi stop',
            'PARCEL' => 'Parcel',
            'SCHEDULE' => 'Scheduled ride',
            default => trim(str_replace('_', ' ', $product)) ?: 'Ride',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(object $row): array
    {
        $raw = $row->quote_snapshot ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * @return array{key: string, label: string, rupees?: float|null, text?: string|null}
     */
    private static function line(string $key, string $label, ?float $rupees = null, ?string $text = null): array
    {
        $row = ['key' => $key, 'label' => $label];
        if ($rupees !== null) {
            $row['rupees'] = $rupees;
        }
        if ($text !== null) {
            $row['text'] = $text;
        }

        return $row;
    }

    private static function rupeesText(float $rupees): string
    {
        return number_format($rupees, abs($rupees - round($rupees)) < 0.01 ? 0 : 2);
    }
}
