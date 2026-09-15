<?php

namespace Cyberwizard\ScheduleCatchup\Tests;

use Cyberwizard\ScheduleCatchup\Console\ScheduleCatchupServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ScheduleCatchupServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $app['config']->set('cache.default', 'array');
    }

}
