<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Plans;

use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * One tender a caller wants to offer, before the module has looked at it.
 *
 * Two ways to declare one, and a plan uses one way or the other, never both:
 *
 * - {@see self::of()} — an explicit amount, the ordinary split.
 * - {@see self::share()} — a weight, and the payable total is split across the
 *   weights exactly, with the remainder distributed by declared order.
 *
 * `$reference` is whatever the host's capacity resolver needs to identify this
 * particular instrument — a gift card code, a store credit account. The module
 * never interprets it.
 */
final readonly class PlannedTender
{
    private function __construct(
        public TenderKind $kind,
        public ?Money $amount,
        public ?int $share,
        public ?string $reference,
        public ?string $instalmentReference,
    ) {}

    public static function of(
        TenderKind $kind,
        Money $amount,
        ?string $reference = null,
        ?string $instalmentReference = null,
    ): self {
        return new self($kind, $amount, null, $reference, $instalmentReference);
    }

    public static function share(
        TenderKind $kind,
        int $weight,
        ?string $reference = null,
        ?string $instalmentReference = null,
    ): self {
        return new self($kind, null, $weight, $reference, $instalmentReference);
    }
}
