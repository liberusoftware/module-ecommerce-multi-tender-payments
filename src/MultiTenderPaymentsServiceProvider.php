<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesTenderCapacity;

/**
 * The module's only registration.
 *
 * Note what is **not** here: neither
 * {@see ResolvesPayableTotal}
 * nor {@see ResolvesTenderCapacity}
 * has a default binding, and that is deliberate. A default would mean a
 * half-configured deployment quietly treating the order total as zero, or a
 * gift card as bottomless. Unbound, it fails loudly the moment anything tries
 * to record money — which is the correct failure direction: such a deployment
 * can read a plan and can compute nothing that moves money.
 *
 * The package also declares no `extra.laravel.providers`, so Composer
 * installing it boots nothing. Enablement is the host's explicit decision.
 */
final class MultiTenderPaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Idempotency needs a lock, and the cache store the host already
        // configured is the one place to get one. Bound with bindIf so a host
        // with its own lock provider keeps it.
        $this->app->bindIf(LockProvider::class, static fn (Application $app) => $app->make('cache.store')->getStore());
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
