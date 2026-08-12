# Ecommerce: Multi Tender Payments Core Module

> This package is the authoritative, provider-neutral implementation of Multi Tender Payments. It owns domain behavior and data; optional API, Filament, Livewire, React, Vue, and Nuxt packages translate its public contracts for their surfaces.

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-multi-tender-payments?sort=semver)](https://github.com/liberusoftware/module-ecommerce-multi-tender-payments/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-multi-tender-payments/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-multi-tender-payments/actions/workflows/tests.yml)

## What this module owns

> **Multi-Tender Payments owns the plan and the arithmetic. It never moves money
> and it never holds a balance.**

Split payments, gift and store credit as tenders, deposits, instalment
references, partial payments, outstanding balances, and allocation.

- Fully compatible with **Laravel 13**, **PHP 8.5**, and **Pest 5**.
- Integer minor units end to end. No `decimal`, `float` or `double` column
  exists in either migration, and a test asserts that against the live schema.
- An append-only tender ledger with no update path and no delete path.
- An outstanding balance that is a fold, proved three independent ways.
- No dependency on `ecommerce-payment-operations`,
  `ecommerce-gift-cards-and-store-credit`, `ecommerce-refunds` or
  `ecommerce-orders` — asserted by namespace and by package name.

## What this replaces

Nine faults in the host application. Each is named here and each has a test in
`tests/Feature/ReplacedHostFaultsTest.php` proving it is gone.

| # | The host's fault | What replaces it |
| --- | --- | --- |
| 1 | `orders.payment_method` is a single nullable `string` — one order, one tender, free text, no enum, no FK, no validation. Multi-tender was not merely unimplemented, it was *unrepresentable*. | Many `TenderEntry` rows per plan, each with a `TenderKind`. |
| 2 | `orders.transaction_id` holds one gateway charge id, on the order. A second tender's reference has nowhere to go but on top of the first. | `external_reference` per tender. |
| 3 | `orders.payment_status` is a string column and `invoices.payment_status` is a second, independent copy of the same idea. Two denormalised columns that can disagree, with nothing between "pending" and "paid" — partial payment has no value to be in. | No status column anywhere. The balance is a number, computed. |
| 4 | `orders.total_amount` is `decimal(10,2)`. Allocation is the one place money arithmetic is not a single addition, and doing it in decimal through a float cast drifts. | Integer minor units, and string arithmetic at the decimal edge. |
| 5 | `payment_methods.details` is a `text` blob — unstructured, unencrypted, holding whatever the caller put there. | No instrument details are stored at all; only an opaque reference the module that moved the money issued. |
| 6 | `payment_methods.is_default` is a bare boolean with no unique constraint, so two rows for one user can both be default and nothing says which wins. | No default and no priority. The caller declares the order; `position` records it. |
| 7 | `payment_methods.user_id` FKs into `users` with `onDelete('cascade')` and has no tenant or site column, so deleting a user silently deletes payment history. | No user foreign key. Every foreign key points inside this module. |
| 8 | There is no allocation record anywhere, so an outstanding balance cannot be computed — only asserted by a status string. | The ledger entry *is* the allocation record: which tender covered which portion of which plan. |
| 9 | Deposits and instalments do not exist in the host in any form — no table, no column, no model. | A deposit is an ordinary tender; an instalment is an external reference on one. |

## The boundary

Four modules exist around this one, and it imports none of them.

| Module | Owns | This module's relationship |
| --- | --- | --- |
| `ecommerce-payment-operations` | authorising and capturing against a gateway | records that a capture *happened*; performs none |
| `ecommerce-gift-cards-and-store-credit` | a redeemable balance | records that a gift card was applied for N minor units; never asks what the card is worth |
| `ecommerce-refunds` | what is owed back | silent on refunds |
| `ecommerce-orders` | the order and its total | is *told* the total |

**There is no transaction across gateways.** A three-tender plan is three
separate movements of real money, at three separate institutions, at three
separate instants. When tender two declines, tender one's capture has already
happened and no application-level rollback can un-happen it. Every decision
below follows from that.

## The settled decisions

1. **The payable total is resolved server-side** through `ResolvesPayableTotal`,
   a contract this module publishes and does not implement, registered with **no
   default binding**. Unbound fails loudly (503 at a boundary); a null answer for
   an order that exists is a different, distinct failure (422). A total is never
   accepted in a request body.
2. **Tender capacity works the same way.** `ResolvesTenderCapacity` is keyed by
   tender kind and bound by the host. A `null` capacity means "no ceiling known"
   — the ordinary answer for a card — and is not zero.
3. **A short tender is partly spent, not refused.** A gift card covering 40% of
   the total contributes 40% and the remainder becomes outstanding. This
   deliberately reverses the deferral recorded in `gift-cards-livewire`, which
   refused a short card only because it had no published total to measure
   against.
4. **Over-allocation is refused outright, never clamped.** Clamping silently
   changes a number the caller gave you.
5. **Under-allocation is valid.** The shortfall is the outstanding balance, and a
   plan covering nothing is a valid, wholly-unsatisfied plan.
6. **No tender kind has a priority.** Tenders apply in the order the caller
   declared, and that order is recorded.
7. **One currency per plan.** A mixed-currency plan is refused with its own
   exception. No default currency, no conversion.
8. **Allocation is exact.** Largest-remainder with the tie-break defined as
   declared order, pinned by a property-style test over many totals and split
   counts asserting `array_sum($parts) === $total` every time.
9. **A deposit is a tender.** Instalments are external references only — no
   scheduler, no authoritative due dates, no chasing.
10. **The ledger is append-only.** No update path, no delete path. A reversal is
    a new entry carrying its own reason. A declined tender never rolls back a
    captured one.
11. **The outstanding balance is a fold.** No status column, no cached total, no
    `amount_paid` column, proved three independent ways including
    order-independence.
12. **Idempotency has two classes.** `TenderIdempotencyConflict` is permanent
    (409); `TenderClaimInFlight` is transient (423 with `Retry-After`). Told
    apart by `instanceof`, never by decoding a message.
13. **A reversal here does not create a refund in `ecommerce-refunds`.**
    Recording that a tender was reversed is a ledger entry in *this* module.
    Deciding that money is owed back to a customer is somebody else's.

## Limits left in place, deliberately

- **No presentation surfaces.** The `-api`, `-filament` and `-livewire`
  packages are separate repositories. The HTTP status codes named above are the
  contract those packages must render; nothing here speaks HTTP.
- **No scheduler.** An instalment reference is a string. This module will not
  tell you what is due when, and holds no due date as authoritative.
- **No currency conversion, and no currency registry.** The exponent arrives
  with the payable total. Nothing here knows that JPY has none — it is told.
- **No backfill command** for the host's existing columns. Mapping free-text
  `orders.payment_method` onto a `TenderKind` is a per-deployment decision, and
  an automated guess would be dishonest. `docs/adoption.md` sets out the manual
  path.
- **No tenant or site column.** The host's fault 7 is fixed here by holding no
  user foreign key at all rather than by adding a tenancy column: a plan keys on
  an opaque `order_reference` and nothing else. Scoping a query to a tenant is
  therefore the host's job, through the same resolvers it already binds. If a
  deployment needs plans partitioned in the database rather than at the seam,
  that is a `0.2.0` column and a migration edited in place, not a workaround.
- **Tax is somebody's input, not this module's concern.** Nothing here looks up
  a rate, knows a jurisdiction, or compounds anything.

## Requirements

- **PHP 8.5**
- **Composer 2**
- A supported database (e.g. MySQL, PostgreSQL, SQLite)

## Quick start

To install this package via Composer, run:

```bash
composer require liberusoftware/ecommerce-multi-tender-payments
```

The package is not on Packagist; the host adds a VCS repository entry first.
Installing boots nothing — there is no `extra.laravel.providers` — and the host
enables the module by name through `MODULES_ENABLED`. See
[docs/adoption.md](docs/adoption.md).

Then bind both contracts. Until the host does, nothing in this module can price
a plan:

```php
$this->app->bind(ResolvesPayableTotal::class, OrderPayableTotal::class);
$this->app->bind(ResolvesTenderCapacity::class, TenderCapacityRouter::class);
```

```php
$plan = app(OpenPaymentPlan::class)('order-9f2c');

$admitted = app(AdmitTenderPlan::class)('order-9f2c', [
    PlannedTender::of(TenderKind::GiftCard, new Money(10_000, 'GBP'), 'gc_7734'),
    PlannedTender::of(TenderKind::Card, new Money(6_000, 'GBP')),
]);

// The gift card was worth 4000, so it is partly spent and the card covers the
// rest. Nothing has moved yet — record each tender as its institution answers.
$entry = app(RecordTender::class)($plan, $admitted->tenders[0], $idempotencyKey);

app(OutstandingBalance::class)->forPlan($plan);
```

## Documentation

- [docs/domain.md](docs/domain.md) — the model, the seams, the fold, the money rules
- [docs/adoption.md](docs/adoption.md) — installing, binding, and migrating off the host's columns
- [docs/runbook.md](docs/runbook.md) — what each failure means and what to do about it
- [Liberu Main Documentation](https://github.com/liberusoftware/documentation)
- [Architecture & Standards Index](https://github.com/liberusoftware/documentation/tree/main/architecture)

## Related Liberu Projects

| Project | Repository | Purpose |
| --- | --- | --- |
| **Boilerplate** | [liberusoftware/boilerplate-laravel](https://github.com/liberusoftware/boilerplate-laravel) | Shared Laravel application foundation and reference composition |
| **CMS** | [liberu-cms/cms-laravel](https://github.com/liberu-cms/cms-laravel) | Structured content, publishing, media, multisite, and headless delivery |
| **CRM** | [liberu-crm/crm-laravel](https://github.com/liberu-crm/crm-laravel) | Customer data, sales, marketing, service, and customer success |
| **Billing** | [liberu-billing/billing-laravel](https://github.com/liberu-billing/billing-laravel) | Products, subscriptions, invoicing, payments, and provisioning |
| **Accounting** | [liberu-accounting/accounting-laravel](https://github.com/liberu-accounting/accounting-laravel) | Ledgers, banking, tax, expenses, close, and financial reporting |
| **Ecommerce** | [liberu-ecommerce/ecommerce-laravel](https://github.com/liberu-ecommerce/ecommerce-laravel) | Catalog, checkout, orders, fulfillment, returns, B2B, and omnichannel commerce |
| **Control Panel** | [liberu-control-panel/control-panel-laravel](https://github.com/liberu-control-panel/control-panel-laravel) | Hosting, infrastructure, DNS, mail, databases, backups, and security operations |
| **Automation** | [liberu-automation/automation-laravel](https://github.com/liberu-automation/automation-laravel) | Governed workflows, provider-neutral AI, approvals, and connectors |

## Security

Please do not report security vulnerabilities through public GitHub issues.
Follow our [Security Policy](https://github.com/liberusoftware/documentation/blob/main/architecture/SECURITY.md) for private reporting and supported versions.

## License

This project is open-source software. You may use, modify, and distribute it
under the terms described in [LICENSE.md](LICENSE.md).

The linked license text is authoritative; this summary is not legal advice.

## Feedback and contributing

Feedback and contributions are welcome. You can help by reporting reproducible
bugs, proposing focused enhancements, improving documentation or translations,
and submitting tested code changes.

Before contributing, please read [CONTRIBUTING.md](https://github.com/liberusoftware/documentation/blob/main/standards/CONTRIBUTING.md) and our
[Code of Conduct](https://github.com/liberusoftware/documentation/blob/main/architecture/CODE_OF_CONDUCT.md). Search existing issues first, then use
the appropriate issue template. Pull requests should explain the problem and
approach, remain focused, include or update tests, pass the required workflows,
and document user-visible or breaking changes.

## Contributors

Thank you to everyone who helps improve Liberu.

<a href="https://github.com/liberusoftware/module-ecommerce-multi-tender-payments/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=liberusoftware/module-ecommerce-multi-tender-payments" alt="Contributors to liberusoftware/module-ecommerce-multi-tender-payments">
</a>

[View the full contributors graph](https://github.com/liberusoftware/module-ecommerce-multi-tender-payments/graphs/contributors).
