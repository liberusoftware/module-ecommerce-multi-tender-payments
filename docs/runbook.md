# Runbook

## Symptom: everything that records a tender throws `BindingResolutionException`

The host has not bound `ResolvesPayableTotal`, `ResolvesTenderCapacity`, or
both. This is the module failing the way it is designed to fail; there is no
default binding to fall back on.

```bash
php artisan tinker --execute="var_dump(app()->bound(\
  Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal::class));"
```

Bind them (see `docs/adoption.md` §2). Reading plans and balances still works
without them only where the payable total is already known; anything that needs
a fresh total will not.

## Symptom: `PayableTotalUnknown` for one order but not others

Different failure, different cause. The resolver is bound and answered `null`
for that order. Either the order does not exist as far as the host's resolver is
concerned, or the resolver has a gap. This is a 422 at an API boundary, never a
503 — do not treat it as an outage.

## Symptom: a gift card tender is admitted at zero

The host's `ResolvesTenderCapacity` returned `Money(0, …)` where it meant "no
ceiling". Return `null` for a tender with no known ceiling. Zero means the card
is worth nothing and the module will honour that literally.

## Symptom: `OverAllocatedPlan` on a plan the operator believes is correct

The tenders sum to more than the payable total. The module refuses rather than
clamping, because clamping changes a number the caller supplied. Re-check the
payable total the host resolved: if it changed after the plan was built (a
discount applied, a line removed) the plan must be rebuilt, not trimmed.

## Symptom: `TenderClaimInFlight` that never clears

The lock is held for 10 seconds and released in a `finally`, so a stuck claim
means the process holding it died between acquiring and releasing. It clears on
its own within the TTL. If it does not, the cache store's lock implementation is
the suspect — check what `Illuminate\Contracts\Cache\LockProvider` resolves to.

Do **not** work around it by minting a fresh idempotency key. A fresh key on a
conflict records a second tender for money that moved once.

## Symptom: `TenderLedgerIsAppendOnly`

Something tried to edit or delete a recorded tender. This is correct behaviour.
The entry describes a movement of money that happened at an institution this
module does not control, and editing the row would make the ledger disagree with
the world. Record a reversal instead:

```php
app(ReverseTender::class)($entry, 'reason the operator gave');
```

A reversal is not a refund. It does not create anything in `ecommerce-refunds`.

## Symptom: the outstanding balance looks wrong

There is no cached balance to be stale, so the ledger is the only input. Dump it:

```php
$plan->tenders()->orderBy('id')->get(['id', 'position', 'kind', 'effect', 'amount_minor']);
```

Fold it by hand: start at the payable total, subtract every `captured` amount,
add back every `reversed` amount, ignore every `declined`. If that disagrees
with `OutstandingBalance::forPlan()`, the payable total the host resolves has
changed since the tenders were recorded — the module's arithmetic has no other
input.

## Symptom: a reversal is refused

`CannotReverseTender` covers three cases and the message says which: the entry
is not `captured`, it has already been reversed, or no reason was given. A
declined tender has nothing to reverse.

## Routine: checking a plan is fully satisfied

```php
app(OutstandingBalance::class)->isSatisfied($plan);
```

There is no status column to read and none will be added. "Satisfied" is the
special case of the fold reaching zero.

## Routine: releasing this package

`Tests` runs on push and pull request against `main`. `Install` and
`Compatibility` run on a tag matching `[0-9]+.[0-9]+.[0-9]+` only. `composer.json`
`version` and `module.json` `version` must be equal and must be pushed *before*
the tag, or the boundary suite fails on the tag.
