<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Events;

use Liberu\Ecommerce\MultiTenderPayments\Models\TenderEntry;

/**
 * A captured tender was reversed by a new ledger entry.
 *
 * Listeners must not read this as "a refund is owed". That decision belongs to
 * `ecommerce-refunds` and this module never makes it.
 */
final readonly class TenderReversed
{
    public function __construct(public TenderEntry $reversal) {}
}
