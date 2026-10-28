<?php

namespace App\Support;

/**
 * Tour & Travel money. This is not the cab 10% advance.
 *
 * Example T = ₹10,000 (1_000_000 paise):
 * Advance A = 30% of T = ₹3,000
 * On that payment: platform ₹1,000 (10% of T), driver ₹2,000 (20% of T) after assign
 * At complete the customer pays ₹7,000 to the driver. Platform does not take 10% of ₹7,000.
 */
final class TourMoney
{
    /**
     * @return array{totalPaise: int, advancePaise: int, platformCommissionPaise: int, driverAdvancePaise: int, balancePaise: int}
     */
    public static function split(int $totalPaise, ?int $advancePercent = null): array
    {
        $total = max(0, $totalPaise);
        $advancePct = $advancePercent ?? 30;
        $advancePct = max(0, min(100, $advancePct));
        $platform = intdiv($total * 10, 100);
        $advance = intdiv($total * $advancePct, 100);
        if ($advance < $platform) {
            $platform = $advance;
        }
        $driver = max(0, $advance - $platform);

        return [
            'totalPaise' => $total,
            'advancePaise' => $advance,
            'advancePercent' => $advancePct,
            'platformCommissionPaise' => $platform,
            'driverAdvancePaise' => $driver,
            'balancePaise' => $total - $advance,
        ];
    }
}
