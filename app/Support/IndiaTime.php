<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class IndiaTime
{
    public static function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            $dt = $value instanceof Carbon ? $value->copy() : Carbon::parse((string) $value);
        } catch (\Throwable) {
            return (string) $value;
        }

        return $dt->timezone('Asia/Kolkata')->format('d/m/Y h:i A');
    }
}
