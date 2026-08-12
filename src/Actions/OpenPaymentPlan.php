<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Actions;

use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\PayableTotalUnknown;
use Liberu\Ecommerce\MultiTenderPayments\Models\PaymentPlan;

/**
 * Opens the plan an order's tenders will hang off, or returns the open one.
 *
 * The currency is taken from the resolved payable total, never from a caller.
 * That is what makes "all tenders share the order's currency" enforceable at
 * all: the plan's currency has a single, server-side origin.
 */
final readonly class OpenPaymentPlan
{
    public function __construct(private ResolvesPayableTotal $total) {}

    public function __invoke(string $orderReference): PaymentPlan
    {
        $payable = $this->total->payableTotalFor($orderReference)
            ?? throw new PayableTotalUnknown("No payable total is known for order [{$orderReference}].");

        return PaymentPlan::query()->firstOrCreate(
            ['order_reference' => $orderReference],
            ['currency' => $payable->currency, 'currency_exponent' => $payable->exponent],
        );
    }
}
