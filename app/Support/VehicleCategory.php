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
}
