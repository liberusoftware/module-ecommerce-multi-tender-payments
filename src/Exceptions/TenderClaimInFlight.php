<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Exceptions;

/**
 * The same idempotency key is being processed right now.
 *
 * Transient. The caller should retry the identical request, and the boundary
 * renders it 423 with `Retry-After`. Told apart from
 * {@see TenderIdempotencyConflict} by `instanceof`.
 */
final class TenderClaimInFlight extends MultiTenderPaymentsException {}
