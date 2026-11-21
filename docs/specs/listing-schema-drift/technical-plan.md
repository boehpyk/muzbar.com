# Technical Plan: listing-schema-drift

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

## Decisions

**Bold** rows go into **ADR-0022** or a dated amendment (task T0).

### Open questions for the owner (the plan is written to the recommendation)

| # | Question | Recommendation |
|---|---|---|
| Q1 | **Does hard delete survive?** ADR-0004 says "deleting soft-deletes". | **Yes, for rows nobody uses; Archive is a separate, always-available button.** C-25/C-26 stay exactly as they are and become the guard of *hard* delete; their refusal now points at Archive. Reasons: a typo attribute defined a minute ago should not linger in an archive for ever (and, by Q2, reserve its code); the existing delete code and its tests stay; and two explicit buttons beat one button that silently does one of two things depending on data the admin cannot see. |
| Q2 | **Does an archived code still block C-21 (and an archived option label C-17)?** | **Yes — names stay reserved (C-29).** (1) Values point at the archived row's id; a *new* "brand" next to the archived one is the override ADR-0018 rejected: two attributes, one facet split in two, values on the wrong side. Re-using the name means **restoring** the row, which brings its values back too. (2) **Restore then needs no facts and cannot be refused** — otherwise it would be a **fourth door to C-21** (the CLAUDE.md watch item), and for C-17 a pre-check on a write that today has none. (3) No index or query changes: `codesDefinedOn()`, `isLabelTaken()` and both unique indexes already read every row. Cost: an admin who wants the code for something *different* must recode the archived attribute first (its edit page stays reachable — Q6) or delete it if unused. |
| Q3 | **Can an archived attribute / option be restored?** | **Yes.** It is the reason to keep values at all, and the escape hatch for Q2. One method and one route each; no facts (Q2). Dormant values wake up by themselves, because nothing was ever deleted. |
| Q4 | **A dormant value and the seller's next save: kept or dropped?** Today revise replaces the whole value set from the form, and the form never shows a dormant value, so it would be dropped silently. | **Kept (L-17): every revise that keeps the category carries dormant values over; a re-file drops them** (L-10 unchanged). "Retained" that lasts only until the seller fixes a typo is not retention: restore would bring back values only for listings nobody touched, and the seller would have lost data they never saw. A re-file is different: the seller explicitly chose a new schema and the form was rendered for the old one — L-10 already says "keep only what the new category asks". Cost: a few lines in `revise()` and one helper pair on `AttributeValues`. |
| Q5 | **A stored value that is an *archived option* (the attribute is still in force).** Hide it? Drop it? | **Treat it as a narrowed rule — exactly like a number outside narrowed bounds (a stale value).** Shown as stored on the public page ("Brand: Fender" stays true of the gear); never retroactive; the seller's edit form shows it **selected** as *"Fender (no longer offered)"*, and the next save must change or clear it (L-9 refuses with its own message); publish refuses it (L-14). Hiding it would make a published listing silently lose a *required* detail; dropping it on save would make a value vanish that the seller could see. Phase 2 facets do not offer archived options as buckets (Risk 3). Alternative if you prefer: hide it on the public page too (one `AND archived_at IS NULL` in `optionLabels()`). |
| Q6 | **Is an archived attribute frozen** (no relabel / recode / rule change / new option)? | **No.** No invariant needs it and each such edit is harmless on a row nobody sees. The admin UI leads with Restore and shows an "Archived on …" banner on the edit page, which stays reachable (needed to recode a reserved code away, Q2). Five "refuse if archived" checks would be rules with no reason. |
| Q7 | **Show the admin how many listings an archive or a move affects?** | **No, not in this slice.** Both acts are reversible and destroy nothing; a count is a read model with no decision behind it. A later admin-UX slice can add it. |

### Design decisions

