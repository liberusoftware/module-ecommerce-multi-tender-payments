<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Liberu\Ecommerce\MultiTenderPayments\Actions\AdmitTenderPlan;
use Liberu\Ecommerce\MultiTenderPayments\Actions\OpenPaymentPlan;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\PayableTotalUnknown;
use Liberu\Ecommerce\MultiTenderPayments\Plans\PlannedTender;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

it('cannot be constructed at all when the host has bound nothing', function (string $action) {
    // The correct failure direction: a half-configured deployment can read a
    // plan but cannot record anything that moves money. A default binding here
    // would mean quietly treating the order total as zero instead.
    app()->make($action);
})->throws(BindingResolutionException::class)->with([
    [AdmitTenderPlan::class],
    [OpenPaymentPlan::class],
]);

it('fails differently when the resolver answers null for an order', function () {
    // Distinct from unbound, and a caller tells them apart by class, not by
    // reading a message: unbound is a deployment fault, null is a fact about
    // this order.
    bindHost();

    app()->make(AdmitTenderPlan::class)('order-nobody-knows', []);
})->throws(PayableTotalUnknown::class);

it('takes the plan currency from the resolved total and never from a caller', function () {
    bindHost()->total('order-1', new Money(4999, 'EUR', 2));

    $plan = app()->make(OpenPaymentPlan::class)('order-1');

    expect($plan->currency)->toBe('EUR')
        ->and($plan->currency_exponent)->toBe(2);
});

it('reads a column default back off the instance create returned', function () {
    // `create()` does not read column defaults back — the model returned carries
    // null until it is refreshed. The matching `$attributes` entry on the model
    // is what makes this true, and this is the test that would catch its removal.
    bindHost()->total('order-1', new Money(4999, 'GBP'));

    $plan = app()->make(OpenPaymentPlan::class)('order-1');

    expect($plan->currency_exponent)->toBe(2);
});

it('opens one plan per order, however often it is asked', function () {
    bindHost()->total('order-1', new Money(4999, 'GBP'));

    $open = app()->make(OpenPaymentPlan::class);

    expect($open('order-1')->id)->toBe($open('order-1')->id);
});

it('refuses to open a plan for an order with no known total', function () {
    bindHost();

    app()->make(OpenPaymentPlan::class)('order-nobody-knows');
})->throws(PayableTotalUnknown::class);

it('never asks another module what a tender is worth', function () {
    // The capacity resolver is the only thing consulted, and it is the host's.
    $host = bindHost();
    $host->total('order-1', new Money(10_000, 'GBP'));
    $host->capacity(TenderKind::GiftCard, 'card-1', new Money(4_000, 'GBP'));

    $plan = app()->make(AdmitTenderPlan::class)('order-1', [
        PlannedTender::of(TenderKind::GiftCard, new Money(10_000, 'GBP'), 'card-1'),
    ]);

    expect($plan->tenders[0]->admitted->minor)->toBe(4_000);
});
