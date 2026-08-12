<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Exceptions;

/**
 * Something tried to update or delete a recorded tender.
 *
 * The ledger has no update path and no delete path. Reversal is a new entry
 * carrying its own reason, because the movement of money it describes already
 * happened at an institution this module does not control.
 */
final class TenderLedgerIsAppendOnly extends MultiTenderPaymentsException {}
