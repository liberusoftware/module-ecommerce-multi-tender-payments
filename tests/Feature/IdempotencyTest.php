<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderClaimInFlight;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderIdempotencyConflict;
use Liberu\Ecommerce\MultiTenderPayments\Models\TenderEntry;
use Liberu\Ecommerce\MultiTenderPayments\Plans\AdmittedTender;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

it('returns the same entry when the identical request arrives twice', function () {
    [$plan, $record] = ledger();

    $first = $record($plan, tender(4_000), 'key-1');
    $second = $record($plan, tender(4_000), 'key-1');

    expect($second->id)->toBe($first->id)
        ->and(TenderEntry::query()->count())->toBe(1);
});

it('refuses permanently when the same key carries a different payload', function () {
    [$plan, $record] = ledger();

    $record($plan, tender(4_000), 'key-1');
    $record($plan, tender(9_000), 'key-1');
})->throws(TenderIdempotencyConflict::class);

it('refuses transiently while the same key is still in flight', function () {
    [$plan, $record] = ledger();

    // Hold the claim the way a concurrent request would.
    app()->make(LockProvider::class)->lock('multi-tender-payments:idempotency:key-1', 10)->get();

    $record($plan, tender(4_000), 'key-1');
})->throws(TenderClaimInFlight::class);

it('tells the two idempotency failures apart by class, never by message', function () {
    // They are opposite instructions to a caller — one says give up, the other
    // says retry the identical request — so they are separate classes and a
    // consumer switches on instanceof.
    [$plan, $record] = ledger();

    $record($plan, tender(4_000), 'key-1');

    $conflict = null;
    $inFlight = null;

    try {
        $record($plan, tender(9_000), 'key-1');
    } catch (Throwable $e) {
        $conflict = $e;
    }

    app()->make(LockProvider::class)->lock('multi-tender-payments:idempotency:key-2', 10)->get();

    try {
        $record($plan, tender(4_000), 'key-2');
    } catch (Throwable $e) {
        $inFlight = $e;
    }

    expect($conflict)->toBeInstanceOf(TenderIdempotencyConflict::class)
        ->and($conflict)->not->toBeInstanceOf(TenderClaimInFlight::class)
        ->and($inFlight)->toBeInstanceOf(TenderClaimInFlight::class)
        ->and($inFlight)->not->toBeInstanceOf(TenderIdempotencyConflict::class);
});

it('releases the claim once a conflict has been answered', function () {
    // The lock is released in a finally, so a permanent conflict does not turn
    // itself into a permanent in-flight claim on the next attempt.
    [$plan, $record] = ledger();

    $record($plan, tender(4_000), 'key-1');

    try {
        $record($plan, tender(9_000), 'key-1');
    } catch (TenderIdempotencyConflict) {
        // expected
    }

    expect($record($plan, tender(4_000), 'key-1')->amount_minor)->toBe(4_000);
});

it('keys the claim on the payload, not on the clock', function () {
    [$plan, $record] = ledger();

    $first = $record($plan, new AdmittedTender(0, TenderKind::Card, new Money(1_000, 'GBP'), new Money(1_000, 'GBP')), 'key-1');

    // Same facts, a later instant: still the same tender.
    $second = $record($plan, new AdmittedTender(0, TenderKind::Card, new Money(1_000, 'GBP'), new Money(1_000, 'GBP')), 'key-1');

    expect($second->id)->toBe($first->id);
});
