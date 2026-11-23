# ADR-0024: Price is compared only within one currency — a price filter carries its currency; no conversion

- **Status:** Accepted
- **Date:** 2026-11-23
- **Established by** the `search-range-facets` slice ([spec](../specs/search-range-facets/feature-spec.md)
  Q1/Q1b/Q10, [plan](../specs/search-range-facets/technical-plan.md) D1, D4, D12; owner chose
  option A and Q1b = drop, 2026-11-23) — Phase 2 item 2.
- **Answers** [ADR-0019](./0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
  Consequences: *"Price facets and sorting across currencies must be decided by Phase 2 before
  `price_amount` is indexed … Until then, nothing sorts or filters on price."*

## Context

A listing's price is a `Price`: an integer amount of **minor units** plus a `Currency` from a closed
allow-list. `Price::equals()` compares both parts; there is **no ordering** across currencies, on
purpose — 100 EUR and 100 USD are not comparable without an exchange rate, and the business owns no
exchange rate.

`search-range-facets` adds a price filter to `/browse`. A buyer types a range; listings are priced in
several currencies. Something must decide what "between 100 and 500" means for a listing priced in a
different currency than the buyer was thinking in.

At the same time the owner narrowed the allow-list (Q10): `UAH` leaves it — "we'll add other
currencies later". The allow-list is now **`USD`, `EUR`, `SEK`**, all with two minor digits.

## Decision

1. **A price filter is a currency plus an optional range, and it restricts the browse to listings
   priced in that currency** (option A). The SQL is one predicate in Search's one matching fragment:
   `l.price_currency = :pc [AND l.price_amount >= :plo] [AND l.price_amount <= :phi]`. No conversion
   happens anywhere. The form says so: *"Choosing a currency hides listings priced in other
   currencies."*
2. **Bounds without a valid currency are dropped whole** (Q1b). No default currency is assumed — a
   silent `EUR` would compare a SEK buyer's numbers against euros.
3. **Bounds are whole units** (`[0-9]{1,7}`), converted to minor units by the currency's exponent
   (`× 10^minorUnits`) with integer arithmetic, never through a float.
4. **Future-sort rule:** a price sort is offered only with a currency chosen, ordering `price_amount`
   within it. A sort across currencies is the revisit trigger below, not a feature.
5. **Search holds its own `Currency` enum — a mirror of Listing's** (cases by value, plus
   `minorUnits()`), because Search's Domain may not import Listing (ADR-0023, fence point 4) yet needs
   the allow-list and the exponent to resolve a bound. Drift is caught by `CurrencyMirrorTest`, a unit
   test reading both enums. **Adding a currency means adding it to both.** The exponent is the only
   dangerous drift (a ×100 bug); the test pins it.
6. **The allow-list is `USD`, `EUR`, `SEK`.** Re-adding a code (e.g. `UAH`) is a code change in both
   enums plus a test, never a migration (ADR-0019 d.7). Removing one needs a pre-deploy row check —
   a stored row naming a removed code fails to hydrate (see `docs/infrastructure.md` §*Listing*).

## Alternatives

| | **A. One chosen currency** *(chosen)* | B. Static rate table | C. Live daily rates | D. Normalised column at write |
|---|---|---|---|---|
| Moving parts | none | config + owner discipline | a job (ADR-0012 d.5 re-opened), a rates provider, a Search table (a new ADR under ADR-0023), a failure mode for missing rates | Listing migration + backfill; a write-path change for a read need |
| Correctness | exact within a currency | silently wrong by the drift | right to the day; wrong when a fetch fails | wrong from the next day |
| Index later | `(price_currency, price_amount)` serves it directly | expression over a join | same as B | plain index |
| Honest to the model | yes — `Price` has no cross-currency order | invents an order the business does not own | same, fresher numbers | freezes a guess into Listing |

## Consequences

- A buyer browsing without a currency sees every price; choosing one hides the others. That is the
  visible cost of honesty, stated on the page.
- `(price_currency, price_amount)` is the index shape if `search-benchmark` (slice 4) wants one.
- **Revisit trigger:** after launch, when one top-level category holds **≥ 2 currencies each with
  ≥ 20 % of its published listings** (the query is in `docs/infrastructure.md`), or when a
  cross-currency sort is asked for. Then option C, as its own ADR. *If `UAH` has returned by then: the
  ECB's reference rates are believed not to cover it (verify then), so C would need a second source,
  e.g. the National Bank of Ukraine.*
- ADR-0019's Consequences: "cross-currency comparison is undecided" → answered here; "nothing filters
  on price" → no longer true; "nothing sorts" → still true.
