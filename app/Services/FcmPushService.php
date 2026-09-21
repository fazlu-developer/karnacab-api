<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FcmPushService
{
    public function notifyUsers(array $userIds, string $title, string $body, array $data = [], ?string $imageUrl = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($ids === []) {
            return;
        }
        $this->persistInbox($ids, $title, $body, $data);
        $tokens = $this->tokensFor($ids);
        foreach ($tokens as $token) {
            $this->send($token, $title, $body, $data, $imageUrl);
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    private function persistInbox(array $userIds, string $title, string $body, array $data): void
    {
        if (! Schema::hasTable('user_notifications')) {
            return;
        }
        if (! Schema::hasColumn('user_notifications', 'read_at') && Schema::hasTable('user_notifications')) {
            try {
                Schema::table('user_notifications', fn ($table) => $table->timestamp('read_at')->nullable());
            } catch (\Throwable) {
            }
        }
        $now = now();
        foreach ($userIds as $userId) {
            $row = [
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'kind' => (string) ($data['type'] ?? 'info'),
                'created_at' => $now,
            ];
            if (Schema::hasColumn('user_notifications', 'updated_at')) {
                $row['updated_at'] = $now;
            }
            DB::table('user_notifications')->insert(array_filter(
                $row,
                fn ($key) => Schema::hasColumn('user_notifications', $key),
                ARRAY_FILTER_USE_KEY,
            ));
        }
    }

    public function send(string $token, string $title, string $body, array $data = [], ?string $imageUrl = null): bool
    {
        if (! $imageUrl) {
            $brand = env('FCM_BRAND_IMAGE');
            $imageUrl = is_string($brand) && str_starts_with($brand, 'http') ? $brand : null;
        }
        $access = $this->accessToken();
        $project = $this->credentials()['project_id'] ?? 'karnacab-bf930';
        if (! $access || $token === '') {
            return false;
        }
        $payloadData = [];
        foreach ($data + [
            'title' => $title,
            'body' => $body,
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        ] as $key => $value) {
            if ($value === null) {
                continue;
            }
            $payloadData[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }
        if ($imageUrl) {
            $payloadData['image'] = $imageUrl;
            $payloadData['imageUrl'] = $imageUrl;
        }
        $message = [
            'message' => [
                'token' => $token,
                'notification' => array_filter([
                    'title' => $title,
                    'body' => $body,
                    'image' => $imageUrl,
                ]),
                'data' => $payloadData,
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => array_filter([
                        'channel_id' => (str_contains((string) ($data['type'] ?? ''), 'booking') || ($data['event'] ?? '') === 'new_booking') ? 'karnacab_booking' : 'karnacab_default',
                        'sound' => 'default',
                        'notification_priority' => 'PRIORITY_MAX',
                        'default_vibrate_timings' => false,
                        'vibrate_timings' => ['0s', '0.7s', '0.3s', '0.7s', '0.3s', '0.9s'],
                        'image' => $imageUrl,
                        'visibility' => 'PUBLIC',
                    ], fn ($value) => $value !== null && $value !== ''),
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'alert' => ['title' => $title, 'body' => $body],
                            'sound' => 'default',
                            'badge' => 1,
                            'mutable-content' => 1,
                        ],
                    ],
                    'fcm_options' => array_filter(['image' => $imageUrl]),
                ],
            ],
        ];
        try {
            $response = Http::withToken($access)
                ->acceptJson()
                ->timeout(8)
                ->post('https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send', $message);
            if (! $response->successful()) {
                Log::warning('fcm.send_failed', ['status' => $response->status(), 'body' => $response->body()]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('fcm.send_exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param  list<int>  $userIds
     * @return list<string>
     */
    private function tokensFor(array $userIds): array
    {
        $tokens = [];
        foreach (['push_devices', 'device_tokens'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->whereIn('user_id', $userIds)->pluck('token');
            foreach ($rows as $token) {
                if (is_string($token) && strlen($token) >= 20) {
                    $tokens[] = $token;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    private function accessToken(): ?string
    {
        $creds = $this->credentials();
        if (! $creds) {
            return null;
        }
        $now = time();
        $jwt = JWT::encode([
            'iss' => $creds['client_email'],
            'sub' => $creds['client_email'],
            'aud' => $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3500,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        ], $creds['private_key'], 'RS256');
        try {
            $response = Http::asForm()->timeout(8)->post($creds['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            $token = $response->json('access_token');

            return is_string($token) ? $token : null;
        } catch (\Throwable $e) {
            Log::warning('fcm.token_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function credentials(): ?array
    {
        $path = base_path('firebase-service-account.json');
        if (! is_file($path)) {
            $path = storage_path('app/firebase-service-account.json');
        }
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
