<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Tests\Fixtures;

use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesTenderCapacity;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * What a host binds. The module ships neither implementation, on purpose.
 */
final class FakeHost implements ResolvesPayableTotal, ResolvesTenderCapacity
{
    /** @var array<string, Money|null> */
    public array $totals = [];

    /** @var array<string, Money|null> */
    public array $capacities = [];

    public function payableTotalFor(string $orderReference): ?Money
    {
        return $this->totals[$orderReference] ?? null;
    }

    public function capacityFor(TenderKind $kind, ?string $reference): ?Money
    {
        return $this->capacities[$kind->value.':'.($reference ?? '')] ?? null;
    }

    public function total(string $orderReference, Money $money): self
    {
        $this->totals[$orderReference] = $money;

        return $this;
    }

    public function capacity(TenderKind $kind, ?string $reference, Money $money): self
    {
        $this->capacities[$kind->value.':'.($reference ?? '')] = $money;

        return $this;
    }
}
