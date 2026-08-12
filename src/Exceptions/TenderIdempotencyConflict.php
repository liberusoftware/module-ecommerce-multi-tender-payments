<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Exceptions;

/**
 * The same idempotency key arrived with a different payload.
 *
 * Permanent. A caller retrying this will get the same answer forever, and the
 * boundary renders it 409. Told apart from {@see TenderClaimInFlight} by
 * `instanceof`.
 */
final class TenderIdempotencyConflict extends MultiTenderPaymentsException {}
