<?php

declare(strict_types=1);

namespace Hekal\ShipBridge\Ups;

use Hekal\ShipBridge\Facades\ShipBridge;
use Hekal\ShipBridge\Support\StatusNormalizer;
use Hekal\ShipBridge\Ups\Support\PayloadFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

final class UpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ups.php', 'shipbridge.drivers.ups');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ups.php' => config_path('shipbridge-ups.php'),
        ], 'shipbridge-ups-config');

        ShipBridge::extend('ups', function ($app, array $config): UpsDriver {
            /** @var array<string, string> $aliases */
            $aliases = config('shipbridge.status_aliases', []);
            /** @var array<string, string> $driverMap */
            $driverMap = $config['status_map'] ?? [];

            return new UpsDriver(
                client: new UpsClient($app->make(HttpFactory::class), $config),
                payloads: new PayloadFactory($config),
                normalizer: new StatusNormalizer(array_merge($aliases, $driverMap)),
                config: $config,
            );
        });
    }
}
