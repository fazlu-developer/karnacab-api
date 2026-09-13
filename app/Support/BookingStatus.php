<?php

namespace App\Support;

final class BookingStatus
{
    public const PENDING = 'PENDING';
    public const SEARCHING = 'SEARCHING';
    public const DRIVER_ACCEPTED = 'DRIVER_ACCEPTED';
    public const DRIVER_ARRIVED = 'DRIVER_ARRIVED';
    public const OTP_VERIFIED = 'OTP_VERIFIED';
    public const TRIP_STARTED = 'TRIP_STARTED';
    public const COMPLETED = 'COMPLETED';
    public const CUSTOMER_CANCELLED = 'CUSTOMER_CANCELLED';
    public const DRIVER_CANCELLED = 'DRIVER_CANCELLED';
    public const EXPIRED = 'EXPIRED';

    /** Legacy rows still in production. */
    public const LEGACY_REQUESTED = 'REQUESTED';
    public const LEGACY_ASSIGNED = 'ASSIGNED';
    public const LEGACY_STARTED = 'STARTED';
    public const LEGACY_CANCELLED = 'CANCELLED';

    /**
     * @return list<string>
     */
    public static function searching(): array
    {
        return [self::SEARCHING, self::LEGACY_REQUESTED, 'DRIVER_SEARCHING', self::PENDING];
    }

    /**
     * @return list<string>
     */
    public static function assigned(): array
    {
        return [self::DRIVER_ACCEPTED, self::LEGACY_ASSIGNED, 'DRIVER_ASSIGNED', 'DRIVER_ARRIVING'];
    }

    /**
     * @return list<string>
     */
    public static function inTrip(): array
    {
        return [self::DRIVER_ARRIVED, self::OTP_VERIFIED, self::TRIP_STARTED, self::LEGACY_STARTED, 'ONGOING'];
    }

    /**
     * @return list<string>
     */
    public static function openForDriver(): array
    {
        return array_merge(self::assigned(), self::inTrip());
    }

    /**
     * @return list<string>
     */
    public static function terminal(): array
    {
        return [self::COMPLETED, self::CUSTOMER_CANCELLED, self::DRIVER_CANCELLED, self::EXPIRED, self::LEGACY_CANCELLED];
    }

    public static function isSearching(string $status): bool
    {
        return in_array($status, self::searching(), true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::terminal(), true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return true;
        }
        $map = [
            self::PENDING => [self::SEARCHING, self::CUSTOMER_CANCELLED, self::EXPIRED],
            self::SEARCHING => [self::DRIVER_ACCEPTED, self::CUSTOMER_CANCELLED, self::EXPIRED],
            self::LEGACY_REQUESTED => [self::DRIVER_ACCEPTED, self::CUSTOMER_CANCELLED, self::EXPIRED, self::LEGACY_ASSIGNED],
            'DRIVER_SEARCHING' => [self::DRIVER_ACCEPTED, self::CUSTOMER_CANCELLED, self::EXPIRED],
            self::DRIVER_ACCEPTED => [self::DRIVER_ARRIVED, self::CUSTOMER_CANCELLED, self::DRIVER_CANCELLED],
            self::LEGACY_ASSIGNED => [self::DRIVER_ARRIVED, self::CUSTOMER_CANCELLED, self::DRIVER_CANCELLED, self::LEGACY_STARTED],
            self::DRIVER_ARRIVED => [self::OTP_VERIFIED, self::TRIP_STARTED, self::CUSTOMER_CANCELLED, self::DRIVER_CANCELLED],
            self::OTP_VERIFIED => [self::TRIP_STARTED, self::CUSTOMER_CANCELLED, self::DRIVER_CANCELLED],
            self::TRIP_STARTED => [self::COMPLETED, self::CUSTOMER_CANCELLED, self::DRIVER_CANCELLED],
            self::LEGACY_STARTED => [self::COMPLETED, self::CUSTOMER_CANCELLED, self::DRIVER_CANCELLED],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    public static function lifecycle(string $status): string
    {
        return match (strtoupper($status)) {
            self::PENDING => 'pending',
            self::SEARCHING, self::LEGACY_REQUESTED, 'DRIVER_SEARCHING' => 'driver_searching',
            self::DRIVER_ACCEPTED, self::LEGACY_ASSIGNED, 'DRIVER_ASSIGNED', 'DRIVER_ARRIVING' => 'driver_assigned',
            self::DRIVER_ARRIVED => 'driver_arrived',
            self::OTP_VERIFIED => 'otp_verified',
            self::TRIP_STARTED, self::LEGACY_STARTED, 'ONGOING' => 'started',
            self::COMPLETED => 'completed',
            self::CUSTOMER_CANCELLED, self::DRIVER_CANCELLED, self::LEGACY_CANCELLED => 'cancelled',
            self::EXPIRED => 'expired',
            default => strtolower($status),
        };
    }
}
