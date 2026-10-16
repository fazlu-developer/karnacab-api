<?php

namespace Tests\Feature;

use App\Support\DistrictLocator;
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

    public function test_mumbai_pickup_to_patna_drop_is_coming_soon(): void
    {
        $trip = ServiceArea::trip([
            'pickupLat' => 19.0760,
            'pickupLng' => 72.8777,
            'dropLat' => 25.5941,
            'dropLng' => 85.1376,
            'pickupText' => 'Mumbai',
            'dropText' => 'Patna, Bihar',
        ]);
        $this->assertFalse($trip['allowed']);
        $this->assertTrue($trip['comingSoon']);
        $this->assertStringContainsString('pickup', strtolower((string) $trip['message']));
    }

    public function test_delhi_pickup_to_uttar_pradesh_drop_is_coming_soon(): void
    {
        $trip = ServiceArea::trip([
            'pickupLat' => 28.6139,
            'pickupLng' => 77.2090,
            'dropLat' => 26.8467,
            'dropLng' => 80.9462,
            'pickupText' => 'Delhi',
            'dropText' => 'Lucknow, Uttar Pradesh',
        ]);
        $this->assertFalse($trip['allowed']);
        $this->assertTrue($trip['comingSoon']);
        $this->assertStringContainsString('drop location', strtolower((string) $trip['message']));
    }

    public function test_saharsa_pickup_is_not_tagged_as_araria(): void
    {
        $saharsaId = \Illuminate\Support\Facades\DB::table('districts')->where('name', 'Saharsa')->value('id');
        if (! $saharsaId) {
            $this->markTestSkipped('District catalog is not loaded.');
        }
        $fromGps = DistrictLocator::resolve(25.88, 86.60, 'RCX7+745, Mangrauni, Bihar 852216, India', 1);
        $fromPin = DistrictLocator::resolve(null, null, 'Mangrauni, Bihar 852216', 1);
        $this->assertSame((int) $saharsaId, (int) $fromGps);
        $this->assertSame((int) $saharsaId, (int) $fromPin);
        $araria = \Illuminate\Support\Facades\DB::table('districts')->where('name', 'Araria')->value('id');
        if ($araria) {
            $this->assertNotSame((int) $araria, (int) $fromGps);
        }
    }

    public function test_mustafabad_pickup_is_north_east_delhi_not_central(): void
    {
        $northEast = \Illuminate\Support\Facades\DB::table('districts')->where('name', 'North East Delhi')->value('id');
        if (! $northEast) {
            $this->markTestSkipped('District catalog is not loaded.');
        }
        $fromAddress = DistrictLocator::resolve(
            28.7104937,
            77.2683288,
            'D1/73, Nehru Vihar, Block B, Old Mustafabad, New Mustafabad, New Delhi, Delhi, 110090, India',
            2,
        );
        $fromPin = DistrictLocator::resolve(null, null, 'Nehru Vihar, New Mustafabad, Delhi, 110090', 2);
        $this->assertSame((int) $northEast, (int) $fromAddress);
        $this->assertSame((int) $northEast, (int) $fromPin);
        $central = \Illuminate\Support\Facades\DB::table('districts')->where('name', 'Central Delhi')->value('id');
        if ($central) {
            $this->assertNotSame((int) $central, (int) $fromAddress);
        }
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
