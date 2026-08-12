<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Exceptions;

/**
 * An allocation was asked for that has no exact answer.
 *
 * A negative total, a negative share, no shares at all, or shares that are all
 * zero. Every one of those is a caller mistake with no defensible default.
 */
final class CannotAllocate extends MultiTenderPaymentsException {}
