<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Support;

use Liberu\Ecommerce\MultiTenderPayments\Exceptions\MixedCurrencyPlan;

/**
 * A money amount in integer minor units.
 *
 * There is no float and no decimal anywhere in this module. Allocation is the
 * one place where money arithmetic is not a single addition — splitting a total
 * across N tenders and reconciling the remainder — and doing that through a
 * float drifts.
 */
final readonly class Money
{
    public function __construct(
        public int $minor,
        public string $currency,
        public int $exponent = 2,
    ) {}

    /**
     * Convert a decimal string to minor units by string arithmetic.
     *
     * `(int) (19.99 * 100)` is 1998, because 19.99 has no exact binary
     * representation and the cast truncates what is left. Splitting on the
     * point, padding the fraction to the currency exponent and concatenating
     * never touches a float at all. The test for this case exists so the reason
     * survives the next person who thinks a cast would be tidier.
     *
     * A fraction longer than the exponent is truncated, never rounded: rounding
     * a caller's figure invents money.
     */
    public static function fromDecimalString(string $decimal, string $currency, int $exponent = 2): self
    {
        $trimmed = trim($decimal);
        $sign = str_starts_with($trimmed, '-') ? -1 : 1;
        [$whole, $fraction] = array_pad(explode('.', ltrim($trimmed, '+-'), 2), 2, '');
        $fraction = substr(str_pad($fraction, $exponent, '0'), 0, $exponent);

        return new self($sign * (int) ($whole.$fraction), $currency, $exponent);
    }

    /** The decimal presentation, as a string. Never a float. */
    public function decimal(): string
    {
        $digits = str_pad((string) abs($this->minor), $this->exponent + 1, '0', STR_PAD_LEFT);
        $sign = $this->minor < 0 ? '-' : '';

        if ($this->exponent === 0) {
            return $sign.$digits;
        }

        return $sign.substr($digits, 0, -$this->exponent).'.'.substr($digits, -$this->exponent);
    }

    /**
     * The settled API money envelope. `decimal` is a string.
     *
     * @return array{minor: int, currency: string, exponent: int, decimal: string}
     */
    public function toArray(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency,
            'exponent' => $this->exponent,
            'decimal' => $this->decimal(),
        ];
    }

    public function withMinor(int $minor): self
    {
        return new self($minor, $this->currency, $this->exponent);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    /**
     * All tenders in a plan share the order's currency.
     *
     * There is no default currency and no conversion — conversion is somebody
     * else's module. A mixed-currency plan is refused with its own exception so
     * a caller can tell it apart from every other refusal without reading a
     * message string.
     */
    public function assertComparable(self $other): void
    {
        if ($this->currency !== $other->currency || $this->exponent !== $other->exponent) {
            throw new MixedCurrencyPlan(
                "Cannot mix {$this->currency} (exponent {$this->exponent}) with {$other->currency} (exponent {$other->exponent}) in one plan."
            );
        }
    }
}
