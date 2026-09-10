<?php

return [
    'jwt_ttl' => 60 * 60 * 24 * 7,
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
