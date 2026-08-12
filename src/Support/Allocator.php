<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Support;

use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotAllocate;

/**
 * Splits an integer total across weighted shares with no residue.
 *
 * Largest remainder: give everyone their floor, then hand the leftover units to
 * the largest fractional remainders. The tie-break is **declared order** — the
 * earlier share wins — because the module has no tender priority of its own and
 * a tie broken by anything else would be a hidden preference.
 *
 * The guarantee is `array_sum($parts) === $total` for every input, and it is
 * pinned by a property-style test over many totals and split counts rather than
 * by a handful of hand-picked examples.
 */
final class Allocator
{
    /**
     * @param  list<int>  $weights
     * @return list<int>
     */
    public static function largestRemainder(int $total, array $weights): array
    {
        if ($total < 0) {
            throw new CannotAllocate("Cannot allocate a negative total [{$total}].");
        }

        if ($weights === []) {
            throw new CannotAllocate('Cannot allocate across no shares.');
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new CannotAllocate("Cannot allocate against a negative share [{$weight}].");
            }
        }

        $sum = array_sum($weights);

        if ($sum === 0) {
            throw new CannotAllocate('Cannot allocate across shares that are all zero.');
        }

        $parts = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            $scaled = $total * $weight;
            $parts[$index] = intdiv($scaled, $sum);
            $remainders[$index] = $scaled - ($parts[$index] * $sum);
        }

        $order = array_keys($remainders);

        // A long closure, not an arrow function: an arrow function captures by
        // value at definition, which is fine here, but the comparison reads
        // better spelled out and the tie-break is the point of the method.
        usort($order, function (int $a, int $b) use ($remainders): int {
            return [$remainders[$b], $a] <=> [$remainders[$a], $b];
        });

        $leftover = $total - array_sum($parts);

        for ($given = 0; $given < $leftover; $given++) {
            $parts[$order[$given]]++;
        }

        return array_values($parts);
    }
}
