<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Exceptions;

use RuntimeException;

/**
 * The root of everything this module refuses to do.
 *
 * Every refusal below is its own class. A caller tells two refusals apart with
 * `instanceof`, never by decoding a message string — two opposite instructions
 * carried by one class and separated by `str_contains` is recorded as a defect
 * in this fleet, not as a shortcut.
 */
abstract class MultiTenderPaymentsException extends RuntimeException {}
