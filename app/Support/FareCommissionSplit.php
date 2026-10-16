<?php

namespace App\Support;

/**
 * Completed-fare commission: 10% leaves the driver, 6% stays with the platform,
 * 4% is the district pool. A fleet cut is a percent of fare taken only from that 4%.
 */
final class FareCommissionSplit
{
    public const TOTAL_PERCENT = 10.0;

    public const PLATFORM_PERCENT = 6.0;

    public const DISTRICT_PERCENT = 4.0;

    /**
     * @return array{commissionPaise: int, platformPaise: int, districtPoolPaise: int, districtHeadPaise: int, fleetPaise: int, fleetPercent: float}
     */
    public static function split(int $farePaise, int $commissionPaise, float $fleetPercentOfFare): array
    {
        $fare = max(0, $farePaise);
        $commission = max(0, $commissionPaise);
        $platform = (int) round($fare * self::PLATFORM_PERCENT / 100);
        $districtPool = (int) round($fare * self::DISTRICT_PERCENT / 100);
        if ($platform + $districtPool > $commission) {
            $platform = $commission === 0 ? 0 : (int) round($commission * self::PLATFORM_PERCENT / self::TOTAL_PERCENT);
            $districtPool = max(0, $commission - $platform);
        } elseif ($platform + $districtPool < $commission) {
            $platform += $commission - $platform - $districtPool;
        }
        $fleetPct = min(self::DISTRICT_PERCENT, max(0, $fleetPercentOfFare));
        $fleet = (int) round($fare * $fleetPct / 100);
        if ($fleet > $districtPool) {
            $fleet = $districtPool;
        }

        return [
            'commissionPaise' => $commission,
            'platformPaise' => $platform,
            'districtPoolPaise' => $districtPool,
            'districtHeadPaise' => $districtPool - $fleet,
            'fleetPaise' => $fleet,
            'fleetPercent' => $fleetPct,
        ];
    }
}
