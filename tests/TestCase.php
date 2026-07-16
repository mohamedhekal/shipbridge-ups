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
        $app['config']->set('shipbridge.drivers.ups.base_url', 'https://ups.test');
        $app['config']->set('shipbridge.drivers.ups.client_id', 'test-client-id');
        $app['config']->set('shipbridge.drivers.ups.client_secret', 'test-client-secret');
        $app['config']->set('shipbridge.drivers.ups.account_number', 'A12345');
        $app['config']->set('shipbridge.drivers.ups.shipper_number', 'A12345');
        $app['config']->set('shipbridge.drivers.ups.service_code', '11');
        $app['config']->set('shipbridge.drivers.ups.transaction_src', 'shipbridge');
    }
}
