<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\MultiTenderPayments\Actions\ReverseTender;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderEffect;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Events\TenderRecorded;
use Liberu\Ecommerce\MultiTenderPayments\Events\TenderReversed;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotAllocate;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotReverseTender;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\MixedCurrencyPlan;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderLedgerIsAppendOnly;
use Liberu\Ecommerce\MultiTenderPayments\Models\TenderEntry;
use Liberu\Ecommerce\MultiTenderPayments\Plans\AdmittedTender;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

it('records a capture as an entry that carries what happened', function () {
    Event::fake();

    [$plan, $record] = ledger();

    $entry = $record($plan, tender(4_000, requested: 10_000), 'key-1', externalReference: 'ch_123');

    expect($entry->effect)->toBe(TenderEffect::Captured)
        ->and($entry->kind)->toBe(TenderKind::GiftCard)
        ->and($entry->amount_minor)->toBe(4_000)
        ->and($entry->requested_minor)->toBe(10_000)
        ->and($entry->isPartlySpent())->toBeTrue()
        ->and($entry->external_reference)->toBe('ch_123')
        ->and($entry->amount()->toArray()['decimal'])->toBe('40.00')
        ->and($entry->plan->is($plan))->toBeTrue();

    Event::assertDispatched(TenderRecorded::class);
});

it('records a decline that moves nothing and rolls nothing back', function () {
    // There is no transaction across gateways. Tender one's capture already
    // happened at another institution and nothing here can un-happen it.
    [$plan, $record] = ledger();

    $captured = $record($plan, tender(4_000), 'key-1');
    $declined = $record($plan, tender(6_000), 'key-2', TenderEffect::Declined);

    expect($declined->effect)->toBe(TenderEffect::Declined)
        ->and($declined->amount_minor)->toBe(0)
        ->and($captured->fresh()?->effect)->toBe(TenderEffect::Captured)
        ->and($captured->fresh()?->amount_minor)->toBe(4_000)
        ->and($plan->tenders()->count())->toBe(2);
});

it('refuses to update a recorded tender', function () {
    [$plan, $record] = ledger();

    $entry = $record($plan, tender(4_000), 'key-1');
    $entry->amount_minor = 1;
    $entry->save();
})->throws(TenderLedgerIsAppendOnly::class);

it('refuses to delete a recorded tender', function () {
    [$plan, $record] = ledger();

    $record($plan, tender(4_000), 'key-1')->delete();
})->throws(TenderLedgerIsAppendOnly::class);

it('reverses a tender with a new entry rather than by editing the old one', function () {
    Event::fake();

    [$plan, $record] = ledger();

    $captured = $record($plan, tender(4_000), 'key-1', externalReference: 'ch_123');
    $reversal = app()->make(ReverseTender::class)($captured, 'chargeback received');

    expect($reversal->id)->not->toBe($captured->id)
        ->and($reversal->effect)->toBe(TenderEffect::Reversed)
        ->and($reversal->reason)->toBe('chargeback received')
        ->and($reversal->reverses?->is($captured))->toBeTrue()
        ->and($captured->fresh()?->effect)->toBe(TenderEffect::Captured)
        ->and(TenderEntry::query()->count())->toBe(2);

    Event::assertDispatched(TenderReversed::class);
});

it('refuses a reversal without a reason', function () {
    [$plan, $record] = ledger();

    app()->make(ReverseTender::class)($record($plan, tender(4_000), 'key-1'), '   ');
})->throws(CannotReverseTender::class);

it('refuses to reverse the same tender twice', function () {
    [$plan, $record] = ledger();

    $captured = $record($plan, tender(4_000), 'key-1');
    $reverse = app()->make(ReverseTender::class);
    $reverse($captured, 'first');
    $reverse($captured, 'second');
})->throws(CannotReverseTender::class);

it('refuses to reverse something that never captured', function () {
    [$plan, $record] = ledger();

    $declined = $record($plan, tender(4_000), 'key-1', TenderEffect::Declined);

    app()->make(ReverseTender::class)($declined, 'nothing to undo');
})->throws(CannotReverseTender::class);

it('refuses to record a reversal as if it were a fresh tender', function () {
    [$plan, $record] = ledger();

    $record($plan, tender(4_000), 'key-1', TenderEffect::Reversed);
})->throws(CannotAllocate::class);

it('refuses a tender in a currency the plan does not use', function () {
    [$plan, $record] = ledger();

    $record($plan, new AdmittedTender(0, TenderKind::Cash, new Money(10, 'EUR'), new Money(10, 'EUR')), 'key-1');
})->throws(MixedCurrencyPlan::class);

it('refuses a negative tender', function () {
    [$plan, $record] = ledger();

    $record($plan, tender(-1), 'key-1');
})->throws(CannotAllocate::class);

it('records a deposit as an ordinary tender and an instalment as a reference only', function () {
    // A deposit is a tender recorded before the order is complete; the balance
    // due is the same fold. An instalment is an external identifier — this
    // module runs no scheduler and holds no authoritative due date.
    [$plan, $record] = ledger();

    $deposit = $record($plan, tender(2_000, kind: TenderKind::Deposit), 'key-deposit');
    $instalment = $record(
        $plan,
        new AdmittedTender(1, TenderKind::Instalment, new Money(1_000, 'GBP'), new Money(1_000, 'GBP'), null, 'schedule-7/3'),
        'key-instalment',
    );

    expect($deposit->kind)->toBe(TenderKind::Deposit)
        ->and($deposit->effect)->toBe(TenderEffect::Captured)
        ->and($instalment->instalment_reference)->toBe('schedule-7/3')
        ->and(TenderEntry::query()->whereNotNull('instalment_reference')->count())->toBe(1);
});
