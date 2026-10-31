<?php

namespace App\Support;

class VehicleCategory
{
    /**
     * @return list<string>
     */
    public static function family(string $category): array
    {
        $category = strtoupper(trim($category));
        $aliases = [
            'CAR' => 'SEDAN',
            'CAB' => 'SEDAN',
            'HATCHBACK' => 'SEDAN',
            'PREMIUM' => 'SEDAN',
            'MINI' => 'SEDAN',
            'VAN' => 'TRAVELLER',
            'TEMPO' => 'TRAVELLER',
            'SCOOTY' => 'BIKE',
            'SCOOTER' => 'BIKE',
            'E_RICKSHAW' => 'AUTO',
            'PICKUP' => 'TRUCK',
            'LOADING' => 'TRUCK',
            'BASIC' => 'AMBULANCE',
            'BLS' => 'AMBULANCE',
            'ALS' => 'AMBULANCE',
        ];
        $category = $aliases[$category] ?? $category;

        return match ($category) {
            'BIKE' => ['BIKE', 'SCOOTY', 'SCOOTER'],
            'AUTO' => ['AUTO', 'E_RICKSHAW'],
            'SEDAN' => ['SEDAN', 'MINI', 'CAR', 'CAB', 'HATCHBACK', 'PREMIUM'],
            'SUV' => ['SUV'],
            'TRAVELLER' => ['TRAVELLER', 'VAN', 'TEMPO'],
            'TRUCK' => ['TRUCK', 'PICKUP', 'LOADING'],
            'AMBULANCE' => ['AMBULANCE', 'BASIC', 'BLS', 'ALS'],
            default => [$category],
        };
    }

    public static function canonical(string $category): string
    {
        return self::family($category)[0] ?? strtoupper(trim($category));
    }

    public static function sameFamily(string $left, string $right): bool
    {
        return in_array(strtoupper(trim($right)), self::family($left), true);
    }

    public static function sameKind(string $left, string $right): bool
    {
        $a = self::canonical($left);
        $b = self::canonical($right);

        return $a !== '' && $a === $b;
    }

    /**
     * Parcel goods vehicles share one family so a pickup request reaches a van or truck.
     *
     * @return list<string>
     */
    public static function parcelSearchFamily(string $category): array
    {
        $goods = ['TRUCK', 'PICKUP', 'LOADING', 'VAN', 'TRAVELLER', 'TEMPO'];
        $family = self::family($category);
        $hit = false;
        foreach ($family as $item) {
            if (in_array(strtoupper($item), $goods, true)) {
                $hit = true;
                break;
            }
        }
        if (! $hit) {
            return $family;
        }

        return array_values(array_unique([...$family, ...$goods]));
    }

    /**
     * Vehicles that may take this parcel. A car parcel reaches sedan, mini, and SUV drivers.
     *
     * @return list<string>
     */
    public static function parcelOfferFamily(string $bookingCategory): array
    {
        $canonical = self::canonical($bookingCategory);
        if (in_array($canonical, ['SEDAN', 'SUV', 'BIKE', 'AUTO', 'AMBULANCE'], true)) {
            return self::family($canonical);
        }

        return self::parcelSearchFamily($bookingCategory);
    }

    public static function parcelCompatible(string $driverCategory, string $bookingCategory): bool
    {
        $driver = strtoupper(trim($driverCategory));
        $offered = self::parcelOfferFamily($bookingCategory);

        return in_array($driver, $offered, true)
            || in_array(self::canonical($driverCategory), $offered, true);
    }
}
