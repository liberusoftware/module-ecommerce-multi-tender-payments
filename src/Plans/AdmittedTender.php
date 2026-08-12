<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Plans;

use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * A tender the module has admitted, with what was asked and what it can give.
 *
 * Both figures are kept. A gift card asked to cover the whole total but worth
 * 40% of it is *partly spent*, not refused — and the record says so, so nothing
 * about the reduction is silent.
 */
final readonly class AdmittedTender
{
    public function __construct(
        public int $position,
        public TenderKind $kind,
        public Money $requested,
        public Money $admitted,
        public ?string $reference = null,
        public ?string $instalmentReference = null,
    ) {}

    public function isPartlySpent(): bool
    {
        return $this->admitted->minor < $this->requested->minor;
    }
}
