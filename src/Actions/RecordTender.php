<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Actions;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderEffect;
use Liberu\Ecommerce\MultiTenderPayments\Events\TenderRecorded;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotAllocate;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderClaimInFlight;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderIdempotencyConflict;
use Liberu\Ecommerce\MultiTenderPayments\Models\PaymentPlan;
use Liberu\Ecommerce\MultiTenderPayments\Models\TenderEntry;
use Liberu\Ecommerce\MultiTenderPayments\Plans\AdmittedTender;

/**
 * Appends what actually happened at one institution to the ledger.
 *
 * This module does not authorise, capture, redeem or charge anything. It
 * records that a capture *happened*, or that a tender was declined, with the
 * external reference whichever module did the moving handed back.
 *
 * There is no transaction across gateways, so there is nothing atomic to
 * offer: each entry stands alone, and a decline recorded here leaves every
 * earlier capture exactly where it was.
 *
 * Idempotency has two failure modes and they are opposite instructions:
 *
 * - the same key with a different payload is permanent — retrying will never
 *   help, and {@see TenderIdempotencyConflict} says so;
 * - the same key while the first attempt is still running is transient — the
 *   identical retry will succeed, and {@see TenderClaimInFlight} says so.
 *
 * They are separate classes so a caller never has to read a message to tell
 * "give up" from "try again".
 */
final readonly class RecordTender
{
    public function __construct(
        private LockProvider $locks,
        private Dispatcher $events,
    ) {}

    public function __invoke(
        PaymentPlan $plan,
        AdmittedTender $tender,
        string $idempotencyKey,
        TenderEffect $effect = TenderEffect::Captured,
        ?string $externalReference = null,
    ): TenderEntry {
        if ($effect === TenderEffect::Reversed) {
            throw new CannotAllocate('A reversal is recorded through ReverseTender, against the entry it reverses.');
        }

        $plan->zero()->assertComparable($tender->admitted);

        if ($tender->admitted->isNegative()) {
            throw new CannotAllocate("A tender of {$tender->admitted->minor} is negative.");
        }

        $attributes = [
            'plan_id' => $plan->id,
            'position' => $tender->position,
            'kind' => $tender->kind,
            'effect' => $effect,
            // A declined tender moved nothing, so it contributes nothing — but it
            // is recorded, because it happened and the operator has to see it.
            'amount_minor' => $effect === TenderEffect::Captured ? $tender->admitted->minor : 0,
            'requested_minor' => $tender->requested->minor,
            'external_reference' => $externalReference ?? $tender->reference,
            'instalment_reference' => $tender->instalmentReference,
            'occurred_at' => Carbon::now(),
        ];

        $payload = $attributes;
        unset($payload['occurred_at']);
        $payload['kind'] = $tender->kind->value;
        $payload['effect'] = $effect->value;

        $hash = hash('sha256', (string) json_encode($payload));

        $lock = $this->locks->lock('multi-tender-payments:idempotency:'.$idempotencyKey, 10);

        if (! $lock->get()) {
            throw new TenderClaimInFlight("Idempotency key [{$idempotencyKey}] is already being processed.");
        }

        try {
            $existing = TenderEntry::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing instanceof TenderEntry) {
                if ($existing->payload_hash !== $hash) {
                    throw new TenderIdempotencyConflict(
                        "Idempotency key [{$idempotencyKey}] was already used for a different tender."
                    );
                }

                return $existing;
            }

            $entry = TenderEntry::query()->create($attributes + [
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $hash,
            ]);
        } finally {
            $lock->release();
        }

        $this->events->dispatch(new TenderRecorded($entry));

        return $entry;
    }
}
