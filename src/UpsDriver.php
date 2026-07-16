<?php

declare(strict_types=1);

namespace Hekal\ShipBridge\Ups;

use Hekal\ShipBridge\Contracts\CarrierDriver;
use Hekal\ShipBridge\DTOs\CreateShipmentRequest;
use Hekal\ShipBridge\DTOs\ExchangeShipmentRequest;
use Hekal\ShipBridge\DTOs\LabelResult;
use Hekal\ShipBridge\DTOs\ReturnShipmentRequest;
use Hekal\ShipBridge\DTOs\ShipmentResult;
use Hekal\ShipBridge\DTOs\TrackingEvent;
use Hekal\ShipBridge\DTOs\TrackingResult;
use Hekal\ShipBridge\Enums\LabelFormat;
use Hekal\ShipBridge\Enums\ShipmentStatus;
use Hekal\ShipBridge\Exceptions\ShipBridgeException;
use Hekal\ShipBridge\Support\StatusNormalizer;
use Hekal\ShipBridge\Ups\Support\PayloadFactory;

/**
 * UPS REST API driver (OAuth2 Ship + Track).
 *
 * Label GIF/PDF is returned in the create-shipment response
 * (`PackageResults.ShippingLabel.GraphicImage`). {@see label()} falls back to
 * the public UPS tracking page when no stored image is available.
 */
