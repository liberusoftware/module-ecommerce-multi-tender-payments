<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Events;

use Liberu\Ecommerce\MultiTenderPayments\Models\TenderEntry;

/** A tender was appended to the ledger — captured, or declined. */
final readonly class TenderRecorded
{
    public function __construct(public TenderEntry $tender) {}
}
