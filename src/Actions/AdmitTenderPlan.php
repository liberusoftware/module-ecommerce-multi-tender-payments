<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Actions;

use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesTenderCapacity;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotAllocate;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\OverAllocatedPlan;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\PayableTotalUnknown;
use Liberu\Ecommerce\MultiTenderPayments\Plans\AdmittedPlan;
use Liberu\Ecommerce\MultiTenderPayments\Plans\AdmittedTender;
use Liberu\Ecommerce\MultiTenderPayments\Plans\PlannedTender;
use Liberu\Ecommerce\MultiTenderPayments\Support\Allocator;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * The whole of this module's arithmetic, in one place.
 *
 * Given the payable total the host resolves and the capacities the host
 * returns, is this plan admissible, and what would each tender contribute?
 *
 * The rules, all settled:
 *
 * - A tender short of what was asked of it is **partly spent**, not refused.
 * - A plan exceeding the payable total is **refused**, never clamped.
 * - A plan short of the payable total is **fine**; the shortfall is the
 *   outstanding balance.
 * - Tenders apply in the order the caller declared. There is no priority.
 * - Every tender is in the order's currency, or the plan is refused.
 */
final readonly class AdmitTenderPlan
{
    public function __construct(
        private ResolvesPayableTotal $total,
        private ResolvesTenderCapacity $capacity,
    ) {}

    /** @param list<PlannedTender> $tenders */
    public function __invoke(string $orderReference, array $tenders): AdmittedPlan
    {
        $payable = $this->total->payableTotalFor($orderReference)
            ?? throw new PayableTotalUnknown("No payable total is known for order [{$orderReference}].");

        if ($payable->isNegative()) {
            throw new CannotAllocate("Order [{$orderReference}] resolved a negative payable total.");
        }

        $admitted = [];
        $allocated = 0;

        foreach ($this->requested($payable, $tenders) as $position => $requested) {
            $planned = $tenders[$position];
            $ceiling = $this->capacity->capacityFor($planned->kind, $planned->reference);

            if ($ceiling !== null) {
                $payable->assertComparable($ceiling);

                if ($ceiling->isNegative()) {
                    throw new CannotAllocate("A negative capacity was resolved for tender [{$planned->kind->value}].");
                }
            }

            // A null capacity is "no ceiling known to us" — the ordinary answer
            // for a card, whose limit lives at the issuer. It is not zero.
            $spend = $ceiling === null ? $requested->minor : min($requested->minor, $ceiling->minor);
            $allocated += $spend;

            $admitted[] = new AdmittedTender(
                position: $position,
                kind: $planned->kind,
                requested: $requested,
                admitted: $payable->withMinor($spend),
                reference: $planned->reference,
                instalmentReference: $planned->instalmentReference,
            );
        }

        if ($allocated > $payable->minor) {
            throw new OverAllocatedPlan(
                "Tenders totalling {$allocated} exceed the payable total {$payable->minor} for order [{$orderReference}]."
            );
        }

        return new AdmittedPlan($orderReference, $payable, $admitted);
    }

    /**
     * What each tender is being asked for, before capacity is consulted.
     *
     * A plan declares amounts or it declares shares. Mixing the two would make
     * the split ambiguous — shares are relative to a total that the amounts
     * have already claimed part of — so it is refused rather than guessed at.
     *
     * @param  list<PlannedTender>  $tenders
     * @return list<Money>
     */
    private function requested(Money $payable, array $tenders): array
    {
        if ($tenders === []) {
            return [];
        }

        $shares = [];

        foreach ($tenders as $tender) {
            $shares[] = $tender->share !== null;
        }

        if (in_array(true, $shares, true) && in_array(false, $shares, true)) {
            throw new CannotAllocate('A plan declares tender amounts or tender shares, never both.');
        }

        if (! in_array(true, $shares, true)) {
            $amounts = [];

            foreach ($tenders as $tender) {
                $amount = $tender->amount ?? new Money(0, $payable->currency, $payable->exponent);
                $payable->assertComparable($amount);

                if ($amount->isNegative()) {
                    throw new CannotAllocate("A tender of {$amount->minor} is negative.");
                }

                $amounts[] = $amount;
            }

            return $amounts;
        }

        $weights = [];

        foreach ($tenders as $tender) {
            $weights[] = $tender->share ?? 0;
        }

        $parts = [];

        foreach (Allocator::largestRemainder($payable->minor, $weights) as $part) {
            $parts[] = $payable->withMinor($part);
        }

        return $parts;
    }
}
