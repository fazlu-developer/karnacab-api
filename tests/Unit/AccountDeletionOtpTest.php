<?php

namespace Tests\Unit;

use App\Services\AuthService;
use Tests\TestCase;

class AccountDeletionOtpTest extends TestCase
{
    public function test_consume_otp_rejects_invalid_code(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(AuthService::class)->consumeOtp('9876543210', '000000');
    }
}