| # | Decision | Recommendation | Alternatives considered |
|---|---|---|---|
| **D1** | **Archive / restore are transitions on both aggregates (C-28)** | `Attribute` and `AttributeOption` gain `private ?\DateTimeImmutable $archivedAt`, `archive(\DateTimeImmutable $now): void` (no-op when archived — keeps the first instant), `restore(): void` (no-op when active), `archivedAt()`, `isArchived()`. Handlers pass the shared `Clock`'s now (whole seconds, ADR-0007). ADR-0012's line: archiving is a **state** an aggregate governs; hard delete stays non-existence, handled by handler + port as today. No events (Catalog has none, ADR-0017 D10; Search reads Postgres). | **`bool $archived`:** loses "archived on …" for the admin page for no saving. **`status` enum (`Active`/`Archived`):** a second column to keep in step with the timestamp, or a timestamp nobody can see. **An archive table** (`catalog_attribute_archive`): moves rows, so every id reference breaks — the opposite of the point. |
| **D2** | **Names stay reserved; restore takes no facts (C-29)** | Q2. Nothing changes in `codesDefinedOn()`, `isLabelTaken()`, `existsForCategory()` or the indexes: they already read archived rows, and the plan's job is to say that this is **on purpose** (docblocks + AC-13 + mutation (h)). | **Archived names free:** partial unique indexes `WHERE (archived_at IS NULL)` on both `(category_id, code)` and `(attribute_id, label_key)`, a filtered `codesDefinedOn()`, and a C-21 + C-17 check on restore — the fourth door, for the facet-splitting override ADR-0018 rejected. |
| **D3** | **C-18 counts active options** | `countForAttribute()` is **renamed** `countActiveForAttribute()` (`… AND archived_at IS NULL`) — a port method whose meaning changes must change its name, or an old caller silently keeps the old meaning. `Attribute::allowOptionRemoval(int $activeOptionCount)` keeps its body; its docblock now says *"an active option is leaving the active set — archived or deleted"*. Archive handler: `if (!$option->isArchived()) { allowOptionRemoval(count) ; archive }`. Remove handler: C-18 only when the option is active, then C-26 (order unchanged). A defined choice starts with ≥ 1 active option (unchanged). | **Count all options:** a choice whose every option is archived renders an empty select — and if `required`, no listing below it could ever be published again. **Pass the option into `Attribute`:** an aggregate taking another aggregate as an argument for one bool. **A separate `allowOptionArchival()`:** the same rule twice. |
| **D4** | **The active schema is the only one Listing ever sees** | `CategoriesForListing::schemaOf()`: `JOIN catalog_attribute a ON a.category_id = up.id AND a.archived_at IS NULL`, `LEFT JOIN catalog_attribute_option o ON o.attribute_id = a.id AND o.archived_at IS NULL` (in the **join**, not the `WHERE`, or a choice with only archived options vanishes instead of yielding an empty rule — C-18 forbids that state, but a read model must not change shape on bad data). `AttributeSchema`, `AttributeRule` and the `Categories` port are **unchanged** — "archived" never enters Listing's language; to Listing an archived attribute is simply not in force. | **`AttributeRule::isArchived()` in the fact:** Listing would need a word for a Catalog concept, and every rule would have to ignore it. |
| **D5** | **Dormant values: kept by revise on an unchanged category, ignored by every rule (L-17)** | `AttributeValues::onlyFor(AttributeSchema): self` and `::notIn(AttributeSchema): self` (mirrors `AttributeInput::onlyFor()`; both use `$schema->has()`). In `revise()`, after L-9: `if (category unchanged) { $values = AttributeValues::of(...$values->toList(), ...$this->attributeValues->notIn($schema)->toList()); }` — disjoint by construction (`read()` only yields in-force ids, `notIn()` only others). L-13's `missingFrom()` iterates rules, so dormant values are already invisible to it. Re-file: unchanged (input only, L-10). An input naming a dormant attribute: still `NotInForce` (`read()`), unchanged. Docblock: *dormant is a fact about the pair (value, today's schema), not a state of the value — nothing is stored*. | **Drop on next save** (no Domain change): Q4. **Mark rows `dormant` in `listing_attribute_value`:** a stored copy of a derived fact (C-22's "never stored"), rewritten by every archive and move — the N-row bypass ADR-0018 rejected. **Carry over on re-file as well:** values from an unrelated branch would linger and wake up unasked if the listing ever returned. |
| **D6** | **Publish re-checks in-force values only (L-14 narrowed); stale values are still refused** | `publish()`: `$schema->read(self::asInput($this->attributeValues->onlyFor($schema)))`. An archived option is **in force** (its attribute is), so `read()` refuses it (`NotAnOption`) — that is Q5's "stale", and mutation (d) pins the difference. | **Skip stale values too:** a published listing could claim an option no seller can choose today, at the moment it becomes public. |
| **D7** | **C-27 retired; its port method and exception arm deleted** | `EditCategoryHandler`: remove the C-27 block (order becomes C-4 → C-5 → C-21 → C-24); `$subtree` is still needed for C-21. Delete `CategoryUsage::hasAttributeValues()`, `ListingCategoryUsage::hasAttributeValues()`, `CategoryHasListings::cannotMove()` / `isMove()`, the admin message, and `ObservedCategoryUsage`'s recording of it. **No dead code** (CLAUDE.md). The handler's accretion-point comment is rewritten: *"a move may take attributes out of a listing's schema — allowed since listing-schema-drift; those values go dormant (L-17) and wake if the move is undone"*. | **Narrow C-27** to "refuse when a value would leave the schema" (ADR-0020's precise rule): with L-17 there is nothing left to protect — a dormant value is the designed outcome, not damage. **Keep `hasAttributeValues()` "for later":** a port method with no caller. |
| **D8** | **No partial indexes, no new index** | Catalog tables are hundreds to low thousands of rows, read by `category_id` / `attribute_id` / PK; `archived_at` is a filter on rows already found. No `(…) WHERE (archived_at IS NULL)` index (and if one is ever added, write the predicate in PostgreSQL's normalised form — the CLAUDE.md partial-index footgun). | **Partial indexes now:** no measured need; each is a re-diff risk. |
| **D9** | **`DbalAttributeSchema` shows active by default; archived rows only where the admin asks** | `effectiveFor()`: `AND a.archived_at IS NULL`, and `option_count` counts active options (`count(o.id) FILTER (WHERE o.archived_at IS NULL)`). It serves the admin list, the seller form and the public page — the safe default is "active". New **`archivedDefinedOn(string $categoryId): list<ArchivedRow>`** (the category's **own** archived attributes, with `archivedAt`; one query, admin index only). `find()` and `optionsOf()` gain `archivedAt: ?string` — each gets **its own** `@phpstan-type` rather than widening `AttributeRow`/`OptionRow` (phpstan cannot intersect shapes). `optionsFor(list $attributeIds, list $keep = [])`: active options **plus** the `$keep` ids, each row with `archived: bool` (`WHERE attribute_id IN (…) AND (archived_at IS NULL OR id IN (…))`). `optionLabels()` **unchanged** — it labels stored ids whatever their state (Q5). | **A `bool $includeArchived` parameter on `effectiveFor()`:** a flag every caller must remember; the public page forgetting it is a leak. **Filter in PHP:** the "never filter in PHP" rule from `DbalSellerDrafts`. |
| **D10** | **The seller form shows a stale option selected and explains it** | `SellController::edit()` loads stored values **before** the schema (still five queries), passes the stored choice option ids as `optionsFor(…, keep: …)`, and labels an archived one *"<label> (no longer offered)"*. It stays a valid `ChoiceType` choice so it pre-fills and reaches the Domain, which refuses it (`NotAnOption`); `addDetailRefusal()` turns that into *"<label> is no longer offered. Choose another."* when the submitted id is one of the archived options it rendered, else the existing message. A **new** draft passes no `keep`, so archived options are never offered. | **Leave it out of the select:** the field renders empty, and "blank = clear" silently drops the value on save — a value the seller could see vanishing without explanation. **A new `InvalidAttributeValueReason::Archived`:** the Domain cannot tell "archived" from "never existed" (D4: the fact has no such word), and does not need to. |
| D11 | **Admin UI: Archive / Restore buttons, an "Archived" section** | `AttributeAdminController` gains four POST routes (`admin_attribute_archive`, `admin_attribute_restore`, `admin_attribute_option_archive`, `admin_attribute_option_restore`), CSRF first → 403 (the `delete()` precedent), 303 with the flash. Option actions reuse `attributeForOptionAction()` / `optionAction()` and redirect, so the next page gets a fresh EntityManager (CLAUDE.md). Templates: Archive per active row, "Archived" section on the defining category's list, "Archived options" on the edit page, the banner on an archived attribute's edit page. Refusal messages for C-25/C-26/C-18 per the spec. `CategoryAdminController`: the C-27 message arm deleted. | **A Live Component toggle:** an admin page that is all plain forms today. |
| D12 | **Four commands, four handlers** | `ArchiveAttribute(id)`, `RestoreAttribute(id)`, `ArchiveAttributeOption(attributeId, optionId)`, `RestoreAttributeOption(attributeId, optionId)`. Each: `exclusively(get → (C-18) → archive/restore → save)`. Option handlers reuse `RemoveAttributeOptionHandler`'s "option of this attribute" rule (malformed / unknown / another attribute's → `AttributeOptionNotFound`). | **One `SetArchived(id, bool)`:** a command named after a column rather than an intention. |

## Domain layer (pure PHP)

### `Domain/Catalog/Entity/`

```
Attribute / AttributeOption
  private ?\DateTimeImmutable $archivedAt          mapped; null when active (C-28)

  archive(\DateTimeImmutable $now): void           no-op when archived (keeps the first instant)
  restore(): void                                  no-op when active; takes no facts (C-29)
  archivedAt(): ?\DateTimeImmutable
  isArchived(): bool
```

Constructors set `archivedAt = null`. Docblocks: C-28 (archive is a state, delete is not — ADR-0012),
C-29 (the name stays reserved, which is why restore needs no facts — say it at `restore()`), and on
`Attribute::allowOptionRemoval()`: the count is of **active** options and the call is made whenever
an active option leaves the active set.

### `Domain/Catalog/Port/`

- `AttributeOptionRepository::countForAttribute()` → **`countActiveForAttribute()`** (D3).
- `AttributeRepository::codesDefinedOn()` / `existsForCategory()`, `AttributeOptionRepository::isLabelTaken()`:
  **no change**; docblocks gain *"archived rows included, on purpose (C-29 / C-13)"*.
- `CategoryUsage::hasAttributeValues()` **deleted** (D7). `AttributeUsage`: docblock re-scoped to
  *"guards hard delete; counts dormant values too — they are real rows naming real ids"*.

### `Domain/Catalog/Exception/`

- `CategoryHasListings`: `cannotMove()` and `isMove()` **deleted**.

### `Domain/Listing/ValueObject/`

- **`AttributeValues`** gains `onlyFor(AttributeSchema $schema): self` and `notIn(AttributeSchema
  $schema): self` (D5).

### `Domain/Listing/Entity/Listing`

```
revise(…unchanged signature…)
    L-6 → (category changes: L-15, L-2, input = input->onlyFor(schema))     (unchanged)
    values = schema->read(input)                                            (L-9, unchanged)
    category unchanged → values = values ∪ this->attributeValues->notIn(schema)   (L-17, new)
    Published → L-13 on values                                              (unchanged; dormant never counted)
    assign

publish(…)
    … schema->read(asInput(this->attributeValues->onlyFor(schema)))         (L-14 narrowed)
```

Docblock: the L-17 paragraph (*dormant = not in force on today's schema; kept by every revise that
keeps the category, dropped by a re-file, never read by L-9/L-13/L-14; a derived fact, never stored*)
and the stale-vs-dormant sentence (*an archived option is a narrowed rule, like narrowed bounds — not
dormant*). The "Catalog's C-25/C-26/C-27 refuse…" sentence becomes "C-25/C-26".

### Ports (Listing)

`Categories::schemaOf()`: signature unchanged; docblock *"the **active** effective schema: archived
attributes are not in force, archived options are not among a rule's options"*.

## Application layer

- **New** (`Application/Catalog/Command` + `Handler`): `ArchiveAttribute`, `RestoreAttribute`,
  `ArchiveAttributeOption`, `RestoreAttributeOption` (D12). Archive handlers take `Clock`. Every one
  inside `CategoryTreeLock::exclusively()`.
- **`RemoveAttributeOptionHandler`:** `if (!$option->isArchived()) { $attribute->allowOptionRemoval(
  $this->options->countActiveForAttribute(…)); }` then C-26 (unchanged); class docblock: refusal
  replaced by "archive is the in-use path".
- **`DeleteAttributeHandler`:** code unchanged; docblock (*"…turns this into archiving, here"* →
  *"hard delete, for rows no listing uses; archive is `ArchiveAttributeHandler`"*).
- **`EditCategoryHandler`:** C-27 removed (D7); `CategoryUsage` still injected for C-24.
- **Listing:** no change. `ReviseListingHandler` already reads `schemaOf()` and hands it to the
  aggregate, which now does the rest.
- Idempotency: archive-on-archived and restore-on-active are aggregate no-ops.

## Infrastructure layer

### Persistence

- `Attribute.orm.xml`, `AttributeOption.orm.xml`: `<field name="archivedAt" column="archived_at"
  type="datetimetz_immutable" nullable="true"/>`.
- **Migration** generated by `make migration.make` (two `ALTER TABLE … ADD archived_at TIMESTAMP(0)
  WITH TIME ZONE DEFAULT NULL`). No backfill, no index. Re-run `make migration.make` → empty.
- `DoctrineAttributeOptionRepository::countActiveForAttribute()` (rename + `AND archived_at IS NULL`).

### Cross-context adapters

- `CategoriesForListing::schemaOf()`: the two join conditions (D4).
- `ListingCategoryUsage::hasAttributeValues()`: deleted (D7). `ListingAttributeUsage`: unchanged.

### Read model

- `DbalAttributeSchema`: D9 (`effectiveFor()` filter + active count, `archivedDefinedOn()`,
  `archivedAt` on `find()`/`optionsOf()` with their own phpstan types, `optionsFor(…, keep)`).
  `optionLabels()` unchanged.

### HTTP / UI

- `AttributeAdminController`: D11. `CategoryAdminController`: delete the C-27 arm.
- `SellController::edit()`: D10 (values before schema; `keep`; label suffix; refusal message).
- Templates: `admin/attribute_index` (Archive, "Archived" section), `admin/attribute_edit` (option
  Archive/Restore, "Archived options", banner), `listing/_form` (nothing — the label comes from the
  controller). `listing/show.html.twig`: **no change** (dormant values already fall out of
  `effectiveFor()`; stale options are labelled by `optionLabels()`).

### DI wiring

Nothing new: handlers autowired; `Clock` already aliased.

### Async / schedule / external

None.

## Interface boundary & input contract

| Surface | Accepts | Refuses |
|---|---|---|
| `POST /admin/attributes/{id}/archive` · `/restore` | `_token` (`archive-attribute-<id>` / `restore-attribute-<id>`), `ROLE_ADMIN` | bad token → 403; unknown → 404; GET → 405 |
| `POST /admin/attributes/{id}/options/{optionId}/archive` · `/restore` | `_token` (`archive-option-<optionId>` / `restore-option-<optionId>`) | bad token → 403; unknown / another attribute's option → 404; last active option → error flash |
| `DELETE`-style POSTs (existing) | unchanged | in use → `AttributeInUse` → flash pointing at Archive |
| `ArchiveAttribute` / `RestoreAttribute` | attribute id | malformed / unknown → `AttributeNotFound` |
| `ArchiveAttributeOption` / `RestoreAttributeOption` | attribute id, option id | `AttributeNotFound`, `AttributeOptionNotFound`, `AttributeNeedsAnOption` (archive only) |
| `Categories::schemaOf()` | unchanged | — (returns the active schema) |
| `AttributeValues::onlyFor()` / `notIn()` | an `AttributeSchema` | — |
| Seller edit POST | unchanged fields | stale archived option → 422 "no longer offered"; input for a dormant attribute → 422 "no longer applies" |

## Data & migrations

```sql
ALTER TABLE catalog_attribute        ADD archived_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL;
ALTER TABLE catalog_attribute_option ADD archived_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL;
```

- **No backfill, no pre-deploy query.** Every existing row is active (NULL). C-27's removal needs no
  data change: no stored value is dormant before the first archive or move. **Prod is consistent after
  this deploy on its own.**
- **No new index** (D8). `listing_attribute_value` is **untouched** (its AC-6 shape guard from slice 2
  must still pass unchanged).
- **Deploy window** (as the three previous Listing slices): between `up -d` and `migrate`, every
  query naming `archived_at` fails — admin attribute pages, the seller edit page and POSTs, the public
  listing page — for seconds. Accepted; `docs/infrastructure.md` note extended.

## Test plan

- **Domain unit:** `AttributeTest`/`AttributeOptionTest` (AC-1, AC-2, allow-lists AC-7),
  `AttributeValuesTest` (AC-6), `ListingTest` (AC-3…AC-5).
- **Factories:** `AttributeFactory->archived()`, `AttributeOptionFactory->archived()` — through
  `archive()` with a whole-second instant, never by setting the column. Dormant values in tests are
  made the honest way: store through the aggregate, **then** archive or move through the handlers
  (`ListingFactory::withValues()` cannot build one, and must not learn to).
- **Integration (DAMA):** a new `SchemaDriftTest` (AC-9, AC-10, AC-14 — **fresh EntityManager /
  repository** before every read-back; "rows of D byte-identical" compares raw `SELECT *` snapshots);
  Catalog handler tests (AC-11, AC-12, AC-13); schema shape (AC-8); `CategoryTreeLockTest` and
  `NoDdlTest` (AC-15). Inverted C-27 assertions in `ListingValueGuardsTest`, `StatusBlindGuardsTest`,
  `ListingCategoryUsageTest` (method deleted) — each with a comment naming this slice (AC-24).
- **Functional:** admin (AC-16…AC-19), `SellTest` (AC-20, incl. the five-query budget),
  `PublicListingTest` (AC-21), GET-no-write + Sentry silence (AC-22).
- **Bite proof:** AC-23 (a)…(o).
- **Performance:** no search path. `schemaOf()` gains two join predicates on rows already found by
  `category_id` / `attribute_id`; the public page and edit page query counts are asserted unchanged.

## Risks / open questions

1. **Q1–Q7 above.** Q2 (names reserved), Q4 (dormant kept across saves) and Q5 (an archived option is
   a stale value, shown publicly) are the product calls; the others have safe defaults.
2. **A reserved name can puzzle an admin** (*"code “brand” is taken"* with no visible brand on this
   branch). The archived row is listed on its defining category's page with Restore. If it bites in
   practice, the refusal can name the archived attribute — a message change, not a rule change.
3. **The `phase1-gate` facet query must speak "active" — this is the interaction the owner asked
   about.** A naive `SELECT option_id, count(*) FROM listing_attribute_value WHERE attribute_id = :a
   GROUP BY option_id` now counts (i) **dormant** values of listings whose category moved away from
   the attribute, (ii) **stale** values on archived options, and (iii) values of an **archived**
   attribute. The gate's query (and Phase 2's) must: scope listings to the browsed category's
   **subtree** and `status = 'published'` (which removes (i) — a moved listing is no longer below the
   attribute's defining category), offer only attributes from the **active** effective schema (iii),
   and join `catalog_attribute_option … AND archived_at IS NULL` for the buckets (ii). The `EXPLAIN`
   on `idx_listing_attribute_value_option` stays valid — the extra joins are PK lookups into a small
   table — but the gate must measure **that** query, not the naive one. T19 writes this into the
   gate's roadmap line. Note also: an archived-option *filter* is never offered, so a listing with a
   stale Fender is found by unfiltered search but by no Brand bucket — by design (Q5).
4. **Dormant values accumulate** (every archive and move leaves some, and hard delete of their
   attribute is refused while they exist). Bounded by catalog churn × listings; Phase 2's 30-day
   expiry and GDPR erasure are their natural end. `ponytail:` no sweep — add one only with a
   measured reason and an owner decision (it would be retroactive).
5. **Restore can make a published listing show a value its seller has not seen for months.** That is
   what restore means; the value was the seller's, kept on purpose. Recorded in ADR-0022.
6. **Tests that encoded C-27 must flip, not vanish.** Four files assert the move refusal today; each is
   inverted into "the move succeeds and the rows are untouched", with a comment — CLAUDE.md's *"when
   an AC and the implementation disagree, fix one of them on purpose and say which won"*.
7. **ADR work (T0):**
   - **ADR-0022** *"Schema drift: attributes and options are archived, never deleted from under a
     value; an archived name stays reserved, so restore takes no facts; a value not in force is
     dormant — kept by the listing, hidden everywhere, ignored by every rule; the move refusal (C-27)
     is retired"* — D1–D10, Q1–Q5 with rejected alternatives; consequences (Risks 2–5, the gate's
     facet query).
   - **ADR-0004 amendment:** the soft-delete clause is implemented (as *archive*); hard delete
     survives for unused rows; the value layer is untouched.
   - **ADR-0016 amendment:** the move accretion point loses C-27 (order C-4 → C-5 → C-21 → C-24);
     four new writes under `exclusively()`.
   - **ADR-0017 amendment:** C-18 counts **active** options (`countActiveForAttribute()`); archived
     labels still count for C-17; options gain a lifecycle (archive / restore) but still no `delete()`.
   - **ADR-0018 amendment:** C-21 counts archived codes (C-29); restore is **not** a fourth door; C-22's
     derivation is over **active** attributes for every consumer except the admin's archived list.
   - **ADR-0020 amendment:** decision 6's C-27 retired and `hasAttributeValues()` removed; C-25/C-26
     re-scoped to hard delete; *"what these keep true together"* weakened to "every stored value names
     a row that exists"; L-17.
   - **ADR-0021 amendment:** L-14 re-checks in-force values only; stale values still refused.
