<?php

declare(strict_types=1);

use Hekal\ShipBridge\DTOs\Address;
use Hekal\ShipBridge\DTOs\CreateShipmentRequest;
use Hekal\ShipBridge\DTOs\Parcel;
use Hekal\ShipBridge\DTOs\ReturnShipmentRequest;
use Hekal\ShipBridge\Enums\ShipmentStatus;
use Hekal\ShipBridge\Exceptions\ShipBridgeException;
use Hekal\ShipBridge\Facades\ShipBridge;
use Hekal\ShipBridge\Ups\UpsDriver;
use Illuminate\Support\Facades\Http;

function fakeUpsOAuth(): void
{
    Http::fake([
        'https://ups.test/security/v1/oauth/token' => Http::response([
            'access_token' => 'ups-test-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
    ]);
}

function fakeUpsShip(array $overrides = []): void
{
    Http::fake(array_merge([
        'https://ups.test/security/v1/oauth/token' => Http::response([
            'access_token' => 'ups-test-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
        'https://ups.test/api/shipments/v1/ship' => Http::response([
            'ShipmentResponse' => [
                'Response' => [
                    'ResponseStatus' => [
                        'Code' => '1',
                        'Description' => 'Success',
                    ],
                ],
                'ShipmentResults' => [
                    'ShipmentIdentificationNumber' => 'SHIP-001',
                    'PackageResults' => [
                        'TrackingNumber' => '1Z999AA10123456784',
                        'ShippingLabel' => [
                            'ImageFormat' => [
                                'Code' => 'GIF',
                            ],
                            'GraphicImage' => base64_encode('GIF89a-fake'),
                        ],
                    ],
                ],
            ],
        ], 200),
    ], $overrides));
}

it('creates a UPS shipment via OAuth and ship API', function (): void {
    fakeUpsShip();

    $result = ShipBridge::driver('ups')->createShipment(new CreateShipmentRequest(
        origin: new Address('Warehouse', '1 Industrial Rd', 'Cairo', 'EG', phone: '01011111111', postalCode: '11511'),
        destination: new Address('Customer', '12 Nile St', 'Giza', 'EG', phone: '01000000000', state: 'GZ', postalCode: '12511'),
        parcels: [new Parcel(weightKg: 1.5, description: 'Books')],
        reference: 'ORD-100',
        metadata: [
            'cod' => 50,
            'currency' => 'USD',
        ],
    ));

    expect($result->trackingNumber)->toBe('1Z999AA10123456784')
        ->and($result->id)->toBe('SHIP-001')
        ->and($result->carrier)->toBe('ups')
        ->and($result->status)->toBe(ShipmentStatus::Created)
        ->and($result->labelUrl)->toBe('https://www.ups.com/track?tracknum=1Z999AA10123456784')
        ->and(UpsDriver::extractLabelContents($result->raw))->not->toBeEmpty();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/security/v1/oauth/token')) {
            return false;
        }

        return $request->hasHeader('Authorization')
            && str_contains((string) $request->header('Authorization')[0], 'Basic');
    });

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/shipments/v1/ship')) {
            return false;
        }

        $body = $request->data();

        return $request->hasHeader('Authorization', 'Bearer ups-test-token')
            && $request->hasHeader('transId')
            && $request->hasHeader('transactionSrc', 'shipbridge')
            && ($body['ShipmentRequest']['Shipment']['ShipTo']['Phone']['Number'] ?? null) === '01000000000'
            && ($body['ShipmentRequest']['Shipment']['Service']['Code'] ?? null) === '11'
            && ($body['ShipmentRequest']['Shipment']['PaymentInformation']['ShipmentCharge']['BillShipper']['AccountNumber'] ?? null) === 'A12345'
            && isset($body['ShipmentRequest']['Shipment']['ShipmentServiceOptions']['COD']);
    });
});

