<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\PackageTestbench\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    use RefreshDatabase;

    /**
     * Overriding this means calling the parent, or the application key the
     * testbench sets is lost and anything rendering dies on it instead of on
     * whatever the test was about.
     *
     * The array cache store is chosen because idempotency claims take a lock,
     * and the array store is the one every environment has.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }
}
