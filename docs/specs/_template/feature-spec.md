# Feature Spec: <feature name>

> The *what & why*. Disposable — this dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** <name>
- **Bounded context(s):** <Catalog | Listing | Directory | Identity | Billing | Notification | Search>
- **Related PRD items:** <US-x / FR-x>
- **Status:** Draft | Approved | Shipped
- **Date:** YYYY-MM-DD

## Ubiquitous language (this slice)

Define the terms this feature introduces or touches, so code and spec share one vocabulary.

| Term | Means | Not to be confused with |
|---|---|---|
| <Listing> | <a gear advertisement with a 30-day life> | product, ad |

## User story

As a **<persona>**, I want **<capability>** so that **<outcome>**.

## In scope

- <bullet>

## Non-goals (explicit — hold the line)

- <what this feature deliberately does NOT do>

## Acceptance criteria (the Definition of Done checklist)

Enumerated, measurable, each independently checkable. These are what `/verify` checks off.

- [ ] AC-1: <observable behaviour, incl. the value/threshold>
- [ ] AC-2: <failure/edge behaviour>
- [ ] AC-3: <authz / privacy rule respected>
- [ ] AC-4: <performance budget if relevant, e.g. facet query < 200 ms>

## Failure contract

What happens when things go wrong (enumerated, not invented at implementation time):

| Condition | Expected behaviour |
|---|---|
| <invalid input> | <validation error, no domain mutation> |
| <dependency down> | <graceful degrade / retry / user message> |
