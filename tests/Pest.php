<?php

declare(strict_types=1);

use Liberu\Ecommerce\MultiTenderPayments\Actions\AdmitTenderPlan;
use Liberu\Ecommerce\MultiTenderPayments\Actions\OpenPaymentPlan;
use Liberu\Ecommerce\MultiTenderPayments\Actions\RecordTender;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesTenderCapacity;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Models\PaymentPlan;
use Liberu\Ecommerce\MultiTenderPayments\Plans\AdmittedTender;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;
use Liberu\Ecommerce\MultiTenderPayments\Tests\Fixtures\FakeHost;
use Liberu\Ecommerce\MultiTenderPayments\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Bind the two contracts the module publishes and refuses to implement.
 *
 * Every test that records anything has to do this, which is the point: without
 * it nothing in this module can price a plan at all.
 */
function bindHost(): FakeHost
{
    $host = new FakeHost();

    app()->instance(ResolvesPayableTotal::class, $host);
    app()->instance(ResolvesTenderCapacity::class, $host);

    return $host;
}

/**
 * A host that knows one order's total, and the admission action to try it with.
 *
 * @return array{0: FakeHost, 1: AdmitTenderPlan}
 */
function planningOrder(int $minor = 10_000, string $currency = 'GBP', string $order = 'order-1'): array
{
    $host = bindHost()->total($order, new Money($minor, $currency));

    return [$host, app()->make(AdmitTenderPlan::class)];
}

/**
 * An open plan for one order, and the action that appends to its ledger.
 *
 * @return array{0: PaymentPlan, 1: RecordTender}
 */
function ledger(int $total = 10_000): array
{
    bindHost()->total('order-1', new Money($total, 'GBP'));

    return [app()->make(OpenPaymentPlan::class)('order-1'), app()->make(RecordTender::class)];
}

/** One admitted tender, spelled out so a test can say only what it cares about. */
function tender(int $admitted, ?int $requested = null, TenderKind $kind = TenderKind::GiftCard, int $position = 0): AdmittedTender
{
    return new AdmittedTender(
        $position,
        $kind,
        new Money($requested ?? $admitted, 'GBP'),
        new Money($admitted, 'GBP'),
        'card-1',
    );
}

/**
 * Every PHP file this package ships, for the boundary rules to read.
 *
 * @return list<string>
 */
function sourceFiles(): array
{
    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}
