<?php

declare(strict_types=1);

return [
    'driver' => 'ups',

    /*
    |--------------------------------------------------------------------------
    | UPS REST API host (no trailing path)
    |--------------------------------------------------------------------------
    | Production: https://onlinetools.ups.com
    | CIE/Sandbox: https://wwwcie.ups.com
    */
    'base_url' => env('UPS_BASE_URL', 'https://onlinetools.ups.com'),

    'timeout' => (int) env('UPS_TIMEOUT', 30),

    'api_version' => env('UPS_API_VERSION', 'v1'),

    /*
    |--------------------------------------------------------------------------
    | OAuth2 client credentials
    |--------------------------------------------------------------------------
    | POST /security/v1/oauth/token with Basic auth (client_id:client_secret).
    | Set UPS_TOKEN to skip OAuth and use a pre-issued bearer token.
    */
    'client_id' => env('UPS_CLIENT_ID'),
    'client_secret' => env('UPS_CLIENT_SECRET'),
    'token' => env('UPS_TOKEN'),

    'account_number' => env('UPS_ACCOUNT_NUMBER'),
    'shipper_number' => env('UPS_SHIPPER_NUMBER', env('UPS_ACCOUNT_NUMBER')),

    /*
    |--------------------------------------------------------------------------
    | Default shipment options
    |--------------------------------------------------------------------------
    | 11 = UPS Standard, 65 = UPS Saver (confirm for your lane/account)
    */
    'service_code' => env('UPS_SERVICE_CODE', '11'),
    'return_service_code' => env('UPS_RETURN_SERVICE_CODE', '9'),
    'packaging_code' => env('UPS_PACKAGING_CODE', '02'),
    'weight_unit' => env('UPS_WEIGHT_UNIT', 'KGS'),
    'dimension_unit' => env('UPS_DIMENSION_UNIT', 'CM'),
    'label_image_format' => env('UPS_LABEL_IMAGE_FORMAT', 'GIF'),
    'currency' => env('UPS_CURRENCY', 'USD'),
    'transaction_src' => env('UPS_TRANSACTION_SRC', 'shipbridge'),

    'tracking_url_template' => env('UPS_TRACKING_URL_TEMPLATE', 'https://www.ups.com/track?tracknum={tracking}'),

    'status_map' => [
        'M' => 'created',
        'P' => 'picked_up',
        'I' => 'in_transit',
        'O' => 'out_for_delivery',
        'D' => 'delivered',
        'RS' => 'returned',
        'X' => 'exception',
        'Manifest Pickup' => 'picked_up',
        'In Transit' => 'in_transit',
        'Out For Delivery' => 'out_for_delivery',
        'Delivered' => 'delivered',
        'Exception' => 'exception',
        'Returned to Sender' => 'returned',
    ],
];
