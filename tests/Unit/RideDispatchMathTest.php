<?php

namespace Tests\Unit;

use App\Support\BookingStatus;
use App\Support\Geo;
use PHPUnit\Framework\TestCase;

class RideDispatchMathTest extends TestCase
{
    public function test_haversine_mustafabad_to_one_km(): void
    {
        $origin = ['lat' => 28.7113, 'lng' => 77.2706];
        $offset = Geo::offsetKm($origin['lat'], $origin['lng'], 1, 0);
        $km = Geo::haversineKm($origin['lat'], $origin['lng'], $offset['lat'], $offset['lng']);
        $this->assertEqualsWithDelta(1.0, $km, 0.05);
    }

    public function test_searching_cannot_jump_to_completed(): void
    {
        $this->assertFalse(BookingStatus::canTransition(BookingStatus::SEARCHING, BookingStatus::COMPLETED));
        $this->assertFalse(BookingStatus::canTransition(BookingStatus::COMPLETED, BookingStatus::SEARCHING));
        $this->assertTrue(BookingStatus::canTransition(BookingStatus::SEARCHING, BookingStatus::DRIVER_ACCEPTED));
        $this->assertTrue(BookingStatus::canTransition(BookingStatus::DRIVER_ARRIVED, BookingStatus::TRIP_STARTED));
    }
}
