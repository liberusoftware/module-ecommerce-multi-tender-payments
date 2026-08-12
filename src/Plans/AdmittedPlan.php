<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Plans;

use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * An admissible plan: the payable total, and the tenders in declared order.
 *
 * Nothing here is stored and nothing here has moved any money. It is the
 * arithmetic answer to "is this offer admissible, and what would each tender
 * contribute?" — the caller then records what actually happened, tender by
 * tender, as each institution answers.
 */
final readonly class AdmittedPlan
{
    /** @param list<AdmittedTender> $tenders */
    public function __construct(
        public string $orderReference,
        public Money $payable,
        public array $tenders,
    ) {}

    public function allocated(): Money
    {
        $minor = 0;

        foreach ($this->tenders as $tender) {
            $minor += $tender->admitted->minor;
        }

        return $this->payable->withMinor($minor);
    }

    /**
     * What the plan leaves owing.
     *
     * Under-allocation is not an error. A plan covering nothing is a valid,
     * wholly-unsatisfied plan whose outstanding balance is the whole total.
     */
    public function outstanding(): Money
    {
        return $this->payable->withMinor($this->payable->minor - $this->allocated()->minor);
    }
}
