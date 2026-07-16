<?php

declare(strict_types=1);

namespace Hekal\ShipBridge\Ups\Support;

use Hekal\ShipBridge\DTOs\Address;
use Hekal\ShipBridge\DTOs\CreateShipmentRequest;
use Hekal\ShipBridge\DTOs\ExchangeShipmentRequest;
use Hekal\ShipBridge\DTOs\Parcel;
use Hekal\ShipBridge\DTOs\ReturnShipmentRequest;
use Hekal\ShipBridge\Exceptions\ShipBridgeException;

/**
 * Maps ShipBridge DTOs → UPS ShipmentRequest JSON.
 *
 * Metadata keys:
 * - service_code, packaging_code, description, cod, currency
 * - shipper_number / account_number overrides
 * - sender_* / receiver_* address overrides
 */
final class PayloadFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(CreateShipmentRequest $request): array
    {
        return $this->shipmentRequest(
            shipper: $request->origin,
            shipTo: $request->destination,
            shipFrom: $request->origin,
            parcels: $request->parcels,
            reference: $request->reference,
            metadata: $request->metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function returnShipment(ReturnShipmentRequest $request): array
    {
        $pickup = $request->pickupFrom ?? $request->returnTo;
        $meta = array_merge($request->metadata, [
            'description' => $request->reason ?? 'Return shipment',
            'service_code' => $request->metadata['service_code'] ?? $this->config['return_service_code'] ?? '9',
        ]);

        return $this->shipmentRequest(
            shipper: $request->returnTo,
            shipTo: $request->returnTo,
            shipFrom: $pickup,
            parcels: $request->parcels ?? [new Parcel(weightKg: 1.0)],
            reference: $request->originalShipmentId,
            metadata: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function exchange(ExchangeShipmentRequest $request): array
    {
        $meta = array_merge($request->metadata, [
            'description' => $request->reason ?? 'Exchange shipment',
        ]);

        return $this->shipmentRequest(
            shipper: $request->origin,
            shipTo: $request->destination,
            shipFrom: $request->origin,
            parcels: $request->outboundParcels,
            reference: $request->originalShipmentId,
            metadata: $meta,
        );
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function shipmentRequest(
        Address $shipper,
        Address $shipTo,
        Address $shipFrom,
        array $parcels,
        ?string $reference,
        array $metadata,
    ): array {
        $phone = $shipTo->phone ?? (isset($metadata['phone']) ? (string) $metadata['phone'] : null);
        if ($phone === null || $phone === '') {
            throw ShipBridgeException::carrierFailed('UPS requires ShipTo phone (Address::$phone).');
        }

        $account = (string) (
            $metadata['account_number']
            ?? $metadata['shipper_number']
            ?? $this->config['shipper_number']
            ?? $this->config['account_number']
            ?? ''
        );

        if ($account === '') {
            throw ShipBridgeException::carrierFailed('UPS requires account_number / shipper_number in config or metadata.');
        }

        $weight = 0.0;
        foreach ($parcels as $parcel) {
            $weight += $parcel->weightKg;
        }
        if ($weight <= 0) {
            $weight = 1.0;
        }

        $weightUnit = (string) ($metadata['weight_unit'] ?? $this->config['weight_unit'] ?? 'KGS');
        $dimensionUnit = (string) ($metadata['dimension_unit'] ?? $this->config['dimension_unit'] ?? 'CM');
        $serviceCode = (string) ($metadata['service_code'] ?? $this->config['service_code'] ?? '11');
        $packagingCode = (string) ($metadata['packaging_code'] ?? $this->config['packaging_code'] ?? '02');
        $labelFormat = (string) ($metadata['label_image_format'] ?? $this->config['label_image_format'] ?? 'GIF');
        $description = (string) ($metadata['description'] ?? $reference ?? 'ShipBridge shipment');

        $shipment = [
            'Description' => $description,
            'Shipper' => $this->party($shipper, $metadata, 'shipper', $account),
            'ShipTo' => $this->party($shipTo, $metadata, 'receiver'),
            'ShipFrom' => $this->party($shipFrom, $metadata, 'sender'),
            'PaymentInformation' => [
                'ShipmentCharge' => [
                    'Type' => '01',
                    'BillShipper' => [
                        'AccountNumber' => $account,
                    ],
                ],
            ],
            'Service' => [
                'Code' => $serviceCode,
            ],
            'Package' => $this->package($parcels, $metadata, $packagingCode, $weightUnit, $dimensionUnit, $weight),
        ];

        $cod = (float) ($metadata['cod'] ?? $metadata['cod_amount'] ?? 0);
        if ($cod > 0) {
            $currency = (string) ($metadata['currency'] ?? $this->config['currency'] ?? 'USD');
            $shipment['ShipmentServiceOptions'] = [
                'COD' => [
                    'CODFundsCode' => (string) ($metadata['cod_funds_code'] ?? '0'),
                    'CODAmount' => [
                        'CurrencyCode' => $currency,
                        'MonetaryValue' => $this->formatNumber($cod),
                    ],
                ],
            ];
        }

        return [
            'ShipmentRequest' => [
                'Request' => [
                    'RequestOption' => (string) ($metadata['request_option'] ?? 'nonvalidate'),
                ],
                'Shipment' => $shipment,
                'LabelSpecification' => [
                    'LabelImageFormat' => [
                        'Code' => $labelFormat,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function package(
        array $parcels,
        array $metadata,
        string $packagingCode,
        string $weightUnit,
        string $dimensionUnit,
        float $weight,
    ): array {
        $first = $parcels[0] ?? new Parcel(weightKg: $weight);
        $package = [
            'Packaging' => [
                'Code' => $packagingCode,
            ],
            'PackageWeight' => [
                'UnitOfMeasurement' => [
                    'Code' => $weightUnit,
                ],
                'Weight' => $this->formatNumber($weight),
            ],
        ];

        $length = (float) ($metadata['length'] ?? $first->lengthCm ?? 0);
        $width = (float) ($metadata['width'] ?? $first->widthCm ?? 0);
        $height = (float) ($metadata['height'] ?? $first->heightCm ?? 0);

        if ($length > 0 && $width > 0 && $height > 0) {
            $package['Dimensions'] = [
                'UnitOfMeasurement' => [
                    'Code' => $dimensionUnit,
                ],
                'Length' => $this->formatNumber($length),
                'Width' => $this->formatNumber($width),
                'Height' => $this->formatNumber($height),
            ];
        }

        $description = $first->description !== '' ? $first->description : (string) ($metadata['package_description'] ?? 'Package');
        $package['Description'] = $description;

        return $package;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function party(
        Address $address,
        array $metadata,
        string $role,
        ?string $shipperNumber = null,
    ): array {
        $prefix = match ($role) {
            'shipper' => 'shipper_',
            'sender' => 'sender_',
            default => 'receiver_',
        };

        $phone = $address->phone
            ?? (isset($metadata[$prefix.'phone']) ? (string) $metadata[$prefix.'phone'] : null)
            ?? ($role === 'receiver' && isset($metadata['phone']) ? (string) $metadata['phone'] : null)
            ?? '';

        $lines = array_values(array_filter([
            (string) ($metadata[$prefix.'line1'] ?? $address->line1),
            isset($metadata[$prefix.'line2']) ? (string) $metadata[$prefix.'line2'] : ($address->line2 ?? ''),
        ], static fn (string $line): bool => $line !== ''));

        $party = [
            'Name' => (string) ($metadata[$prefix.'name'] ?? $address->name),
            'AttentionName' => (string) ($metadata[$prefix.'attention'] ?? $address->name),
            'Phone' => [
                'Number' => $phone,
            ],
            'Address' => [
                'AddressLine' => $lines !== [] ? $lines : [$address->line1],
                'City' => (string) ($metadata[$prefix.'city'] ?? $address->city),
                'StateProvinceCode' => (string) ($metadata[$prefix.'state'] ?? $address->state ?? ''),
                'PostalCode' => (string) ($metadata[$prefix.'postal_code'] ?? $address->postalCode ?? ''),
                'CountryCode' => (string) ($metadata[$prefix.'country_code'] ?? $address->countryCode),
            ],
        ];

        if ($shipperNumber !== null && $shipperNumber !== '') {
            $party['ShipperNumber'] = $shipperNumber;
        }

        $email = $address->email ?? (isset($metadata[$prefix.'email']) ? (string) $metadata[$prefix.'email'] : null);
        if (is_string($email) && $email !== '') {
            $party['EMailAddress'] = $email;
        }

        return $party;
    }

    private function formatNumber(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
