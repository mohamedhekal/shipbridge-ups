<?php

declare(strict_types=1);

return [
    'driver' => 'ups',
    'base_url' => env('UPS_BASE_URL', 'https://onlinetools.ups.com/api'),
    'timeout' => (int) env('UPS_TIMEOUT', 20),
    'client_id' => env('UPS_CLIENT_ID'),
    'client_secret' => env('UPS_CLIENT_SECRET'),
    'token' => env('UPS_TOKEN'),
    'status_map' => [
        'M' => 'created',
        'P' => 'picked_up',
        'I' => 'in_transit',
        'O' => 'out_for_delivery',
        'D' => 'delivered',
        'RS' => 'returned',
        'X' => 'exception',
    ],
];
