<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Queries;

use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\PayableTotalUnknown;
use Liberu\Ecommerce\MultiTenderPayments\Models\PaymentPlan;
use Liberu\Ecommerce\MultiTenderPayments\Models\TenderEntry;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * What a plan still owes.
 *
 * There is no status column, no cached total and no `amount_paid`. The balance
 * is a fold over the append-only ledger, computed every time it is asked for,
 * because a stored balance and a ledger can disagree and only one of them can
 * be right.
 *
 * The fold is a sum of signed deltas, which is what makes it order-independent:
 * replaying the same entries in any sequence gives the same answer. That
 * property is the whole reason to trust it, and it is asserted rather than
 * assumed.
 *
 * A plan is satisfied or it has an outstanding balance. Those are the only two
 * answers and both are computed.
 */
final readonly class OutstandingBalance
{
    public function __construct(private ResolvesPayableTotal $total) {}

    public function forPlan(PaymentPlan $plan): Money
    {
        $payable = $this->total->payableTotalFor($plan->order_reference)
            ?? throw new PayableTotalUnknown("No payable total is known for order [{$plan->order_reference}].");

        /** @var list<TenderEntry> $entries */
        $entries = $plan->tenders()->get()->all();

        return self::fold($payable, $entries);
    }

    public function isSatisfied(PaymentPlan $plan): bool
    {
        return $this->forPlan($plan)->minor <= 0;
    }

    /**
     * @param  iterable<TenderEntry>  $entries
     */
    public static function fold(Money $payable, iterable $entries): Money
    {
        $balance = $payable->minor;

        foreach ($entries as $entry) {
            $balance += $entry->effect->balanceDelta() * $entry->amount_minor;
        }

        return $payable->withMinor($balance);
    }
}
