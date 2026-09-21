<?php

namespace Tests\Feature;

use App\Support\ServiceArea;
use Tests\TestCase;

class ServiceAreaTripTest extends TestCase
{
    public function test_patna_to_gaya_is_live(): void
    {
        $trip = ServiceArea::trip([
            'pickupLat' => 25.5941,
            'pickupLng' => 85.1376,
            'dropLat' => 24.7914,
            'dropLng' => 85.0002,
        ]);
        $this->assertTrue($trip['allowed']);
        $this->assertFalse($trip['comingSoon']);
    }

    public function test_mumbai_to_mumbai_is_coming_soon(): void
    {
        $trip = ServiceArea::trip([
            'pickupLat' => 19.0760,
            'pickupLng' => 72.8777,
            'dropLat' => 19.2183,
            'dropLng' => 72.9781,
            'pickupText' => 'Mumbai',
            'dropText' => 'Thane',
        ]);
        $this->assertFalse($trip['allowed']);
        $this->assertTrue($trip['comingSoon']);
        $this->assertStringContainsString('Coming soon', (string) $trip['message']);
    }

    public function test_mumbai_pickup_to_patna_drop_is_allowed(): void
    {
        $trip = ServiceArea::trip([
            'pickupLat' => 19.0760,
            'pickupLng' => 72.8777,
            'dropLat' => 25.5941,
            'dropLng' => 85.1376,
            'pickupText' => 'Mumbai',
            'dropText' => 'Patna, Bihar',
        ]);
        $this->assertTrue($trip['allowed']);
        $this->assertFalse($trip['comingSoon']);
    }

    public function test_quote_without_coordinates_is_not_blocked(): void
    {
        $trip = ServiceArea::trip([
            'product' => 'LOCAL_CAB',
            'category' => 'SEDAN',
            'distanceKm' => 10,
        ]);
        $this->assertTrue($trip['allowed']);
        $this->assertFalse($trip['comingSoon']);
    }
}
