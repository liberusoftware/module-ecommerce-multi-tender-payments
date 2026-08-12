<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Models\TenderEntry;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/*
 * One test per fault this module replaces in the host application.
 *
 * The README names all nine. These are the proofs that each is gone, kept next
 * to each other so a reader can check the list rather than trust it.
 */

it('replaces a single nullable payment_method string with many typed tenders', function () {
    // Fault 1. One order, one tender, free text, no enum, no FK, no validation:
    // multi-tender was not merely unimplemented in the host, it was
    // unrepresentable.
    [$plan, $record] = ledger();

    $record($plan, tender(2_000, kind: TenderKind::GiftCard, position: 0), 'k-1');
    $record($plan, tender(3_000, kind: TenderKind::Card, position: 1), 'k-2');

    $kinds = $plan->tenders()->orderBy('position')->get()->map(fn (TenderEntry $e) => $e->kind);

    expect($kinds->all())->toBe([TenderKind::GiftCard, TenderKind::Card])
        ->and($kinds->first())->toBeInstanceOf(TenderKind::class);
});

it('gives every tender its own gateway reference instead of one on the order', function () {
    // Fault 2. `orders.transaction_id` holds one charge id, so a second
    // tender's reference has nowhere to go but on top of the first.
    [$plan, $record] = ledger();

    $record($plan, tender(2_000, position: 0), 'k-1', externalReference: 'ch_first');
    $record($plan, tender(3_000, kind: TenderKind::Card, position: 1), 'k-2', externalReference: 'ch_second');

    expect($plan->tenders()->pluck('external_reference')->sort()->values()->all())
        ->toBe(['ch_first', 'ch_second']);
});

it('holds no payment status string at all, in either table', function () {
    // Fault 3. `orders.payment_status` and `invoices.payment_status` are two
    // independent copies of the same idea that can disagree, and nothing sits
    // between "pending" and "paid" — partial payment has no value to be in.
    foreach (['multi_tender_payment_plans', 'multi_tender_payment_tenders'] as $table) {
        $columns = Schema::getColumnListing($table);

        expect($columns)->not->toContain('payment_status');
        expect($columns)->not->toContain('status');
    }
});

it('never converts a decimal total through a float', function () {
    // Fault 4. `orders.total_amount decimal(10,2)` through a PHP float cast is
    // where a split-and-reconcile drifts.
    expect(Money::fromDecimalString('19.99', 'GBP')->minor)->toBe(1999)
        ->and((int) (19.99 * 100))->toBe(1998);
});

it('stores no instrument details blob', function () {
    // Fault 5. `payment_methods.details` is a `text` blob, unstructured,
    // unencrypted, holding whatever the caller put there. A stored instrument
    // is not a tender, and this module stores none: it holds an opaque
    // reference that the module which moved the money issued.
    $columns = Schema::getColumnListing('multi_tender_payment_tenders');

    expect($columns)->not->toContain('details');
    expect($columns)->not->toContain('metadata');
    expect($columns)->not->toContain('payload');
});

it('has no default tender and no hardcoded priority', function () {
    // Fault 6. `payment_methods.is_default` is a bare boolean with no unique
    // constraint, so two rows for one user can both be default and nothing says
    // which wins. Here there is no default: order is declared by the caller and
    // recorded.
    $columns = Schema::getColumnListing('multi_tender_payment_tenders');

    expect($columns)->not->toContain('is_default');
    expect($columns)->toContain('position');
});

it('holds no user foreign key that a user deletion could cascade through', function () {
    // Fault 7. `payment_methods.user_id` FKs into `users` with
    // `onDelete('cascade')` and has no tenant or site column at all, so
    // deleting a user silently deletes payment history.
    $columns = Schema::getColumnListing('multi_tender_payment_tenders');

    expect($columns)->not->toContain('user_id');
    expect(Schema::hasTable('users'))->toBeFalse();
});

it('records which tender covered which portion of which order', function () {
    // Fault 8. The host has no allocation record anywhere, so an outstanding
    // balance cannot be computed — only asserted by a status string.
    [$plan, $record] = ledger();

    $entry = $record($plan, tender(2_500, requested: 4_000, position: 0), 'k-1');

    expect($entry->plan_id)->toBe($plan->id)
        ->and($entry->position)->toBe(0)
        ->and($entry->amount_minor)->toBe(2_500)
        ->and($entry->requested_minor)->toBe(4_000)
        ->and($entry->kind)->toBe(TenderKind::GiftCard);
});

it('represents a deposit and an instalment reference, which the host cannot', function () {
    // Fault 9. Deposits and instalments do not exist in the host in any form —
    // no table, no column, no model.
    [$plan, $record] = ledger();

    $deposit = $record($plan, tender(2_000, kind: TenderKind::Deposit, position: 0), 'k-deposit');

    expect($deposit->kind)->toBe(TenderKind::Deposit)
        ->and(Schema::getColumnListing('multi_tender_payment_tenders'))->toContain('instalment_reference');
});
