<?php

namespace App\Support;

final class Geo
{
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($earth * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a))), 3);
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public static function offsetKm(float $lat, float $lng, float $km, float $bearingDeg): array
    {
        $earth = 6371.0;
        $bearing = deg2rad($bearingDeg);
        $lat1 = deg2rad($lat);
        $lng1 = deg2rad($lng);
        $ang = $km / $earth;
        $lat2 = asin(sin($lat1) * cos($ang) + cos($lat1) * sin($ang) * cos($bearing));
        $lng2 = $lng1 + atan2(
            sin($bearing) * sin($ang) * cos($lat1),
            cos($ang) - sin($lat1) * sin($lat2),
        );

        return [
            'lat' => round(rad2deg($lat2), 6),
            'lng' => round(rad2deg($lng2), 6),
        ];
    }

    public static function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $y = sin(deg2rad($lng2 - $lng1)) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2))
            - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2 - $lng1));

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }
}
