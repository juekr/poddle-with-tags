<?php

namespace Tests;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Minimal container just so facades like Http::fake() work, without
        // needing the full orchestra/testbench Laravel-app bootstrap (which
        // isn't otherwise used by this library's tests).
        $container = new Container();
        $container->singleton(Factory::class, static fn () => new Factory());
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }
}
