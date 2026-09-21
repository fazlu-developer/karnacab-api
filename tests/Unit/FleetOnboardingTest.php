<?php

namespace Tests\Unit;

use App\Support\FleetOnboarding;
use Tests\TestCase;

class FleetOnboardingTest extends TestCase
{
    public function test_pending_company_needs_company_step(): void
    {
        $fleet = (object) ['trade_name' => '', 'company_type' => '', 'kyc_status' => 'pending', 'status' => 'PENDING'];
        $this->assertSame('FLEET_COMPANY', FleetOnboarding::nextStep($fleet));
        $this->assertFalse(FleetOnboarding::isActive($fleet));
    }

    public function test_submitted_fleet_is_under_review(): void
    {
        $fleet = (object) [
            'trade_name' => 'Patna Cabs',
            'company_type' => 'LLP',
            'kyc_status' => 'under_review',
            'status' => 'PENDING',
            'submitted_at' => now(),
        ];
        $this->assertSame('FLEET_REVIEW', FleetOnboarding::nextStep($fleet));
        $this->assertFalse(FleetOnboarding::isActive($fleet));
    }

    public function test_legacy_active_fleet_without_kyc_is_allowed(): void
    {
        $fleet = (object) ['trade_name' => 'Demo', 'company_type' => null, 'kyc_status' => null, 'status' => 'ACTIVE'];
        $this->assertTrue(FleetOnboarding::isActive($fleet));
        $this->assertSame('HOME', FleetOnboarding::nextStep($fleet));
    }
}
