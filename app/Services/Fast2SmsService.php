<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Fast2SmsService
{
    public function sendOtp(string $phone, string $otp): void
    {
        $apiKey = (string) config('karnacab.fast2sms_api_key');
        abort_unless($apiKey !== '', 503, 'OTP SMS is not configured');

        $response = Http::asForm()
            ->timeout(20)
            ->withHeaders([
                'authorization' => $apiKey,
            ])
            ->post('https://www.fast2sms.com/dev/bulkV2', [
                'sender_id' => (string) config('karnacab.fast2sms_sender_id', 'KARCAB'),
                'message' => (string) config('karnacab.fast2sms_template_id', '201877'),
                'variables_values' => $otp,
                'route' => 'dlt',
                'numbers' => $phone,
            ]);

        $body = $response->json();
        $ok = is_array($body) && (
            ($body['return'] ?? false) === true
            || ($body['status_code'] ?? null) === 200
        );
        if (! $response->successful() || ! $ok) {
            Log::warning('fast2sms.otp_failed', [
                'phone' => $phone,
                'http' => $response->status(),
            ]);
            abort(502, 'Could not send OTP SMS. Please try again.');
        }
    }
}
