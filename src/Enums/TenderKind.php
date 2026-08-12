<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Enums;

/**
 * The kinds of tender a plan can carry.
 *
 * No kind has a priority. The module does not prefer store credit over card;
 * tenders apply in the order the caller declares and that order is recorded. A
 * deployment that wants gift-card-first expresses it when building the plan.
 *
 * A deposit is a tender, not a separate concept — a tender recorded before the
 * order is complete, folded into the same balance as every other.
 */
enum TenderKind: string
{
    case Card = 'card';
    case GiftCard = 'gift_card';
    case StoreCredit = 'store_credit';
    case Deposit = 'deposit';
    case Instalment = 'instalment';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
}
