# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-12

First release. The module owns the payment plan and its arithmetic; it never
moves money and it never holds a balance.

### Added

- `ResolvesPayableTotal` and `ResolvesTenderCapacity`, published as contracts
  and deliberately left unbound. A money figure is resolved server-side and is
  never accepted from a caller.
- `AdmitTenderPlan`, the whole of the module's arithmetic: a short tender is
  partly spent, an over-allocated plan is refused outright, an under-allocated
  plan is valid and its shortfall is the outstanding balance.
- `Allocator::largestRemainder()`, an exact integer split with the tie-break
  defined as declared order.
- An append-only tender ledger with no update path and no delete path.
  `ReverseTender` appends a new entry carrying its own reason.
- `OutstandingBalance`, a fold over that ledger. No status column, no cached
  total, no `amount_paid`.
- Deposits as ordinary tenders; instalments as external references only.
- Two-class idempotency: `TenderIdempotencyConflict` (permanent) and
  `TenderClaimInFlight` (transient), told apart by `instanceof`.
- `Money`, in integer minor units, with decimal conversion done as string
  arithmetic and the settled `{minor, currency, exponent, decimal}` envelope.

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-multi-tender-payments/releases/tag/0.1.0
