<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Exceptions;

/**
 * A plan tried to mix currencies, or to mix exponents within one currency.
 *
 * There is no default currency and no conversion in this module.
 */
final class MixedCurrencyPlan extends MultiTenderPaymentsException {}
