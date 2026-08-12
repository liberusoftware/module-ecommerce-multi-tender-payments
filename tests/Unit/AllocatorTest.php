<?php

declare(strict_types=1);

use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotAllocate;
use Liberu\Ecommerce\MultiTenderPayments\Support\Allocator;

it('always distributes the whole total with no residue', function () {
    // A property, not a handful of hand-picked examples: the failure mode of
    // remainder distribution is the one case nobody thought to pick.
    mt_srand(875);

    $checked = 0;

    foreach ([0, 1, 2, 3, 7, 10, 99, 100, 101, 1999, 10_000, 123_457] as $total) {
        foreach (range(1, 7) as $count) {
            for ($trial = 0; $trial < 6; $trial++) {
                $weights = [];

                for ($i = 0; $i < $count; $i++) {
                    $weights[] = mt_rand(0, 50);
                }

                if (array_sum($weights) === 0) {
                    $weights[0] = 1;
                }

                $parts = Allocator::largestRemainder($total, $weights);

                expect(array_sum($parts))->toBe($total)
                    ->and($parts)->toHaveCount($count);

                foreach ($parts as $part) {
                    expect($part)->toBeGreaterThanOrEqual(0);
                }

                $checked++;
            }
        }
    }

    expect($checked)->toBe(12 * 7 * 6);
});

it('breaks a remainder tie by declared order', function () {
    // Three equal shares of 100 is 33.33…; the extra penny goes to the tender
    // the caller declared first, because this module has no other preference.
    expect(Allocator::largestRemainder(100, [1, 1, 1]))->toBe([34, 33, 33])
        ->and(Allocator::largestRemainder(10, [1, 1, 1, 1]))->toBe([3, 3, 2, 2]);
});

it('splits proportionally when the shares differ', function () {
    expect(Allocator::largestRemainder(1000, [3, 1]))->toBe([750, 250])
        ->and(Allocator::largestRemainder(1001, [1, 1]))->toBe([501, 500])
        ->and(Allocator::largestRemainder(100, [0, 1]))->toBe([0, 100]);
});

it('refuses an allocation that has no exact answer', function (int $total, array $weights) {
    Allocator::largestRemainder($total, $weights);
})->throws(CannotAllocate::class)->with([
    'negative total' => [-1, [1, 1]],
    'no shares' => [100, []],
    'negative share' => [100, [1, -1]],
    'all shares zero' => [100, [0, 0]],
]);
