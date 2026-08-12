<?php

declare(strict_types=1);

use Liberu\Ecommerce\MultiTenderPayments\Exceptions\MixedCurrencyPlan;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

it('converts a decimal string to minor units without ever touching a float', function () {
    // The reason this method exists, kept as a test so it survives the next
    // person who thinks a cast would be tidier.
    expect((int) (19.99 * 100))->toBe(1998)
        ->and(Money::fromDecimalString('19.99', 'GBP')->minor)->toBe(1999);
});

it('converts decimal strings across the shapes a caller actually sends', function (string $decimal, int $exponent, int $minor) {
    expect(Money::fromDecimalString($decimal, 'GBP', $exponent)->minor)->toBe($minor);
})->with([
    ['0', 2, 0],
    ['5', 2, 500],
    ['5.7', 2, 570],
    ['0.01', 2, 1],
    ['  12.34  ', 2, 1234],
    ['+12.34', 2, 1234],
    ['-12.34', 2, -1234],
    ['1000', 0, 1000],
    ['1.005', 3, 1005],
    // Truncated, never rounded: rounding a caller's figure invents money.
    ['19.999', 2, 1999],
]);

it('renders a decimal presentation as a string in both directions', function () {
    expect(new Money(1999, 'GBP')->decimal())->toBe('19.99')
        ->and(new Money(5, 'GBP')->decimal())->toBe('0.05')
        ->and(new Money(0, 'GBP')->decimal())->toBe('0.00')
        ->and(new Money(-1999, 'GBP')->decimal())->toBe('-19.99')
        ->and(new Money(1000, 'JPY', 0)->decimal())->toBe('1000');
});

it('presents the settled api money envelope with a string decimal', function () {
    $envelope = new Money(1999, 'GBP')->toArray();

    expect($envelope)->toBe([
        'minor' => 1999,
        'currency' => 'GBP',
        'exponent' => 2,
        'decimal' => '19.99',
    ])->and($envelope['decimal'])->toBeString();
});

it('answers the small questions a fold asks of it', function () {
    expect(new Money(0, 'GBP')->isZero())->toBeTrue()
        ->and(new Money(1, 'GBP')->isZero())->toBeFalse()
        ->and(new Money(-1, 'GBP')->isNegative())->toBeTrue()
        ->and(new Money(0, 'GBP')->isNegative())->toBeFalse()
        ->and(new Money(1999, 'GBP')->withMinor(5))->toEqual(new Money(5, 'GBP'));
});

it('refuses to compare two currencies', function () {
    new Money(1999, 'GBP')->assertComparable(new Money(1999, 'EUR'));
})->throws(MixedCurrencyPlan::class);

it('refuses to compare one currency at two exponents', function () {
    new Money(1999, 'GBP')->assertComparable(new Money(1999, 'GBP', 3));
})->throws(MixedCurrencyPlan::class);
