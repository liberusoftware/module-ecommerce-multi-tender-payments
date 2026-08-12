<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Contracts;

use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * How much a given tender can actually contribute, answered by the host.
 *
 * The module does not ask what a gift card is worth. It does not know what a
 * balance is, and asking `ecommerce-gift-cards-and-store-credit` directly would
 * make this module uninstallable without it. Instead the host binds one
 * implementation per tender kind and this module's arithmetic asks only:
 * given the payable total and the capacities returned, is this plan admissible?
 *
 * Returning `null` means "no capacity is known for this tender" — the normal
 * answer for a card, where the ceiling lives at the issuer. It is not zero, and
 * the module must not treat it as zero.
 *
 * Registered with no default binding, for the same reason as
 * {@see ResolvesPayableTotal}.
 */
interface ResolvesTenderCapacity
{
    public function capacityFor(TenderKind $kind, ?string $reference): ?Money;
}