final class UpsDriver implements CarrierDriver
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly UpsClient $client,
        private readonly PayloadFactory $payloads,
        private readonly StatusNormalizer $normalizer,
        private readonly array $config = [],
    ) {}

    public function createShipment(CreateShipmentRequest $request): ShipmentResult
    {
        $response = $this->client->ship($this->payloads->create($request));

        return $this->shipmentFromShipResponse($response, ShipmentStatus::Created);
    }

    public function track(string $trackingNumber): TrackingResult
    {
        $response = $this->client->track($trackingNumber);

        /** @var array<string, mixed> $trackResponse */
        $trackResponse = is_array($response['trackResponse'] ?? null)
            ? $response['trackResponse']
            : (is_array($response['TrackResponse'] ?? null) ? $response['TrackResponse'] : $response);

        /** @var list<mixed> $shipments */
        $shipments = is_array($trackResponse['shipment'] ?? null) ? $trackResponse['shipment'] : [];

        /** @var array<string, mixed> $shipment */
        $shipment = is_array($shipments[0] ?? null) ? $shipments[0] : [];

        /** @var list<mixed> $packages */
        $packages = is_array($shipment['package'] ?? null) ? $shipment['package'] : [];

        /** @var array<string, mixed> $package */
        $package = is_array($packages[0] ?? null) ? $packages[0] : [];

        $resolvedTracking = (string) ($package['trackingNumber'] ?? $trackingNumber);

        /** @var list<TrackingEvent> $events */
        $events = [];
        $activities = $package['activity'] ?? [];
        if (is_array($activities)) {
            foreach ($activities as $activity) {
                if (! is_array($activity)) {
                    continue;
                }

                $statusRaw = $this->activityStatus($activity);
                $location = null;
                if (isset($activity['location']['address']) && is_array($activity['location']['address'])) {
                    $addr = $activity['location']['address'];
                    $parts = array_filter([
                        isset($addr['city']) ? (string) $addr['city'] : null,
                        isset($addr['stateProvince']) ? (string) $addr['stateProvince'] : null,
                        isset($addr['countryCode']) ? (string) $addr['countryCode'] : null,
                    ]);
                    $location = $parts !== [] ? implode(', ', $parts) : null;
                }

                $occurredAt = null;
                if (isset($activity['date']) || isset($activity['time'])) {
                    $occurredAt = trim(((string) ($activity['date'] ?? '')).' '.((string) ($activity['time'] ?? '')));
                }

                $events[] = new TrackingEvent(
                    status: $this->normalizer->normalize($statusRaw),
                    description: (string) ($activity['status']['description'] ?? $statusRaw),
                    occurredAt: $occurredAt !== '' ? $occurredAt : null,
                    location: $location,
                );
            }
        }

        $latest = $events !== [] ? $events[0]->status : ShipmentStatus::InTransit;
        if ($events === [] && isset($package['currentStatus']['description'])) {
            $statusRaw = (string) ($package['currentStatus']['code'] ?? $package['currentStatus']['description'] ?? 'in_transit');
            $latest = $this->normalizer->normalize($statusRaw);
            $events[] = new TrackingEvent(
                status: $latest,
                description: (string) $package['currentStatus']['description'],
            );
        }

        return new TrackingResult(
            trackingNumber: $resolvedTracking,
            status: $latest,
            events: $events,
            raw: $response,
        );
    }

    public function label(string $shipmentId, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        // UPS embeds the label in the ship response; there is no separate label download
        // endpoint in this integration. Return the public tracking page (FedEx/Turbo style).
        $template = (string) ($this->config['tracking_url_template'] ?? 'https://www.ups.com/track?tracknum={tracking}');
        $url = str_replace('{tracking}', rawurlencode($shipmentId), $template);

        return new LabelResult(
            shipmentId: $shipmentId,
            format: $format,
            contents: '',
            base64Encoded: false,
            url: $url,
        );
    }

    public function createReturn(ReturnShipmentRequest $request): ShipmentResult
    {
        $response = $this->client->ship($this->payloads->returnShipment($request));

        return $this->shipmentFromShipResponse($response, ShipmentStatus::Returned);
    }

    public function createExchange(ExchangeShipmentRequest $request): ShipmentResult
    {
        $response = $this->client->ship($this->payloads->exchange($request));

        return $this->shipmentFromShipResponse($response, ShipmentStatus::Exchanged);
    }

    /**
     * Extract label base64 from a ship API raw response.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function extractLabelContents(array $raw): ?string
    {
        $packageResults = self::packageResults($raw);
        if ($packageResults === null) {
            return null;
        }

        /** @var list<mixed> $rows */
        $rows = isset($packageResults[0]) ? $packageResults : [$packageResults];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = $row['ShippingLabel'] ?? $row['shippingLabel'] ?? null;
            if (! is_array($label)) {
                continue;
            }

            $graphic = (string) ($label['GraphicImage'] ?? $label['graphicImage'] ?? '');
            if ($graphic !== '') {
                return $graphic;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function shipmentFromShipResponse(array $response, ShipmentStatus $fallbackStatus): ShipmentResult
    {
        $packageResults = self::packageResults($response);
        if ($packageResults === null) {
            throw ShipBridgeException::carrierFailed('UPS ship response missing PackageResults.');
        }

        /** @var array<string, mixed> $firstPackage */
        $firstPackage = isset($packageResults[0]) && is_array($packageResults[0])
            ? $packageResults[0]
            : $packageResults;

        $trackingNumber = (string) ($firstPackage['TrackingNumber'] ?? $firstPackage['trackingNumber'] ?? '');
        $shipmentId = (string) (
            $response['ShipmentResponse']['ShipmentResults']['ShipmentIdentificationNumber']
            ?? $response['shipResponse']['shipmentResults']['shipmentIdentificationNumber']
            ?? $trackingNumber
        );

        if ($trackingNumber === '' && $shipmentId === '') {
            throw ShipBridgeException::carrierFailed('UPS ship response missing tracking number.');
        }

        $tracking = $trackingNumber !== '' ? $trackingNumber : $shipmentId;
        $template = (string) ($this->config['tracking_url_template'] ?? 'https://www.ups.com/track?tracknum={tracking}');
        $labelUrl = str_replace('{tracking}', rawurlencode($tracking), $template);

        return new ShipmentResult(
            id: $shipmentId !== '' ? $shipmentId : $tracking,
            trackingNumber: $tracking,
            status: $fallbackStatus,
            carrier: 'ups',
            labelUrl: $labelUrl,
            raw: $response,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|list<mixed>|null
     */
    private static function packageResults(array $response): ?array
    {
        $results = $response['ShipmentResponse']['ShipmentResults']
            ?? $response['shipResponse']['shipmentResults']
            ?? null;

        if (! is_array($results)) {
            return null;
        }

        $packageResults = $results['PackageResults'] ?? $results['packageResults'] ?? null;

        return is_array($packageResults) ? $packageResults : null;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function activityStatus(array $activity): string
    {
        if (isset($activity['status']) && is_array($activity['status'])) {
            $status = $activity['status'];

            return (string) ($status['type'] ?? $status['code'] ?? $status['description'] ?? 'in_transit');
        }

        return (string) ($activity['status'] ?? 'in_transit');
    }
}
