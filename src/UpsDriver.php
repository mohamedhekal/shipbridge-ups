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
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * UPS driver for ShipBridge (Global).
 *
 * Talks to a JSON shipping API. Point base_url/credentials at your UPS
 * sandbox or production environment. Response fields expected:
 * id, tracking_number, status, label_url, events[].
 */
final class UpsDriver implements CarrierDriver
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly StatusNormalizer $normalizer,
        private readonly array $config,
    ) {}

    public function createShipment(CreateShipmentRequest $request): ShipmentResult
    {
        $payload = array_merge($request->toArray(), [
            'carrier' => 'ups',
        ]);

        $response = $this->client()->post('shipments', $payload);
        $this->ensureOk($response);

        return $this->shipmentFromPayload($response->json() ?? []);
    }

    public function track(string $trackingNumber): TrackingResult
    {
        $response = $this->client()->get("shipments/track/{$trackingNumber}");
        $this->ensureOk($response);

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];
        $status = $this->normalizer->normalize((string) ($payload['status'] ?? 'exception'));

        /** @var list<TrackingEvent> $events */
        $events = [];
        foreach ((array) ($payload['events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $events[] = new TrackingEvent(
                status: $this->normalizer->normalize((string) ($event['status'] ?? $status->value)),
                description: (string) ($event['description'] ?? ''),
                occurredAt: isset($event['occurred_at']) ? (string) $event['occurred_at'] : null,
                location: isset($event['location']) ? (string) $event['location'] : null,
            );
        }

        return new TrackingResult(
            trackingNumber: (string) ($payload['tracking_number'] ?? $trackingNumber),
            status: $status,
            events: $events,
            raw: $payload,
        );
    }

    public function label(string $shipmentId, LabelFormat $format = LabelFormat::Pdf): LabelResult
    {
        $response = $this->client()->get("shipments/{$shipmentId}/label", [
            'format' => $format->value,
        ]);
        $this->ensureOk($response);

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return new LabelResult(
            shipmentId: $shipmentId,
            format: $format,
            contents: (string) ($payload['contents'] ?? ''),
            base64Encoded: (bool) ($payload['base64'] ?? true),
            url: isset($payload['url']) ? (string) $payload['url'] : null,
        );
    }

    public function createReturn(ReturnShipmentRequest $request): ShipmentResult
    {
        $response = $this->client()->post(
            "shipments/{$request->originalShipmentId}/returns",
            $request->toArray(),
        );
        $this->ensureOk($response);

        return $this->shipmentFromPayload($response->json() ?? []);
    }

    public function createExchange(ExchangeShipmentRequest $request): ShipmentResult
    {
        $response = $this->client()->post(
            "shipments/{$request->originalShipmentId}/exchanges",
            $request->toArray(),
        );
        $this->ensureOk($response);

        return $this->shipmentFromPayload($response->json() ?? []);
    }

    private function client(): PendingRequest
    {
        $pending = $this->http
            ->baseUrl(rtrim((string) ($this->config['base_url'] ?? ''), '/'))
            ->timeout((int) ($this->config['timeout'] ?? 20))
            ->acceptJson()
            ->withHeaders([
                'X-ShipBridge-Carrier' => 'ups',
            ]);

        $token = $this->config['token'] ?? $this->config['api_key'] ?? $this->config['passkey'] ?? null;
        if (is_string($token) && $token !== '') {
            $pending = $pending->withToken($token);
        }

        $username = $this->config['username'] ?? null;
        $password = $this->config['password'] ?? null;
        if (is_string($username) && is_string($password) && $username !== '' && $password !== '') {
            $pending = $pending->withBasicAuth($username, $password);
        }

        return $pending;
    }

    private function ensureOk(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw ShipBridgeException::carrierFailed(
            (string) ($response->json('message') ?? $response->body()),
            $response->status(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shipmentFromPayload(array $payload): ShipmentResult
    {
        $statusRaw = (string) ($payload['status'] ?? ShipmentStatus::Created->value);

        return new ShipmentResult(
            id: (string) ($payload['id'] ?? ''),
            trackingNumber: (string) ($payload['tracking_number'] ?? ''),
            status: $this->normalizer->normalize($statusRaw),
            carrier: 'ups',
            labelUrl: isset($payload['label_url']) ? (string) $payload['label_url'] : null,
            raw: $payload,
        );
    }
}
