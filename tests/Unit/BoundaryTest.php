<?php

declare(strict_types=1);

/*
 * The wave-8 boundary, asserted by name.
 *
 * "Multi-Tender Payments owns the plan and the arithmetic. It never moves money
 * and it never holds a balance." Four modules already exist around it and this
 * one imports none of them — so the rule is checked against the source rather
 * than trusted to reviewer memory.
 *
 * The fleet's shared module boundary suite runs alongside this from
 * vendor/liberusoftware/package-testbench; it covers the rules every module
 * shares. These are the ones specific to this module.
 */

it('imports none of the four neighbouring modules', function (string $namespace) {
    foreach (sourceFiles() as $file) {
        expect(file_get_contents($file))->not->toContain($namespace);
    }
})->with([
    'payment operations owns authorising and capturing' => ['Liberu\\Ecommerce\\PaymentOperations\\'],
    'gift cards and store credit owns a redeemable balance' => ['Liberu\\Ecommerce\\GiftCardsAndStoreCredit\\'],
    'refunds owns what is owed back' => ['Liberu\\Ecommerce\\Refunds\\'],
    'orders owns the order and its total' => ['Liberu\\Ecommerce\\Orders\\'],
]);

it('requires none of the four neighbouring packages', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer)->toBeArray();

    $required = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

    foreach ([
        'liberusoftware/ecommerce-payment-operations',
        'liberusoftware/ecommerce-gift-cards-and-store-credit',
        'liberusoftware/ecommerce-refunds',
        'liberusoftware/ecommerce-orders',
    ] as $package) {
        expect($required)->not->toContain($package);
    }
});

it('never reaches for the host application', function () {
    foreach (sourceFiles() as $file) {
        expect(file_get_contents($file))->not->toMatch('/(?:use|new|extends|implements)\s+App\\\\/');
    }
});

it('keeps a presentation framework out of the domain', function (string $namespace) {
    foreach (sourceFiles() as $file) {
        expect(file_get_contents($file))->not->toContain($namespace);
    }
})->with([['Filament\\'], ['Livewire\\']]);

it('never calls a query builder update or delete on the ledger', function () {
    // Model events do not fire for `query()->update()` or `query()->delete()`,
    // so an append-only guarantee enforced only in model hooks has a hole. The
    // hooks are still there — this closes the hole they cannot see.
    foreach (sourceFiles() as $file) {
        expect(file_get_contents($file))->not->toMatch('/->(?:update|delete|forceDelete|truncate|upsert)\(/');
    }
});

it('declares no money column that is not an integer of minor units', function () {
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
        $source = (string) file_get_contents($migration);

        expect($source)->not->toMatch('/->(?:decimal|float|double|unsignedDecimal)\(/');
    }
});

it('registers no default binding for either published contract', function (string $contract) {
    // A default here would be a deployment quietly treating the order total as
    // zero, or a gift card as bottomless. Unbound is the correct failure.
    expect(app()->bound($contract))->toBeFalse();
})->with([
    ['Liberu\\Ecommerce\\MultiTenderPayments\\Contracts\\ResolvesPayableTotal'],
    ['Liberu\\Ecommerce\\MultiTenderPayments\\Contracts\\ResolvesTenderCapacity'],
]);
