<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Contracts;

use Liberu\Ecommerce\MultiTenderPayments\Exceptions\PayableTotalUnknown;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * What the order is worth, answered server-side by the host.
 *
 * This module is *told* the total. It never looks one up, never asks
 * `ecommerce-orders`, and above all never accepts one in a request body:
 * a money figure in a body is a hole of exactly the same shape as a tenant id
 * in a body, and it is the figure every other number here is measured against.
 *
 * The module publishes this contract and does **not** implement it. It is
 * registered with no default binding, so a half-configured deployment fails
 * loudly at the boundary instead of quietly treating the total as zero. That is
 * the correct failure direction: such a deployment can read a plan but cannot
 * record anything that moves money.
 *
 * A null answer for an order that exists is a different failure — see
 * {@see PayableTotalUnknown}.
 */
interface ResolvesPayableTotal
{
    public function payableTotalFor(string $orderReference): ?Money;
}
