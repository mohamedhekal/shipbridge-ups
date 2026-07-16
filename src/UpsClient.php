<?php

declare(strict_types=1);

namespace Hekal\ShipBridge\Ups;

use Hekal\ShipBridge\Exceptions\ShipBridgeException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * UPS REST API client (OAuth2 + Ship + Track).
 *
 * Live: https://onlinetools.ups.com
 * CIE:  https://wwwcie.ups.com
 */
final class UpsClient
{
    private ?string $accessToken = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function ship(array $payload): array
    {
        $version = (string) ($this->config['api_version'] ?? 'v1');
        $response = $this->authorized()
            ->withHeaders($this->transactionHeaders())
            ->post("/api/shipments/{$version}/ship", $payload);

        return $this->decode($response, 'UPS ship request failed.');
    }

    /**
     * @return array<string, mixed>
     */
    public function track(string $inquiryNumber): array
    {
        $response = $this->authorized()
            ->withHeaders($this->transactionHeaders())
            ->get('/api/track/v1/details/'.rawurlencode($inquiryNumber));

        return $this->decode($response, 'UPS track request failed.');
    }

    /**
     * @return array<string, string>
     */
    private function transactionHeaders(): array
    {
        return [
            'transId' => $this->transactionId(),
            'transactionSrc' => (string) ($this->config['transaction_src'] ?? 'shipbridge'),
        ];
    }

    private function transactionId(): string
    {
        return substr(str_replace('-', '', (string) str()->uuid()), 0, 32);
    }

    private function authorized(): PendingRequest
    {
        return $this->request()->withToken($this->token());
    }

    private function token(): string
    {
        $configured = $this->config['token'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if ($this->accessToken !== null && $this->accessToken !== '') {
            return $this->accessToken;
        }

        $clientId = $this->config['client_id'] ?? null;
        $clientSecret = $this->config['client_secret'] ?? null;

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            throw ShipBridgeException::carrierFailed('UPS requires UPS_CLIENT_ID and UPS_CLIENT_SECRET (or UPS_TOKEN).');
        }

        $response = $this->request()
            ->withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post('/security/v1/oauth/token', [
                'grant_type' => 'client_credentials',
            ]);

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        if (! $response->successful()) {
            $message = (string) ($json['response']['errors'][0]['message'] ?? $json['message'] ?? $response->body());

            throw ShipBridgeException::carrierFailed(
                $message !== '' ? $message : 'UPS OAuth token request failed.',
                $response->status(),
            );
        }

        $token = (string) ($json['access_token'] ?? '');
        if ($token === '') {
            throw ShipBridgeException::carrierFailed('UPS OAuth response missing access_token.');
        }

        $this->accessToken = $token;

        return $token;
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) ($this->config['base_url'] ?? 'https://onlinetools.ups.com'), '/'))
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-ShipBridge-Carrier' => 'ups',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $fallback): array
    {
        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        if ($response->successful() && ! $this->hasErrors($json)) {
            return $json;
        }

        $message = $this->errorMessage($json, $response);

        throw ShipBridgeException::carrierFailed(
            $message !== '' ? $message : $fallback,
            $response->status(),
        );
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function hasErrors(array $json): bool
    {
        if (isset($json['response']['errors']) && is_array($json['response']['errors']) && $json['response']['errors'] !== []) {
            return true;
        }

        foreach (['ShipmentResponse', 'shipResponse'] as $root) {
            if (! isset($json[$root]) || ! is_array($json[$root])) {
                continue;
            }

            /** @var array<string, mixed> $rootPayload */
            $rootPayload = $json[$root];
            $status = $rootPayload['Response']['ResponseStatus']['Code']
                ?? $rootPayload['response']['responseStatus']['code']
                ?? null;

            if ($status !== null && (string) $status !== '1') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function errorMessage(array $json, Response $response): string
    {
        $errors = $json['response']['errors'] ?? $json['Response']['errors'] ?? null;
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            return (string) ($errors[0]['message'] ?? $errors[0]['code'] ?? '');
        }

        foreach (['ShipmentResponse', 'shipResponse'] as $root) {
            if (! isset($json[$root]['Response']['ResponseStatus']) || ! is_array($json[$root]['Response']['ResponseStatus'])) {
                continue;
            }

            $desc = (string) ($json[$root]['Response']['ResponseStatus']['Description'] ?? '');

            if ($desc !== '') {
                return $desc;
            }
        }

        return (string) ($json['message'] ?? $response->body());
    }
}
