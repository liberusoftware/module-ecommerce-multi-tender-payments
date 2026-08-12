<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Exceptions;

/**
 * A reversal was refused.
 *
 * Only a captured tender can be reversed, only once, and only with a reason.
 */
final class CannotReverseTender extends MultiTenderPaymentsException {}
