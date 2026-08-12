# Adoption

## 1. Install

The package is not on Packagist. Add a VCS repository entry to the **host's own**
`composer.json` — Composer honours `repositories` only from the root manifest,
so a consumer must declare it itself:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-multi-tender-payments" }
]
```

```bash
composer require liberusoftware/ecommerce-multi-tender-payments
```

Installing boots nothing. The package declares no `extra.laravel.providers`;
the host's `ModuleManagerServiceProvider` globs `config('modules.paths')` for
`*/module.json` and registers only modules named in `MODULES_ENABLED`.

```dotenv
MODULES_ENABLED="…,ecommerce-multi-tender-payments"
```

## 2. Bind the two contracts — this is not optional

Nothing in this module can price a plan until the host binds both. There is no
default binding and there will never be one: a default would mean a
half-configured deployment quietly treating the order total as zero, or a gift
card as bottomless.

```php
// A service provider in the host.
$this->app->bind(ResolvesPayableTotal::class, OrderPayableTotal::class);
$this->app->bind(ResolvesTenderCapacity::class, TenderCapacityRouter::class);
```

`ResolvesPayableTotal` must resolve the total **server-side**, from the order.
Never from a request body.

`ResolvesTenderCapacity` is keyed by `TenderKind`. A host typically routes:

| Kind | Bound to |
| --- | --- |
| `gift_card`, `store_credit` | `ecommerce-gift-cards-and-store-credit` |
| `card`, `bank_transfer` | `null` — the ceiling lives at the issuer |
| `deposit`, `instalment`, `cash` | whatever the deployment decides |

Returning `null` means "no ceiling known". It does **not** mean zero, and a host
that returns zero for a card will find every card tender admitted at nothing.

A deployment that binds neither can still read plans and balances. It cannot
record anything that moves money. That is the correct failure direction.

## 3. Migrations

Two tables, both invented by this package, both carrying the module prefix:

- `multi_tender_payment_plans`
- `multi_tender_payment_tenders`

**No host table is adopted, and that is a deliberate choice rather than an
oversight.** The host's shapes are the faults this module exists to replace:

- `orders.payment_method` is a single nullable string.
- `orders.transaction_id` holds one gateway charge id on the order.
- `orders.payment_status` and `invoices.payment_status` are two independent
  copies of the same idea.
- `orders.total_amount` is `decimal(10,2)`.
- `payment_methods.details` is an unstructured text blob.
- `payment_methods.is_default` is a bare boolean with no unique constraint.
- `payment_methods.user_id` cascades from `users`.

Adopting any of those would carry the fault forward. Nothing here guards on
`Schema::hasTable()` and the host deletes no migration of its own — these are new
tables beside the old ones.

**Migrating off the host's columns.** There is no automated backfill, and one
would be dishonest: `orders.payment_method` is free text with no enum, so
mapping it onto a `TenderKind` is a per-deployment decision. The suggested path:

1. Enable the module and record all *new* payments through it.
2. Backfill historic orders with a host-owned one-off command that opens a plan
   per order and records a single tender of the mapped kind, using
   `orders.transaction_id` as the external reference.
3. Read balances from `OutstandingBalance` and stop writing
   `orders.payment_status`.
4. Drop the host columns once nothing reads them.

## 4. Idempotency and locking

`RecordTender` claims its idempotency key through `Illuminate\Contracts\Cache\LockProvider`,
which the module binds with `bindIf` to the host's default cache store. A host
running a cache driver without lock support (or wanting a different one) binds
`LockProvider` itself before this module's provider registers.

A caller must handle both idempotency outcomes, and must tell them apart by
class:

```php
try {
    $entry = $record($plan, $tender, $key);
} catch (TenderIdempotencyConflict $e) {
    // Permanent. Same key, different facts. Do not mint a fresh key — that
    // would record a second tender for money that moved once.
} catch (TenderClaimInFlight $e) {
    // Transient. Retry the identical request with the same key.
}
```

Never decode a message to decide which happened.

## 5. What this module will not do for you

- It will not move money. Capture and authorisation belong to
  `ecommerce-payment-operations`.
- It will not redeem a gift card or debit store credit. That is
  `ecommerce-gift-cards-and-store-credit`, and this module never even asks what
  a card is worth.
- It will not create a refund. A reversal here is a ledger entry in *this*
  module; deciding money is owed back to a customer is `ecommerce-refunds`.
- It will not run an instalment schedule. Instalments are references only: no
  scheduler, no authoritative due dates, no chasing.
- It will not convert currencies. A mixed-currency plan is refused with
  `MixedCurrencyPlan`.
