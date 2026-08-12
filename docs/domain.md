# Domain

## The boundary

> Multi-Tender Payments owns the plan and the arithmetic. It never moves money
> and it never holds a balance.

Four modules exist around it, and this one imports none of them.

| Module | Owns | This module's relationship |
| --- | --- | --- |
| `ecommerce-payment-operations` | authorising and capturing against a gateway | records that a capture *happened*; performs none |
| `ecommerce-gift-cards-and-store-credit` | a redeemable balance | records that a gift card was applied for N minor units; never asks what the card is worth |
| `ecommerce-refunds` | what is owed back | silent on refunds; reversing a tender is a different ledger entry |
| `ecommerce-orders` | the order and its total | is *told* the total through `ResolvesPayableTotal` |

`tests/Unit/BoundaryTest.php` asserts the absence of all four namespaces by
name, and the absence of all four Composer packages from the manifest.

## The fact that shapes everything

**There is no transaction across gateways.** A three-tender plan is three
movements of real money, at three institutions, at three instants. When tender
two declines, tender one's capture has already happened and no application-level
rollback can un-happen it.

Consequences, each pinned by a test:

- The ledger is append-only. No update path, no delete path.
- A declined tender does not erase, invalidate or roll back a captured one.
- There is no "plan failed" state. A plan is satisfied or it has an outstanding
  balance, and both are computed.
- Partial satisfaction is the normal case. Full satisfaction is the special case
  where the balance reaches zero.

## Aggregates

### `PaymentPlan`

`order_reference`, `currency`, `currency_exponent`. That is the whole of it.
No status, no `amount_paid`, no cached balance. The currency has exactly one
origin — the payable total the host resolved — which is what makes "every
tender is in the order's currency" enforceable at all.

### `TenderEntry`

One row per thing that happened, and simultaneously the allocation record the
host never had: which tender covered which portion of which plan, in which
declared position, with which external reference.

| Column | Why |
| --- | --- |
| `position` | the caller's declared order, recorded rather than inferred |
| `kind` | a `TenderKind`, not free text |
| `effect` | `captured`, `declined` or `reversed` |
| `amount_minor` | what it contributed, integer minor units |
| `requested_minor` | what was asked of it, so a partial spend is visible |
| `external_reference` | whatever the module that moved the money handed back |
| `instalment_reference` | an external schedule identifier, nothing more |
| `reverses_tender_id` | set on a reversal, pointing at what it reverses |
| `reason` | required on a reversal |
| `idempotency_key`, `payload_hash` | the two-class idempotency scheme |

## The two seams

Both contracts are published by this module, implemented by neither this module
nor any sibling, and registered with **no default binding**.

```php
interface ResolvesPayableTotal
{
    public function payableTotalFor(string $orderReference): ?Money;
}

interface ResolvesTenderCapacity
{
    public function capacityFor(TenderKind $kind, ?string $reference): ?Money;
}
```

- **Unbound** is a deployment fault. It fails at the container, loudly, and a
  boundary package renders it 503.
- **Null for an order that exists** is a fact about that order, raised as
  `PayableTotalUnknown`, and a boundary package renders it 422.
- **Null capacity** means "no ceiling known to us" — the ordinary answer for a
  card, whose limit lives at the issuer. It is not zero.

A money figure is never accepted in a request body. That hole has the same shape
as accepting a tenant id, and the payable total is the figure every other number
in the module is measured against.

## Admission

`AdmitTenderPlan` is pure arithmetic. It stores nothing and moves nothing.

1. Resolve the payable total. Null is `PayableTotalUnknown`; negative is
   `CannotAllocate`.
2. Work out what each tender is being asked for. A plan declares **amounts** or
   it declares **shares**, never both — shares are relative to a total that
   declared amounts have already claimed part of, so mixing them is ambiguous
   and is refused.
3. For a share plan, split the payable total with `Allocator::largestRemainder`.
4. Ask the host for each tender's capacity. `min(requested, capacity)` when a
   capacity is known — a short tender is **partly spent**, not refused.
5. If the admitted total exceeds the payable total, refuse with
   `OverAllocatedPlan`. Never clamp.
6. Otherwise return an `AdmittedPlan`. Under-allocation is fine, and the
   shortfall is the outstanding balance.

## Allocation

`Allocator::largestRemainder(int $total, array $weights): array` gives every
share its floor and hands the leftover units to the largest fractional
remainders. The tie-break is **declared order** — the earlier share wins —
because the module has no tender priority and a tie broken any other way would
be a hidden preference.

The guarantee is `array_sum($parts) === $total` for every input. It is pinned by
a property-style test over 504 total/count/weight combinations, not by picked
examples.

## The fold

The outstanding balance is the payable total plus the signed sum of the ledger:

| Effect | Delta |
| --- | --- |
| `captured` | −1 × amount |
| `reversed` | +1 × amount |
| `declined` | 0 |

Being a sum of signed deltas is what makes it order-independent, and
order-independence is what makes it trustworthy. `tests/Feature/OutstandingBalanceTest.php`
proves the same answer three independent ways over a ledger with mixed kinds, a
reversal, a partial capture and an out-of-sequence entry — and then over every
permutation of a smaller one.

## Money

Integer minor units everywhere. No `decimal`, `float` or `double` column exists
in either migration and a test asserts it against the live schema.

`(int) (19.99 * 100)` is `1998`. `Money::fromDecimalString()` splits on the
point, pads the fraction to the exponent, concatenates and casts once. A longer
fraction is truncated, never rounded: rounding a caller's figure invents money.

The API envelope is `{"minor": 1999, "currency": "GBP", "exponent": 2,
"decimal": "19.99"}` with `decimal` a string.

## Events

- `TenderRecorded` — a tender was appended, captured or declined.
- `TenderReversed` — a captured tender was reversed.

Neither means a refund is owed. That decision belongs to `ecommerce-refunds`.