it('tracks a UPS shipment', function (): void {
    Http::fake([
        'https://ups.test/security/v1/oauth/token' => Http::response([
            'access_token' => 'ups-test-token',
        ], 200),
        'https://ups.test/api/track/v1/details/*' => Http::response([
            'trackResponse' => [
                'shipment' => [[
                    'package' => [[
                        'trackingNumber' => '1Z999AA10123456784',
                        'activity' => [[
                            'status' => [
                                'type' => 'I',
                                'description' => 'In Transit',
                            ],
                            'date' => '20260716',
                            'time' => '101500',
                            'location' => [
                                'address' => [
                                    'city' => 'Cairo',
                                    'countryCode' => 'EG',
                                ],
                            ],
                        ]],
                    ]],
                ]],
            ],
        ], 200),
    ]);

    $tracking = ShipBridge::driver('ups')->track('1Z999AA10123456784');

    expect($tracking->trackingNumber)->toBe('1Z999AA10123456784')
        ->and($tracking->status)->toBe(ShipmentStatus::InTransit)
        ->and($tracking->events)->toHaveCount(1);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/api/track/v1/details/1Z999AA10123456784')
            && $request->hasHeader('transactionSrc', 'shipbridge');
    });
});

it('returns UPS tracking URL from label()', function (): void {
    $label = ShipBridge::driver('ups')->label('1Z999AA10123456784');

    expect($label->url)->toBe('https://www.ups.com/track?tracknum=1Z999AA10123456784')
        ->and($label->contents)->toBe('')
        ->and($label->base64Encoded)->toBeFalse();
});

it('creates a return shipment', function (): void {
    fakeUpsShip([
        'https://ups.test/api/shipments/v1/ship' => Http::response([
            'ShipmentResponse' => [
                'Response' => ['ResponseStatus' => ['Code' => '1']],
                'ShipmentResults' => [
                    'ShipmentIdentificationNumber' => 'RET-1',
                    'PackageResults' => [
                        'TrackingNumber' => '1Z999AA10999999999',
                        'ShippingLabel' => ['GraphicImage' => base64_encode('GIF')],
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = ShipBridge::driver('ups')->createReturn(new ReturnShipmentRequest(
        originalShipmentId: 'ORD-1',
        returnTo: new Address('Warehouse', '1 Industrial', 'Cairo', 'EG', phone: '01011111111'),
        pickupFrom: new Address('Customer', '12 Nile', 'Giza', 'EG', phone: '01000000000'),
        reason: 'Wrong size',
    ));

    expect($result->status)->toBe(ShipmentStatus::Returned);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/shipments/v1/ship')) {
            return false;
        }

        return ($request->data()['ShipmentRequest']['Shipment']['Service']['Code'] ?? null) === '9';
    });
});

it('requires ShipTo phone', function (): void {
    fakeUpsShip();

    ShipBridge::driver('ups')->createShipment(new CreateShipmentRequest(
        origin: new Address('Warehouse', '1 Industrial', 'Cairo', 'EG', phone: '01011111111'),
        destination: new Address('Customer', '12 Nile', 'Giza', 'EG'),
        parcels: [new Parcel(weightKg: 1.0)],
    ));
})->throws(ShipBridgeException::class);

it('uses configured bearer token without OAuth call', function (): void {
    config()->set('shipbridge.drivers.ups.token', 'preissued-token');
    config()->set('shipbridge.drivers.ups.client_id', null);
    config()->set('shipbridge.drivers.ups.client_secret', null);

    Http::fake([
        'https://ups.test/api/shipments/v1/ship' => Http::response([
            'ShipmentResponse' => [
                'Response' => ['ResponseStatus' => ['Code' => '1']],
                'ShipmentResults' => [
                    'ShipmentIdentificationNumber' => 'S2',
                    'PackageResults' => [
                        'TrackingNumber' => '1ZPREISSUED',
                        'ShippingLabel' => ['GraphicImage' => base64_encode('GIF')],
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = ShipBridge::driver('ups')->createShipment(new CreateShipmentRequest(
        origin: new Address('Warehouse', '1 Industrial', 'Cairo', 'EG', phone: '01011111111'),
        destination: new Address('Customer', '12 Nile', 'Giza', 'EG', phone: '01000000000'),
        parcels: [new Parcel(weightKg: 1.0)],
    ));

    expect($result->trackingNumber)->toBe('1ZPREISSUED');

    Http::assertNotSent(function ($request): bool {
        return str_contains($request->url(), '/oauth/token');
    });
});
