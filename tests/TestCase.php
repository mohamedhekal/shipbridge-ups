<?php

declare(strict_types=1);

namespace Hekal\ShipBridge\Ups\Tests;

use Hekal\ShipBridge\ShipBridgeServiceProvider;
use Hekal\ShipBridge\Ups\UpsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ShipBridgeServiceProvider::class,
            UpsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('shipbridge.default', 'ups');
        $app['config']->set('shipbridge.drivers.ups.base_url', 'https://ups.test/v1');
        $app['config']->set('shipbridge.drivers.ups.token', 'test-token');
    }
}
