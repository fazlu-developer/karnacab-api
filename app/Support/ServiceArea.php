<?php

namespace App\Support;

class ServiceArea
{
    public static function allows(?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }
        $delhi = $lat >= 28.40 && $lat <= 28.90 && $lng >= 76.80 && $lng <= 77.40;
        $bihar = $lat >= 24.20 && $lat <= 27.60 && $lng >= 83.19 && $lng <= 88.35;

        return $delhi || $bihar;
    }

    public static function label(?float $lat, ?float $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }
        if ($lat >= 28.40 && $lat <= 28.90 && $lng >= 76.80 && $lng <= 77.40) {
            return 'Delhi';
        }
        if ($lat >= 24.20 && $lat <= 27.60 && $lng >= 83.19 && $lng <= 88.35) {
            return 'Bihar';
        }

        return null;
    }

    public static function states(): array
    {
        return ['Bihar', 'Delhi'];
    }

    public static function comingSoonMessage(): string
    {
        return 'KarnaCab is coming soon in your city. We currently operate in Bihar and Delhi.';
    }
}
