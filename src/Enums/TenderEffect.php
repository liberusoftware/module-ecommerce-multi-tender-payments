<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Enums;

/**
 * What a ledger entry does to the outstanding balance.
 *
 * There is no "plan failed" effect. A declined tender is recorded because it
 * happened, and it moves the balance by nothing — it does not erase, invalidate
 * or roll back an earlier captured tender, because no application-level
 * rollback can un-happen a capture at another institution.
 */
enum TenderEffect: string
{
    case Captured = 'captured';
    case Declined = 'declined';
    case Reversed = 'reversed';

    /** The sign this effect contributes when the ledger is folded. */
    public function balanceDelta(): int
    {
        return match ($this) {
            self::Captured => -1,
            self::Reversed => 1,
            self::Declined => 0,
        };
    }
}
