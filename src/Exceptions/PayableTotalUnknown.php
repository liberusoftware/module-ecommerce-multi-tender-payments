<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Exceptions;

/**
 * The payable-total resolver answered null for an order that exists.
 *
 * Distinct from the resolver being unbound. Unbound is a deployment fault and
 * surfaces as a container failure the boundary renders 503; a null answer is a
 * fact about this order and is a 422.
 */
final class PayableTotalUnknown extends MultiTenderPaymentsException {}
