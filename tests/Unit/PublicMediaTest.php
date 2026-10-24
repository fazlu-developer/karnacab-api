<?php

namespace Tests\Unit;

use App\Support\PublicMedia;
use PHPUnit\Framework\TestCase;

class PublicMediaTest extends TestCase
{
    public function test_presenters_never_emit_the_marketing_host(): void
    {
        $url = PublicMedia::presentMediaUrl('https://www.karnaride.in/uploads/ads/a.jpg?x=1', '9');
        $this->assertSame('https://admin.karnaride.in/uploads/ads/a.jpg?v=9', $url);
        $this->assertStringNotContainsString('https://karnaride.in/', (string) $url);
        $this->assertNull(PublicMedia::presentMediaUrl('https://api.karnaride.in/api/v1/ads/1/banner'));
    }
}
