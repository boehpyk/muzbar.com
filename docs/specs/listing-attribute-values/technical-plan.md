# Technical Plan: listing-attribute-values

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

## Decisions

**Bold** rows go into **ADR-0020** or a dated amendment (task T0).

### Open questions for the owner (the plan is written to the recommendation)

| # | Question | Recommendation |
|---|---|---|
| Q1 | **C-27, the move guard: coarse or precise?** Coarse = refuse a move when *any* listing in the subtree has *any* value. Precise = refuse only when a value would leave the effective schema (an attribute defined above the moved category and not on the new lineage). | **Coarse** (the roadmap's wording). Precise needs attribute ids defined on the old-minus-new lineage handed to the Listing adapter — more SQL for a rare admin act, and `listing-schema-drift` replaces the refusal anyway. Moves of categories without details stay free. |
| Q2 | **Re-filing a draft to another leaf:** what happens to values whose attribute is not in force on the new category? | **Dropped, in the aggregate (L-10), inside `holdSteady()`;** values for attributes shared through a common ancestor are kept. The form then lands back on the edit page with a notice (AC-21). Alternatives: refuse re-file while values exist (punishes a seller fixing a wrong category), keep them hidden (stranded rows, the thing C-25/C-27 exist to prevent). |
| Q3 | **Text values:** max length, one line? | **1–200 characters after trimming, one line** (`ListingTitle`'s control-character rule). `varchar(200)`. Serial numbers, finish names, short notes. |
| Q4 | **After creating a draft, where does the seller land?** | **The new draft's edit page** (303), flash *"Draft saved. Add the details below."* — the server-rendered stand-in for Phase 2's morphing form: pick the category, then fill in what it asks. Changes listing-draft AC-25 on purpose. |
| Q5 | **Booleans on the form:** checkbox or tri-state? | **Tri-state `<select>` (— / Yes / No).** A checkbox cannot say "not answered", and on a draft "not answered" is legal (L-11). |
| Q6 | **Number input format** | **Whole numbers only, ASCII digits, optional leading `-`, at most 18 digits, no separators or `+`; bounds inclusive.** Same spirit as `Price` (D7 of listing-draft): one unambiguous shape, said in the error. |
| Q7 | **A stored value outside *narrowed* bounds:** refuse on the next save of the draft, or only when that value itself changes? | **On every save** (L-9 reads current rules on every revise) — D11.2's "at every later edit". The seller sees the field and the message; nothing changes behind their back. The L-2 "unchanged checks nothing" shape was for legacy data an admin cannot fix; here the seller *can* fix it in the same form. |
| Q8 | Mark required attributes on the draft form? | **Yes, as a hint ("required to publish"), not enforced** (L-11). |
| Q9 | Long option lists (thousands of brands) | **Plain `<select>` now**; typeahead is the Phase 2 wizard. The schema fact loads every option id of the category's choice attributes per save — `ponytail:` note with the ceiling (D5). |

### Design decisions

| # | Decision | Recommendation | Alternatives considered |
|---|---|---|---|
| **D1** | **Values are parts of the `Listing` aggregate, not their own aggregate** | ADR-0017's test, applied honestly: a part leaves when it has **its own references** (something outside points at it) or an **unbounded count**. A value has neither: nothing references a value (Phase 2 search *reads* rows, it holds no reference), and the count per listing is bounded by the category's schema (dozens at most). Every value rule is the listing's: one per attribute (L-8), fits the schema of *the listing's* category (L-9), dropped on *the listing's* re-file (L-10). The listing is the consistency boundary, so it is the aggregate. | **`ListingAttributeValue` as its own aggregate (the `AttributeOption` shape):** a root per value, a repository, and L-8/L-10 become cross-instance invariants needing the lock *and* an index, for no reference that needs it. Options left `Attribute` because a Brand list is unbounded and listings point at options; neither holds here. |
| **D2** | **The aggregate holds an immutable `AttributeValues` value; the repository persists it to `listing_attribute_value`; a `postLoad` listener hydrates it** | `Listing` has `private AttributeValues $attributeValues` (not a Doctrine-mapped field). `DoctrineListingRepository::save()`: `persist` + `flush` the listing, then, on the same connection inside the caller's transaction, `DELETE FROM listing_attribute_value WHERE listing_id = :id` and one multi-row `INSERT` of the current values. `ListingAttributeValuesLoader` (`#[AsEntityListener(event: postLoad, entity: Listing::class)]`) reads the rows and sets the property by reflection — Doctrine's own hydration mechanism, used once more in the same layer. postLoad fires once per hydration, so a second `get()` in one EM never clobbers unsaved changes, and Foundry's `refresh()` gets values too. Delete-and-reinsert is legitimate per ADR-0012: the rows are the aggregate's own state, rewritten whole, as Doctrine rewrites a `jsonb` column. `ponytail:` rewrite-all on every save; diff against a load snapshot if a listing ever has hundreds of values. | **Doctrine `<one-to-many>` to an `AttributeValue` entity:** the Domain would hold a `Doctrine\Common\Collections\Collection` — a vendor import Deptrac's catch-all rejects — or replace a `PersistentCollection` with a plain array and rely on dereference + `orphanRemoval`, where the UnitOfWork runs **inserts before deletions**, so re-saving a value for the same attribute collides on the `(listing_id, attribute_id)` key. **A `jsonb` column on `listing_listing`** (the `User`/`OAuthIdentity` shape, ADR-0014): one write path, but ADR-0004 §2 wants typed columns with generic `(attribute_id, option_id)` indexes for facet counts; a `jsonb` *plus* the table is the two write paths ADR-0004 warns about. **Hydrate in `get()`:** misses every other load path (Foundry, fresh-EM tests) and must special-case the identity map. |
| **D3** | **Listing asks Catalog for the schema through its existing `Categories` port: `schemaOf(CategoryId): AttributeSchema`** | The `EffectiveSchema` of ADR-0018 d.8.4, in Listing's words. `Categories` is already "Listing's view of the catalog", its adapter `CategoriesForListing` already lives with the catalog's data and already holds the lock, so the new question goes there: one port, one adapter, no new seam. `AttributeSchema` is a **fact** type (the `Lineage` / `CategoryStatus` sentence): true only inside `holdSteady()`, never stored. It carries ids, kinds, option ids and bounds — **no labels** (the Domain never shows anything). | **A new port `AttributeSchemas`:** a second seam with the same lock contract for a question about the same catalog. **A Catalog-side `EffectiveSchema` type imported by Listing:** a Conformist import `ListingIsolationTest` forbids (ADR-0019 d.2). **Labels in the fact so the form uses it:** presentation inside a Domain type no Domain code reads. |
| **D4** | **The aggregate reads the seller's input against the schema (`AttributeSchema::read()`), inside `revise()`** | Whether `"42"` is valid depends on the attribute it answers — the `Price::fromDecimal(string, Currency)` situation: parsing needs a fact, so it happens in the Domain, next to the fact. The command carries `array<string, string> $attributes` (attribute id → raw text); the handler builds `AttributeInput` (well-formed ids, raw strings); `revise(…, AttributeSchema $schema, AttributeInput $input)` does **L-6 → L-2 → L-10 (filter on re-file) → L-9 (read, refuse) → assign**, every check before any assignment. One `revise()` keeps the category change and the values in one decision, which L-10 needs: the values posted with a re-file were rendered for the *old* category. | **Separate `describe()` after `revise()`:** two calls in one handler, and a re-file submit would post values for the old schema into a check against the new one (every dropped attribute refused). **Typed VOs built by the form/handler:** the handler would need the schema to know which type to build, and the decision "refuse vs drop" would leave the aggregate. |
| **D5** | **The schema fact carries every option id of every choice attribute in force** | `CategoriesForListing::schemaOf()`: one bounded recursive query over the lineage (the `effectiveFor()` CTE) with `array_agg(o.id ORDER BY o.id)` per attribute. Simple, one query, true under the lock. `ponytail:` a 5 000-brand list is 5 000 ids per save; the upgrade is to load only the option ids the input mentions. | **Load only mentioned options:** a second argument on the port that leaks the input shape into a "what is the schema" question, for a cost nobody has measured. |
| **D6** | **Three Catalog guards, all handler checks under the exclusive lock (ADR-0019 d.9's reasoning)** | **C-25** in `DeleteAttributeHandler` (after `get()`, before `remove()`), **C-26** in `RemoveAttributeOptionHandler` (after C-18's `allowOptionRemoval()` — the aggregate's own rule first), **C-27** in `EditCategoryHandler` inside `if ($parentChanged)`, after C-24: `hasAttributeValues([$category->id(), ...descendantsOf()])`. Each is a fact about someone else's data that the Catalog aggregate would combine with none of its own state. New Catalog port **`AttributeUsage`** (`isInUse(AttributeId)`, `isOptionInUse(AttributeId, AttributeOptionId)` — the attribute id lets the query use the `(attribute_id, option_id)` index); `CategoryUsage` gains **`hasAttributeValues(list<CategoryId>)`**. Exceptions: **`AttributeInUse`** (`cannotDelete()`, `cannotRemoveOption()`) and `CategoryHasListings::cannotMove()`. | **Soft delete now** (ADR-0004's original rule): `archived_at`, hiding from forms, and C-21's "does an archived code block?" — the whole of `listing-schema-drift`. Refusal is the fail-closed placeholder the roadmap asked for. **Facts into `Attribute::allowOptionRemoval()`:** C-18 combines a count with the attribute's own type; C-26 is a yes/no about listings. **Methods on `CategoryUsage` for attributes:** the name would lie. |
| **D7** | **What is *not* guarded, on purpose** | Recode, relabel (attribute or option), `required`, number rules, and defining new attributes strand nothing: values refer to ids, the type never changes (C-14), and bounds/required bite at the next write and at publish (D11.2). AC-15 proves they stay free. | **Refusing a bound change that would invalidate stored values:** retroactive by another name, and it would let one seller's draft veto an admin's schema. |
| **D8** | **Listing's own words for Catalog's ids and kinds** | Local copies `AttributeId`, `AttributeOptionId` (ADR-0019 d.2; the 10th and 11th copies of the UUID shape) and `AttributeKind` (`Choice | Number | Boolean | Text`, backed by the same strings so the adapter maps by value; an unknown string throws `\LogicException` — fails closed). | Import Catalog's `AttributeType` / ids: forbidden by the isolation scan. |
| **D9** | **Storage: one row per value, one typed column per kind, a hand-written CHECK** | `listing_attribute_value (listing_id, attribute_id, option_id, value_int, value_bool, value_text)`, PK `(listing_id, attribute_id)` (L-8 backstop, load-by-listing), `idx_listing_attribute_value_option (attribute_id, option_id)` (C-25, C-26 and the Phase 1 gate's facet count), `CHECK (num_nonnulls(option_id, value_int, value_bool, value_text) = 1)`. `value_bool` is added to ADR-0004's sketch (a boolean is not a 0/1 integer). No FK (ADR-0009 d.4). The table is not mapped by Doctrine at all (D2) and is excluded from the schema diff by `schema_filter` (Risk 2), so the hand-written CHECK cannot re-diff; AC-6 asserts it from `pg_constraint` instead. | **A `kind` column:** derivable from which column is set. **Number-range / boolean / text indexes now:** no query yet; one migration each when Phase 2 writes one. **`value_text` as `text`:** Q3 bounds it. |
| **D10** | **No domain events** | A draft's details are private; nothing consumes them. `listing-publish` designs `ListingPublished`'s payload (ADR-0019 consequence). | `ListingDescribed`, read by nothing. |
| **D11** | **The form is built from Catalog's admin read model, not from the Domain fact** | `SellController::edit()` reads `DbalAttributeSchema::effectiveFor()` (labels, units, `required`, order) and a new `optionsFor(list<string>)` (all options of the listed choice attributes in **one** query). The Domain decides on `schemaOf()` read again under the lock at POST. A schema change between render and submit only produces a refusal (failure contract) — fail closed. This is the **fourth** cross-context crossing (a UI read, like `DbalCategoryOutline` in listing-draft D13); ADR-0019's consequence list gains it. | **Render from `schemaOf()`:** needs labels in the Domain fact (D3). **A Listing-side copy of the CTE:** `catalog_*` SQL under `Infrastructure/Listing`, which the data-ownership scan forbids. |
| D12 | **Form field types never answer first** | Number and text are `TextType` (an `IntegerType` would parse through the locale and answer with its own message — listing-draft's "no `Length` constraint" lesson). Choice and boolean are `ChoiceType` with `invalid_message` set to the failure contract's text, so a vanished option reads the same as a tampered one. Field names are the attribute ids under `attributes[...]`. | `IntegerType`, `CheckboxType` (Q5). |

## Domain layer (pure PHP)

### `Domain/Listing/ValueObject/` (new)

- **`AttributeId`, `AttributeOptionId`** — copies of the id shape; docblock *"Listing's name for an id
  Catalog owns; never import theirs"* + ADR-0009 trait note (eleven copies now).
- **`AttributeKind`** — backed enum `Choice = 'choice' | Number = 'number' | Boolean = 'boolean' |
  Text = 'text'`. Docblock: same backing strings as Catalog's `AttributeType`, mapped by value in the
  adapter, never imported.
- **`AttributeValue`** — `final readonly`; `attributeId()`, `kind()`; named constructors
  `option(AttributeId, AttributeOptionId)`, `number(AttributeId, int)`, `boolean(AttributeId, bool)`,
  `text(AttributeId, string)` (the text constructor re-applies the text rule, so a hydrated row is
  never trusted blindly); readers `optionId(): ?AttributeOptionId`, `number(): ?int`,
  `boolean(): ?bool`, `text(): ?string` — non-null exactly for its kind; `equals()`.
- **`AttributeValues`** — `final readonly`; `none()`, `of(AttributeValue ...)` (two for one attribute →
  `\InvalidArgumentException`, L-8), `for(AttributeId): ?AttributeValue`, `toList()` (sorted by
  attribute id, so `equals()` and row order are deterministic), `equals()`, `isEmpty()`.
- **`AttributeInput`** — `final readonly`; `fromArray(array<string,string>)` → throws
  `InvalidAttributeValue::notInForce($rawKey)` for a malformed key; `onlyFor(AttributeSchema): self`
  (L-10's filter); `entries(): list<array{AttributeId, string}>`.
- **`AttributeRule`** — `final readonly`; `choice(AttributeId, list<AttributeOptionId>)`,
  `number(AttributeId, ?int $min, ?int $max)`, `boolean(AttributeId)`, `text(AttributeId)`;
  **`read(string $raw): ?AttributeValue`** — trims; empty → `null`; otherwise L-9 per kind or
  `InvalidAttributeValue`. `TEXT_MAX_LENGTH = 200`, `NUMBER_MAX_DIGITS = 18`. Docblock: the regexes
  and why (ASCII only, no separators — the `Price` reasoning).
- **`AttributeSchema`** — fact type; `of(AttributeRule ...)`, `none()`, `has(AttributeId)`,
  **`read(AttributeInput): AttributeValues`** (input for an id not in the schema →
  `InvalidAttributeValue::notInForce()`). Docblock: *only true inside `Categories::holdSteady()`*.

### `Domain/Listing/Exception/`

- **`InvalidAttributeValue`** (`\DomainException`): `notAnOption`, `notAWholeNumber`,
  `outOfRange(?int $min, ?int $max)`, `notYesOrNo`, `badText`, `notInForce`; `attributeKey(): string`
  (the raw id string, so the controller can map a refusal to its field even for a malformed key) and
  `reason()` (enum or constant, for the message choice incl. the bounds).

### Aggregate (`Listing`)

```
Listing
  private AttributeValues $attributeValues        not Doctrine-mapped; set by draft() and by the
                                                  postLoad loader (D2)
  static draft(…unchanged…)                       attributeValues = AttributeValues::none()
  revise(SellerId $by, CategoryId, CategoryStatus, AttributeSchema $schema,
         ListingTitle, ListingDescription, Price, AttributeInput $input): void
      L-6: !isSoldBy($by) → NotTheSeller
      categoryChanged → L-2 on the status; $input = $input->onlyFor($schema)   (L-10)
      $values = $schema->read($input)                                          (L-9, L-8)
      then assign all five
  attributeValues(): AttributeValues
```

`$schema` is the schema of the **target** category (the new one on a re-file). Docblock gains the L-9
contract: *the aggregate decides, the shared lock makes the schema true, Catalog's C-25/C-26/C-27 keep
stored values from being stranded*; and the explicit non-rules (required, retroactive bounds).

### Ports

```
Domain/Listing/Port/Categories                     (gains)
  schemaOf(CategoryId): AttributeSchema           every attribute in force on the category (its
                                                  lineage), with option ids and bounds; unknown →
                                                  none(). Only true inside holdSteady().

Domain/Catalog/Port/AttributeUsage                 (new) Catalog's view of what listings use
  isInUse(AttributeId): bool                      any listing, any status, holds a value for it
  isOptionInUse(AttributeId, AttributeOptionId): bool
                                                  Only true inside CategoryTreeLock::exclusively().

Domain/Catalog/Port/CategoryUsage                  (gains)
  hasAttributeValues(list<CategoryId>): bool      any listing filed under any of them holds any value
```

### Catalog exceptions

- **`AttributeInUse`** (`\DomainException`): `cannotDelete(AttributeId)`,
  `cannotRemoveOption(AttributeOptionId)`; exposes the id.
- **`CategoryHasListings::cannotMove(CategoryId)`** — third named constructor; docblock updated
  (C-23, C-24, C-27).

### Events

None (D10).

## Application layer

- **`ReviseListing`** gains `public array $attributes` (`array<string, string>`, attribute id → raw
  text; `[]` when the form has no Details section). **`DraftListing` is unchanged.**
- **`ReviseListingHandler`** inside `holdSteady()`: `get()` → category id → `statusOf()` →
  **`schemaOf()`** (unconditionally, the "no branch" precedent) → `AttributeInput::fromArray()` →
  `revise()` → `save()`. `@throws` gains `InvalidAttributeValue`.
- **`DeleteAttributeHandler`** gains `AttributeUsage`; after `get()`: `isInUse()` →
  `AttributeInUse::cannotDelete()`. The "Listing slice adds its guard here" sentence becomes the
  record of C-25 and names ADR-0020 + `listing-schema-drift`.
- **`RemoveAttributeOptionHandler`** gains `AttributeUsage`; after `allowOptionRemoval()`:
  `isOptionInUse()` → `AttributeInUse::cannotRemoveOption()`. Comment: C-18 → C-26, aggregate first.
- **`EditCategoryHandler`**: inside `if ($parentChanged)`, after C-24:
  `hasAttributeValues([$category->id(), ...$this->categories->descendantsOf($category->id())])` →
  `CategoryHasListings::cannotMove($category->id())`. Reuse the `descendantsOf()` result already read
  for C-21 (assign it to a local once). The SECOND ACCRETION POINT comment records C-27 and points at
  `listing-schema-drift`.
- Idempotency: a repeated revise rewrites the same rows (AC-7).

## Infrastructure layer

### Persistence (`Infrastructure/Listing/Persistence/Doctrine/`)

- **`DoctrineListingRepository::save()`**: after `flush()`, `writeValues(Listing)`: one `DELETE …
  WHERE listing_id = :id`, then one multi-row `INSERT` when values exist (typed parameters: uuid,
  `bigint`, `boolean`, string). Comment: *the only writer of `listing_attribute_value`; the
  EntityManager alone does not persist values* (CLAUDE.md footgun line).
- **`ListingAttributeValuesLoader`** (`#[AsEntityListener(event: Events::postLoad, method: 'postLoad',
  entity: Listing::class)]`): one `SELECT … WHERE listing_id = :id`, maps each row by its non-null
  column to `AttributeValue`, sets `attributeValues` via `ReflectionProperty`. A row with zero or two
  columns set (impossible past the CHECK) → `\LogicException`.
- **`DbalSellerDrafts::valuesOf(string $listingId): array<string, string>`** — the edit form's prefill
  (option id, int as string, `yes`/`no`, text). Called only after `find()` passed the seller filter.
- **Migration**: `CREATE TABLE listing_attribute_value` + PK + index + CHECK (hand-added after
  generation — generation will produce nothing for an unmapped table, so the whole migration is
  hand-written and then proven by an empty `make migration.make`). Doctrine must ignore the unmapped
  table: widen `doctrine.dbal.schema_filter` (today `'~^(?!prelaunch_signup$)~'`) to exclude
  `listing_attribute_value` too (Risk 2).

### Cross-context adapters

- **`CategoriesForListing::schemaOf()`** (in `Infrastructure/Catalog/Integration/`): the
  `effectiveFor()` CTE shape (`up` bounded by `Category::MAX_DEPTH`) joined to `catalog_attribute`,
  `LEFT JOIN catalog_attribute_option`, `array_agg(o.id ORDER BY o.id) FILTER (WHERE o.id IS NOT
  NULL)`, `number_rules->>'min'/'max'` cast `::bigint`. Maps to `AttributeRule`s. Docblock: the
  `ponytail:` option-count ceiling (D5).
- **`Infrastructure/Listing/Integration/ListingAttributeUsage`** implements `AttributeUsage`:
  `SELECT EXISTS (SELECT 1 FROM listing_attribute_value WHERE attribute_id = :a)` and `… AND option_id
  = :o`, both on `idx_listing_attribute_value_option`.
- **`ListingCategoryUsage::hasAttributeValues()`**: `SELECT EXISTS (SELECT 1 FROM
  listing_attribute_value v JOIN listing_listing l ON l.id = v.listing_id WHERE l.category_id =
  ANY(:ids))` (`idx_listing_listing_category` + the PK). Empty list → `false` without a query.

### Catalog read model

- **`DbalAttributeSchema::optionsFor(list<string> $attributeIds): array<string, list<OptionRow>>`** —
  one query, `ORDER BY attribute_id, id`. Empty list → `[]` without a query.

### HTTP / UI

- **`ListingFormType`** gains an optional `attributes` sub-form, built when the `schema` option
  (effective rows + options + prefill) is non-empty: one child per attribute, named by its id, types per
  D12, label = attribute label (+ ` (unit)`), help = "required to publish" when required, `required =>
  false` on every field (no HTML5 required on drafts). `ListingFormData` gains
  `array<string, ?string> $attributes`.
- **`SellController`**:
  - `new`: unchanged form; on success **303 → `app_sell_draft_edit`** of the new id, flash *"Draft
    saved. Add the details below."* (Q4).
  - `edit`: builds the schema rows from `effectiveFor($draft.categoryId)` + `optionsFor()` + prefill
    from `valuesOf()`; on success: category unchanged → 303 `/sell/drafts` *"Draft saved."*; category
    changed → 303 back to the edit page, *"Draft saved. The details now follow “<name>”: check them
    below."* Catches `InvalidAttributeValue` → `addRefusal()` on `attributes[<key>]` with the contract's
    message (a `match` on the reason). `NotTheSeller|ListingNotFound` → 404 unchanged.
- **Templates**: `listing/_form.html.twig` renders the Details `<fieldset>` when the sub-form exists;
  `edit.html.twig` unchanged otherwise.
- **`AttributeAdminController`**: `delete()` catches `AttributeInUse` → error flash; the option-remove
  branch of its shared option-action helper catches it → error flash (AC-24).
  **`CategoryAdminController::edit()`** maps `CategoryHasListings` from `cannotMove()` to the parent
  field with its own message (distinguish by a `reason`/named-constructor flag on the exception, or
  by a second `catch` order — the implementer picks the smaller; the message is in the contract).

### DI wiring (`services.yaml`)

```
App\Domain\Catalog\Port\AttributeUsage: '@App\Infrastructure\Listing\Integration\ListingAttributeUsage'
```

Lands **in the same commit** as the two attribute handlers that inject it, with an
`ObservedAttributeUsage` fixture for `CategoryTreeLockTest` (Symfony compiles lazily).

### Async / schedule / external

None.

## Interface boundary & input contract

| Surface | Accepts | Refuses |
|---|---|---|
| `POST /sell/drafts/{id}/edit` | listing-draft's fields + `listing_form[attributes][<attributeId>]` for each attribute rendered, all strings | unknown attribute keys (extra field) → 422; per-field refusals → 422; non-owner/unknown → 404 |
| `POST /sell` | unchanged (no `attributes`) | `attributes` posted → extra field, 422 |
| `ReviseListing::$attributes` | `array<string,string>`; keys are attribute ids as text | malformed key → `InvalidAttributeValue` (not in force) |
| `Categories::schemaOf()` | Listing `CategoryId` | — (unknown → empty) |
| `POST /admin/attributes/{id}/delete` | (unchanged) | in use → error flash |
| `POST /admin/attributes/{id}/options/{optionId}/delete` | (unchanged) | in use → error flash |
| `POST /admin/categories/{id}/edit` | (unchanged) | subtree has values and parent changes → 422 on `parent` |

## Data & migrations

```sql
CREATE TABLE listing_attribute_value (
    listing_id   UUID         NOT NULL,   -- the owning Listing (a part, not a root); no FK
    attribute_id UUID         NOT NULL,   -- Catalog attribute; no FK (ADR-0009 d.4); C-25 keeps it alive
    option_id    UUID         NULL,       -- choice; C-26 keeps it alive
    value_int    BIGINT       NULL,       -- number (bounds are PHP int64: bigint, the ::int lesson)
    value_bool   BOOLEAN      NULL,       -- boolean (added to ADR-0004's sketch)
    value_text   VARCHAR(200) NULL,       -- text (Q3)
    PRIMARY KEY (listing_id, attribute_id),                       -- L-8; load by listing
    CONSTRAINT chk_listing_attribute_value_one_value
        CHECK (num_nonnulls(option_id, value_int, value_bool, value_text) = 1)
);
CREATE INDEX idx_listing_attribute_value_option
    ON listing_attribute_value (attribute_id, option_id);         -- C-25, C-26, phase1-gate facet count
```

- **No backfill, no pre-deploy query:** no value exists anywhere, so every category, attribute and
  option is trivially consistent with C-25/C-26/C-27 at deploy, and the guards ship with the first
  door that can create a value. **Prod is consistent after this deploy on its own.**
- **Deploy window** (as listing-draft): between `up -d` and `migrate`, the guards and the edit page
  query a missing table → 500s for seconds. Accepted; `docs/infrastructure.md` note extended.
- **Search impact:** the generic `(attribute_id, option_id)` index is the one `phase1-gate` will
  `EXPLAIN` a `GROUP BY option_id` facet count against. Nothing here is on a search path; no 200 ms
  budget applies yet.

## Test plan

- **Domain unit (no kernel):** `AttributeRuleTest` (AC-1, every listed input), `AttributeSchemaTest`,
  `AttributeValuesTest`, `AttributeInputTest` (AC-2), `ListingTest` additions (AC-3, AC-4 incl. the
  updated method allow-list and check-before-assign on every values refusal), id VO tests.
- **Isolation:** `ListingIsolationTest` data-ownership scan extended with `listing_attribute_value` /
  `catalog_attribute` (AC-5).
- **Integration (DAMA):**
  - `ListingAttributeValuesSchemaTest` (AC-6, incl. the two 23514 inserts).
  - `ListingHandlersTest` additions (AC-7…AC-10) with snapshots and a **fresh EntityManager** before
    each read-back (the identity-map footgun).
  - `CategoriesForListingTest::schemaOf` (AC-11), `ListingAttributeUsageTest`,
    `ListingCategoryUsageTest` additions.
  - `AttributeHandlersTest` / `CategoryHandlersTest` additions (AC-12…AC-15).
  - `CategoryTreeLockTest`: `ObservedCategories` records `schemaOf()`; `ObservedAttributeUsage`;
    `ObservedCategoryUsage` records `hasAttributeValues()` (AC-16).
  - `ListingValuesPersistenceTest` (AC-17): fresh-EM load, Foundry refresh, factory row count.
  - `NoDdlTest` unchanged and green.
- **`ListingFactory::withValues(array<string attributeId, string raw>)`**: after creation, revises
  through the aggregate with a schema built from the given `AttributeFactory` attributes, then
  `ListingRepository::save()` (not the EM — D2). Docblock: bypasses L-2 as before; build values only
  for attributes actually in force on the listing's category, or the factory refuses (good).
- **Functional:** `SellTest` additions (AC-18…AC-23, AC-25…AC-27), `SellTest`'s AC-25 assertion
  updated **on purpose** (Q4), `AttributeAdminTest` / `CategoryAdminTest` additions (AC-24),
  Sentry silence.
- **Bite proof:** AC-28 (a)…(n).
- **Performance:** none required. AC-25's query count guards against an N+1 over choice attributes.

## Risks / open questions

1. **Q1–Q9 above.** Q1, Q2 and Q4 are product calls; the rest have safe defaults.
2. **The unmapped table and `make migration.make`.** Doctrine's schema tool will want to **drop**
   `listing_attribute_value` because no mapping declares it. The precedent exists:
   `doctrine.yaml` has `schema_filter: '~^(?!prelaunch_signup$)~'` for the raw-DBAL launch table;
   widen it to `'~^(?!(prelaunch_signup|listing_attribute_value)$)~'`, with the comment extended. The
   cost: the table's shape is then guarded by AC-6's `information_schema` test, not by the diff. T8
   proves an empty `make migration.make`.
3. **An unmapped table is invisible to every Doctrine safety net** — no diff, no `doctrine:schema:validate`.
   Its shape is guarded only by AC-6. Any future column change is a hand-written migration plus an
   AC-6 update; ADR-0020 says so.
4. **Reflection in the `postLoad` loader** writes a private Domain property from Infrastructure. That
   is what Doctrine does for every mapped field; the loader is the only such place, named in ADR-0020
   and guarded by AC-17 / mutation (l). An uninitialised `$attributeValues` (a load path that skipped
   the listener) throws `Error` on first read — loud, not a silent empty set.
5. **The lock's watch list grows.** Every new Listing write that changes values goes through
   `holdSteady()` and reads `schemaOf()` there; every new Catalog write that deletes an attribute or
   option, or changes a parent, reads the matching usage under `exclusively()`. `CategoryTreeLockTest`
   covers the doors that exist.
6. **Eleven copies of the UUID id shape.** The ADR-0009 trait refactor should be scheduled before
   `listing-publish` (already a roadmap candidate).
7. **ADR work (T0):**
   - **ADR-0020** *"Attribute values are parts of the Listing aggregate, persisted to a typed child
     table by the repository; Listing validates them against Catalog's effective schema read through
     its own port under the shared lock; Catalog refuses the writes that would strand them"* — D1–D9,
     D11, with the rejected alternatives, and the consequences: the repository is the only writer of
     values (EM flush alone persists none), `required`/bounds are never retroactive, the coarse move
     refusal is a placeholder for `listing-schema-drift`.
   - **Dated ADR-0004 amendment:** amendment 1's expiry is discharged **by refusal (C-25, C-26)**,
     not soft delete — soft delete moves to `listing-schema-drift`; hard delete of unused
     attributes/options continues. The value layer gains `value_bool`; only `(attribute_id,
     option_id)` is indexed now.
   - **Dated ADR-0016 amendment:** attribute delete and option remove gain listing guards (C-25,
     C-26); the move accretion point gains C-27 after C-24; the shared mode now also protects the
     schema fact.
   - **Dated ADR-0018 amendment:** decision 8 points 1 (move guard: C-27, coarse), 3 (attribute
     delete: refusal for now) and 4 (`Categories::schemaOf()` / `AttributeSchema`) answered; point 2
     (required) handed to `listing-publish` unchanged.
   - **Dated ADR-0019 amendment:** `Categories` gains `schemaOf()`; the fourth crossing (the edit
     form reads `DbalAttributeSchema`); `AttributeUsage` is Catalog's second port into Listing.
   - **Dated ADR-0007 amendment:** a collection of value objects inside an aggregate that must live in
     a typed table is persisted by the repository adapter and hydrated by a `postLoad` listener —
     never a Doctrine collection in the Domain (pointer to ADR-0020).
