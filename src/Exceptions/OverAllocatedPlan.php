<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Exceptions;

/**
 * The tenders in a plan exceed the payable total.
 *
 * Refused outright, never clamped. Clamping silently changes a number the
 * caller gave you, and the caller is the only one who knows what it meant.
 */
final class OverAllocatedPlan extends MultiTenderPaymentsException {}
