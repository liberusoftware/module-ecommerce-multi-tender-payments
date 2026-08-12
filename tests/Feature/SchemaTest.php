<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

$tables = ['multi_tender_payment_plans', 'multi_tender_payment_tenders'];

it('names every table it invents with the module prefix', function () use ($tables) {
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue()
            ->and($table)->toStartWith('multi_tender_');
    }
});

it('stores every money column as an integer of minor units', function () use ($tables) {
    $minorColumns = 0;

    foreach ($tables as $table) {
        foreach (Schema::getColumns($table) as $column) {
            if (! str_ends_with((string) $column['name'], '_minor')) {
                continue;
            }

            expect(strtolower((string) $column['type_name']))->toContain('int');
            $minorColumns++;
        }
    }

    expect($minorColumns)->toBe(2);
});

it('declares no approximate numeric column anywhere', function () use ($tables) {
    // The host's `orders.total_amount decimal(10,2)` is the shape that drifts
    // once a total is split across N tenders and reconciled through a cast.
    foreach ($tables as $table) {
        foreach (Schema::getColumns($table) as $column) {
            $type = strtolower((string) $column['type_name']);

            foreach (['decimal', 'float', 'double', 'real', 'numeric'] as $approximate) {
                expect($type)->not->toContain($approximate);
            }
        }
    }
});

it('declares its foreign keys, whether or not the driver enforces them', function () {
    // SQLite enforces foreign keys only with the pragma on, and a pragma set
    // inside RefreshDatabase's transaction is a no-op — so the declaration is
    // what gets asserted, not the enforcement.
    $keys = Schema::getForeignKeys('multi_tender_payment_tenders');

    $byColumn = [];

    foreach ($keys as $key) {
        $byColumn[implode(',', $key['columns'])] = $key['foreign_table'];
    }

    // toEqual, not toBe: the driver decides what order it reports keys in and
    // that order is not part of the schema.
    expect($byColumn)->toEqual([
        'plan_id' => 'multi_tender_payment_plans',
        'reverses_tender_id' => 'multi_tender_payment_tenders',
    ]);
});

it('points no foreign key outside this module', function () use ($tables) {
    // The host FKs `payment_methods.user_id` straight into `users` with
    // `onDelete('cascade')`, so deleting a user silently deletes payment
    // history. Nothing here references a table this module does not own.
    foreach ($tables as $table) {
        foreach (Schema::getForeignKeys($table) as $key) {
            expect($tables)->toContain($key['foreign_table']);
        }
    }
});

it('keeps the idempotency key unique', function () {
    expect(collect(Schema::getIndexes('multi_tender_payment_tenders'))
        ->firstWhere('columns', ['idempotency_key'])['unique'] ?? false)->toBeTrue();
});
