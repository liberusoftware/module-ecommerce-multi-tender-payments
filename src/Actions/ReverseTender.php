<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderEffect;
use Liberu\Ecommerce\MultiTenderPayments\Events\TenderReversed;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotReverseTender;
use Liberu\Ecommerce\MultiTenderPayments\Models\TenderEntry;

/**
 * Records that a captured tender was undone, as a new ledger entry.
 *
 * A reversal is not a correction and it is not a refund. It is not a correction
 * because the original capture happened and deleting the row would be a lie
 * about the world; it is not a refund because deciding that money is owed back
 * to a customer belongs to `ecommerce-refunds`, and nothing here creates one
 * there.
 */
final readonly class ReverseTender
{
    public function __construct(private Dispatcher $events) {}

    public function __invoke(TenderEntry $tender, string $reason): TenderEntry
    {
        if ($tender->effect !== TenderEffect::Captured) {
            throw new CannotReverseTender(
                "Tender [{$tender->id}] is {$tender->effect->value}; only a captured tender can be reversed."
            );
        }

        if ($tender->reversal()->exists()) {
            throw new CannotReverseTender("Tender [{$tender->id}] has already been reversed.");
        }

        if (trim($reason) === '') {
            throw new CannotReverseTender("A reversal of tender [{$tender->id}] must carry a reason.");
        }

        $reversal = TenderEntry::query()->create([
            'plan_id' => $tender->plan_id,
            'position' => $tender->position,
            'kind' => $tender->kind,
            'effect' => TenderEffect::Reversed,
            'amount_minor' => $tender->amount_minor,
            'requested_minor' => $tender->amount_minor,
            'external_reference' => $tender->external_reference,
            'instalment_reference' => $tender->instalment_reference,
            'reverses_tender_id' => $tender->id,
            'reason' => trim($reason),
            'occurred_at' => Carbon::now(),
        ]);

        $this->events->dispatch(new TenderReversed($reversal));

        return $reversal;
    }
}
