<?php

return [
    'jwt_ttl' => 60 * 60 * 24 * 7,
    'api_public_url' => rtrim((string) env('API_PUBLIC_URL', env('APP_URL', 'https://api.karnacab.in')), '/'),
    'leads_notify_email' => (string) env('LEADS_NOTIFY_EMAIL', env('MAIL_FROM_ADDRESS', 'website.fazlu@gmail.com')),
    // Cached via config — do not read env() in AuthService after php artisan config:cache.
    'otp_show_in_app' => filter_var(env('OTP_SHOW_IN_APP', false), FILTER_VALIDATE_BOOL),
    'static_test_otp_enabled' => filter_var(env('STATIC_TEST_OTP_ENABLED', false), FILTER_VALIDATE_BOOL),
    'demo_otp_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'DEMO_OTP_PHONES',
        '7428059960,7428059961,7065876175,7065876176,8595238890,9560210876,9560210875',
    ))))),
    'demo_otp_code' => (string) env('DEMO_OTP_CODE', '123456'),
    'fast2sms_api_key' => (string) env('FAST2SMS_API_KEY', ''),
    'fast2sms_template_id' => (string) env('FAST2SMS_TEMPLATE_ID', '201877'),
    'fast2sms_sender_id' => (string) env('FAST2SMS_SENDER_ID', 'KARCAB'),
    'google_maps_key' => (string) env('GOOGLE_MAPS_API', ''),
    'cors_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ORIGINS',
        'http://localhost:8000,https://karnacab.jodoocorp.in',
    ))))),
    'ride_types' => [
        ['key' => 'LOCAL_CAB', 'label' => 'Local Cab'],
        ['key' => 'ONE_WAY', 'label' => 'One Way'],
        ['key' => 'ROUND_WAY', 'label' => 'Round Way'],
        ['key' => 'RENTAL', 'label' => 'Rental'],
        ['key' => 'SCHEDULE', 'label' => 'Schedule'],
        ['key' => 'OUTSTATION', 'label' => 'Outstation'],
        ['key' => 'AIRPORT', 'label' => 'Airport'],
        ['key' => 'RAILWAY', 'label' => 'Railway'],
        ['key' => 'MULTI_STOP', 'label' => 'Multi-stop'],
    ],
    'vehicle_types' => [
        ['key' => 'BIKE', 'label' => 'Bike', 'seats' => 1],
        ['key' => 'AUTO', 'label' => 'Auto', 'seats' => 3],
        ['key' => 'E_RICKSHAW', 'label' => 'E-Rickshaw', 'seats' => 3],
        ['key' => 'MINI', 'label' => 'Mini', 'seats' => 4],
        ['key' => 'SEDAN', 'label' => 'Sedan', 'seats' => 4],
        ['key' => 'SUV', 'label' => 'SUV', 'seats' => 6],
        ['key' => 'TRAVELLER', 'label' => 'Traveller', 'seats' => 12],
    ],
];
