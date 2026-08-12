<?php

declare(strict_types=1);

use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotAllocate;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\MixedCurrencyPlan;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\OverAllocatedPlan;
use Liberu\Ecommerce\MultiTenderPayments\Plans\PlannedTender;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

it('spends a short tender partly rather than refusing it', function () {
    // The wave-7 reversal, and the whole point of the module: a gift card
    // covering 40% of the total contributes 40% and the rest is outstanding.
    [$host, $admit] = planningOrder();
    $host->capacity(TenderKind::GiftCard, 'card-1', new Money(4_000, 'GBP'));

    $plan = $admit('order-1', [
        PlannedTender::of(TenderKind::GiftCard, new Money(10_000, 'GBP'), 'card-1'),
    ]);

    expect($plan->tenders[0]->admitted->minor)->toBe(4_000)
        ->and($plan->tenders[0]->requested->minor)->toBe(10_000)
        ->and($plan->tenders[0]->isPartlySpent())->toBeTrue()
        ->and($plan->outstanding()->minor)->toBe(6_000);
});

it('treats an unknown capacity as no ceiling rather than as zero', function () {
    // A card's limit lives at the issuer. Reading null as zero would refuse
    // every card in the fleet.
    [, $admit] = planningOrder();

    $plan = $admit('order-1', [PlannedTender::of(TenderKind::Card, new Money(10_000, 'GBP'))]);

    expect($plan->tenders[0]->admitted->minor)->toBe(10_000)
        ->and($plan->tenders[0]->isPartlySpent())->toBeFalse()
        ->and($plan->outstanding()->isZero())->toBeTrue();
});

it('refuses an over-allocated plan outright rather than clamping it', function () {
    [, $admit] = planningOrder();

    $admit('order-1', [
        PlannedTender::of(TenderKind::Card, new Money(9_000, 'GBP')),
        PlannedTender::of(TenderKind::Cash, new Money(2_000, 'GBP')),
    ]);
})->throws(OverAllocatedPlan::class);

it('permits an under-allocated plan and calls the shortfall outstanding', function () {
    [, $admit] = planningOrder();

    $plan = $admit('order-1', [PlannedTender::of(TenderKind::StoreCredit, new Money(2_500, 'GBP'))]);

    expect($plan->allocated()->minor)->toBe(2_500)
        ->and($plan->outstanding()->minor)->toBe(7_500);
});

it('accepts a plan that covers nothing at all', function () {
    [, $admit] = planningOrder();

    $plan = $admit('order-1', []);

    expect($plan->tenders)->toBe([])
        ->and($plan->allocated()->isZero())->toBeTrue()
        ->and($plan->outstanding()->minor)->toBe(10_000);
});

it('applies tenders in the caller declared order and records that order', function () {
    // No kind has a priority. Declaring the same two kinds the other way round
    // gives the other order, and nothing in the module reshuffles them.
    [$host, $admit] = planningOrder();
    $host->capacity(TenderKind::GiftCard, 'card-1', new Money(3_000, 'GBP'));

    $forwards = $admit('order-1', [
        PlannedTender::of(TenderKind::Card, new Money(5_000, 'GBP')),
        PlannedTender::of(TenderKind::GiftCard, new Money(3_000, 'GBP'), 'card-1'),
    ]);

    $backwards = $admit('order-1', [
        PlannedTender::of(TenderKind::GiftCard, new Money(3_000, 'GBP'), 'card-1'),
        PlannedTender::of(TenderKind::Card, new Money(5_000, 'GBP')),
    ]);

    expect(array_column($forwards->tenders, 'kind'))->toBe([TenderKind::Card, TenderKind::GiftCard])
        ->and(array_column($backwards->tenders, 'kind'))->toBe([TenderKind::GiftCard, TenderKind::Card])
        ->and(array_column($forwards->tenders, 'position'))->toBe([0, 1]);
});

it('refuses a mixed currency plan with its own exception', function () {
    [, $admit] = planningOrder();

    $admit('order-1', [
        PlannedTender::of(TenderKind::Card, new Money(5_000, 'GBP')),
        PlannedTender::of(TenderKind::Cash, new Money(5_000, 'EUR')),
    ]);
})->throws(MixedCurrencyPlan::class);

it('refuses a capacity answered in another currency', function () {
    [$host, $admit] = planningOrder();
    $host->capacity(TenderKind::GiftCard, 'card-1', new Money(4_000, 'EUR'));

    $admit('order-1', [PlannedTender::of(TenderKind::GiftCard, new Money(4_000, 'GBP'), 'card-1')]);
})->throws(MixedCurrencyPlan::class);

it('splits a total across shares exactly, remainder and all', function () {
    [, $admit] = planningOrder();

    $plan = $admit('order-1', [
        PlannedTender::share(TenderKind::Card, 1),
        PlannedTender::share(TenderKind::Card, 1),
        PlannedTender::share(TenderKind::Card, 1),
    ]);

    $parts = array_map(static fn ($tender): int => $tender->admitted->minor, $plan->tenders);

    expect($parts)->toBe([3_334, 3_333, 3_333])
        ->and(array_sum($parts))->toBe(10_000)
        ->and($plan->outstanding()->isZero())->toBeTrue();
});

it('lets a capacity cut a share down, leaving the rest outstanding', function () {
    [$host, $admit] = planningOrder();
    $host->capacity(TenderKind::GiftCard, 'card-1', new Money(1_000, 'GBP'));

    $plan = $admit('order-1', [
        PlannedTender::share(TenderKind::GiftCard, 1, 'card-1'),
        PlannedTender::share(TenderKind::Card, 1),
    ]);

    expect($plan->allocated()->minor)->toBe(6_000)
        ->and($plan->outstanding()->minor)->toBe(4_000);
});

it('refuses a plan mixing declared amounts with declared shares', function () {
    [, $admit] = planningOrder();

    $admit('order-1', [
        PlannedTender::of(TenderKind::Card, new Money(5_000, 'GBP')),
        PlannedTender::share(TenderKind::Cash, 1),
    ]);
})->throws(CannotAllocate::class);

it('refuses a negative tender', function () {
    [, $admit] = planningOrder();

    $admit('order-1', [PlannedTender::of(TenderKind::Card, new Money(-1, 'GBP'))]);
})->throws(CannotAllocate::class);

it('refuses a negative capacity', function () {
    [$host, $admit] = planningOrder();
    $host->capacity(TenderKind::StoreCredit, null, new Money(-1, 'GBP'));

    $admit('order-1', [PlannedTender::of(TenderKind::StoreCredit, new Money(10, 'GBP'))]);
})->throws(CannotAllocate::class);

it('refuses a negative payable total', function () {
    [, $admit] = planningOrder(-1);

    $admit('order-1', []);
})->throws(CannotAllocate::class);
