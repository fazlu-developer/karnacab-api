<?php

namespace Tests\Unit;

use App\Support\TourMoney;
use PHPUnit\Framework\TestCase;

class TourMoneyTest extends TestCase
{
    public function test_ten_thousand_rupee_tour_splits_thirty_percent_without_a_second_commission(): void
    {
        // T = ₹10,000. Advance ₹3,000. Platform ₹1,000. Driver wallet ₹2,000. Ground collection ₹7,000.
        $split = TourMoney::split(1_000_000);

        $this->assertSame(1_000_000, $split['totalPaise']);
        $this->assertSame(300_000, $split['advancePaise']);
        $this->assertSame(100_000, $split['platformCommissionPaise']);
        $this->assertSame(200_000, $split['driverAdvancePaise']);
        $this->assertSame(700_000, $split['balancePaise']);
        $this->assertSame($split['advancePaise'], $split['platformCommissionPaise'] + $split['driverAdvancePaise']);
        $this->assertSame($split['totalPaise'], $split['advancePaise'] + $split['balancePaise']);
    }
}
