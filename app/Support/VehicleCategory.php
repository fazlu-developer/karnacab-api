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
        ];
        $category = $aliases[$category] ?? $category;

        return match ($category) {
            'BIKE' => ['BIKE', 'SCOOTY', 'SCOOTER'],
            'AUTO' => ['AUTO', 'E_RICKSHAW'],
            'SEDAN' => ['SEDAN', 'MINI', 'CAR', 'CAB', 'HATCHBACK', 'PREMIUM'],
            'SUV' => ['SUV'],
            'TRAVELLER' => ['TRAVELLER', 'VAN', 'TEMPO'],
            'TRUCK' => ['TRUCK', 'PICKUP', 'LOADING'],
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

    public static function parcelCompatible(string $driverCategory, string $bookingCategory): bool
    {
        return self::sameFamily($driverCategory, $bookingCategory)
            || in_array(strtoupper(trim($bookingCategory)), self::parcelSearchFamily($driverCategory), true);
    }
}
