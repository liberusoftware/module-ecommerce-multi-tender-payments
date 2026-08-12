<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\MultiTenderPayments\Actions\ReverseTender;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderEffect;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Models\PaymentPlan;
use Liberu\Ecommerce\MultiTenderPayments\Models\TenderEntry;
use Liberu\Ecommerce\MultiTenderPayments\Queries\OutstandingBalance;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

it('agrees on the balance three independent ways', function () {
    // A non-trivial ledger: mixed kinds, a reversal, a partial capture, and one
    // entry recorded out of sequence.
    [$plan, $record] = ledger(10_000);

    $record($plan, tender(2_000, kind: TenderKind::Deposit, position: 0), 'k-deposit');
    $card = $record($plan, tender(3_000, requested: 5_000, kind: TenderKind::Card, position: 1), 'k-card');
    $record($plan, tender(1_500, kind: TenderKind::StoreCredit, position: 3), 'k-credit');
    $record($plan, tender(3_000, kind: TenderKind::Cash, position: 2), 'k-cash');
    $record($plan, tender(9_999, kind: TenderKind::BankTransfer, position: 4), 'k-declined', TenderEffect::Declined);
    app()->make(ReverseTender::class)($card, 'chargeback received');

    $payable = new Money(10_000, 'GBP');
    $entries = $plan->tenders()->get();

    // One: fold the ledger forward from the payable total.
    $folded = OutstandingBalance::fold($payable, $entries->all());

    // Two: subtract the sum of what was applied.
    $applied = $entries
        ->filter(fn (TenderEntry $e): bool => $e->effect === TenderEffect::Captured)
        ->sum('amount_minor')
        - $entries->filter(fn (TenderEntry $e): bool => $e->effect === TenderEffect::Reversed)->sum('amount_minor');

    // Three: replay in a different order — the property that makes the fold
    // trustworthy is that sequence cannot change the answer.
    $shuffled = OutstandingBalance::fold($payable, $entries->reverse()->all());

    expect($folded->minor)->toBe(10_000 - 2_000 - 1_500 - 3_000)
        ->and($folded->minor)->toBe($payable->minor - $applied)
        ->and($shuffled->minor)->toBe($folded->minor)
        ->and(app()->make(OutstandingBalance::class)->forPlan($plan)->minor)->toBe($folded->minor);
});

it('is order independent over every permutation of a small ledger', function () {
    [$plan, $record] = ledger(1_000);

    $one = $record($plan, tender(200, kind: TenderKind::GiftCard, position: 0), 'k-1');
    $record($plan, tender(300, kind: TenderKind::Card, position: 1), 'k-2');
    app()->make(ReverseTender::class)($one, 'card revoked');

    $entries = $plan->tenders()->get()->all();
    $payable = new Money(1_000, 'GBP');
    $expected = OutstandingBalance::fold($payable, $entries)->minor;

    foreach (permutations($entries) as $permutation) {
        expect(OutstandingBalance::fold($payable, $permutation)->minor)->toBe($expected);
    }

    expect($expected)->toBe(700);
});

it('reports a plan satisfied only when the fold reaches zero', function () {
    [$plan, $record] = ledger(1_000);

    $balance = app()->make(OutstandingBalance::class);

    expect($balance->isSatisfied($plan))->toBeFalse();

    $record($plan, tender(600, kind: TenderKind::StoreCredit), 'k-1');

    expect($balance->forPlan($plan)->minor)->toBe(400)
        ->and($balance->isSatisfied($plan))->toBeFalse();

    $record($plan, tender(400, kind: TenderKind::Card, position: 1), 'k-2');

    expect($balance->forPlan($plan)->isZero())->toBeTrue()
        ->and($balance->isSatisfied($plan))->toBeTrue();
});

it('holds no balance column and no status column anywhere', function () {
    // Partial payment has a value to be in because it is a number, not a word.
    $columns = Schema::getColumnListing((new PaymentPlan())->getTable());

    expect($columns)->not->toContain('status')
        ->and($columns)->not->toContain('payment_status')
        ->and($columns)->not->toContain('amount_paid')
        ->and($columns)->not->toContain('balance_minor')
        ->and($columns)->not->toContain('outstanding_minor');
});

/**
 * @param  list<TenderEntry>  $items
 * @return list<list<TenderEntry>>
 */
function permutations(array $items): array
{
    if (count($items) <= 1) {
        return [$items];
    }

    $result = [];

    foreach ($items as $index => $item) {
        $rest = $items;
        unset($rest[$index]);

        foreach (permutations(array_values($rest)) as $permutation) {
            $result[] = [$item, ...$permutation];
        }
    }

    return $result;
}
