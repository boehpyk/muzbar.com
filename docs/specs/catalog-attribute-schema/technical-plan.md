# Technical Plan: catalog-attribute-schema

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

## Decisions

Reworked 2026-11-15 after the owner's answers. The owner approved reversing the first draft's D2/D3:
options are now their own aggregate. **Bold** rows go into **ADR-0017** or a dated amendment (task T0).

### Recorded owner answers (formerly Q1–Q9)

| # | Answer (owner, 2026-11-15) |
|---|---|
| Q1 | **Integers only.** Number = whole number + optional unit + optional bounds. Decimals would be an additive rule later. |
| Q2 | **Code unique per defining category** (C-11). Slice 3 widens the scope or defines overrides. |
| Q3 | **Attributes are ordered by label; options in insertion order; no manual reordering.** |
| Q4 | **Hard delete now**, recorded as a dated ADR-0004 amendment. Soft delete arrives with the first listing value. |
| Q5 | **No cap on options.** Brand lists can run to thousands. Answering this reversed D2/D3. |
| Q6 | **No multi-select.** |
| Q7 | **Dependent options (Model under Brand) are out of scope, but expected.** The design must not block them. A label such as "Standard" will exist under several brands, so label uniqueness will later be per *(attribute, parent option)* (D14). |
| Q8 | **Keep `Text`; defer `is_facet` / `is_searchable`** to Phase 2. |
| Q9 | **No id-trait refactor first.** It stays its own refactor PR. |

### Design decisions

| # | Decision | Recommendation | Alternatives considered |
|---|---|---|---|
| **D1** | **`Attribute` is its own aggregate, referencing its category by id** | One aggregate per attribute. It holds `id`, `categoryId` (a plain `CategoryId` column: never `<many-to-one>`, no FK, ADR-0009 d.4), `code`, `label`, `type`, `required` and `?numberRules`. It holds **no options**. | **Attributes inside `Category`**: ADR-0004's value layer references `attribute_id` from every listing value, and Evans forbids outside references into another aggregate (the ADR-0016 D1 argument). **A `CategorySchema` aggregate per category**: same objection, and slice 3 needs attributes addressable one by one along a lineage. |
| **D2** | **`AttributeOption` is its own aggregate** (reverses the first draft) | One aggregate per option: `id`, `attributeId` (a plain `AttributeId` column, no association, no FK), `label`, `labelKey`. Options are **created by their attribute** (`Attribute::newOption(id, label): AttributeOption`), which uses an aggregate as the factory for another aggregate. That is how C-15 ("only a choice has options") stays the attribute's rule while the option is a separate root. The set-rules (C-17 unique label, C-18 never the last) become **cross-instance invariants**, handled with the ADR-0016 pattern (D5). | **Options as a `list<AttributeOption>` inside `Attribute`, in a `jsonb` column** (the first draft, and the `User`/`OAuthIdentity` shape). **Rejected, and ADR-0017 records why.** (1) **The set is unbounded.** A brand list has thousands of entries, and an aggregate is loaded, checked and rewritten whole on every change, so relabelling one brand would rewrite a multi-hundred-KB row. That is a boundary drawn around storage, not consistency. (2) **Dependent options.** A Model option belongs under a Brand option of *another attribute*, so the reference crosses attributes. An option inside Brand's jsonb cannot be the target of such a reference without breaking Evans' rule. (3) **Per-parent uniqueness.** "Standard" must be legal under Fender and under Squier. Inside one attribute's document that needs a nested structure. As rows, it is a wider unique index (D14). (4) **Listings reference options directly** (`listing_attribute_value.option_id`, ADR-0004). A root is something you may hold a reference to. **An option `code` as identity**: a typo would be permanent, and nothing needs one yet. |
| **D3** | **Persistence: two tables; case-insensitive uniqueness through a stored `label_key`; order by UUIDv7 id** | `catalog_attribute_option (id, attribute_id, label, label_key)`. This is ADR-0004's original `attribute_option` sketch, which now stands unamended. **`label_key`** = `AttributeLabel::key()` (simple lower-case mapping of the trimmed label). The aggregate assigns it in the same statement as the label, so the two cannot disagree. **Unique index `(attribute_id, label_key)`** is the plain two-column index DBAL models. **Why not `(attribute_id, lower(label))`**: DBAL 4.5 cannot declare an expression index in a mapping. Its PostgreSQL introspection (`selectIndexColumns`) also joins `pg_attribute` on `indkey`, where an expression column has `attnum = 0`. So the index would be read back as a different index and re-diffed by every `make migration.make`: the partial-index footgun, but worse, because no normalised spelling can fix it. `schema_filter` cannot help either: it filters assets by name per table, and excluding a hand-written index this way is undocumented and fragile. A generated column (`GENERATED ALWAYS AS (lower(label)) STORED`) has the same problem. `AttributeLabel::key()` is the only case-folder ever used, in the pre-check and in the stored key alike, so there is no PHP-vs-Postgres collation drift. **Order = `ORDER BY id`.** Ids are UUIDv7, and Postgres compares `uuid` bytewise. Byte order of a v7 is timestamp first, and Symfony's `UuidV7` is monotonic within one process. Every option insert is serialised by the tree lock, so insertion order equals id order, and the `position` column disappears. `ponytail:` the ceiling is a backwards wall-clock step between two separate adds. The upgrade is an additive `position` column backfilled from id order, which manual reordering (deferred) would need anyway. **`number_rules jsonb`** on the attribute row (one VO, one column, one DBAL type, ADR-0007; the `OAuthIdentitySetType` "bad row is fatal" precedent). **`type`** uses Doctrine's native `enum-type`. | `(attribute_id, lower(label))` expression index (re-diffs forever, see left). A `citext` column (an extension, and DBAL types it as text, so the diff noise remains). A `position int` column with `max + 1` under the lock (one more column, one more read, and a gap/renumber question on remove, all for an order the id already gives). A `catalog_attribute_option` `<one-to-many>` from `Attribute` (an association, which ADR-0009 forbids between roots). |
| **D4** | **Where definitions live relative to the tree (the inheritance question)** | **An attribute is stored once, on the category that defines it.** In this slice a category's schema is exactly its own attributes. Any category, leaf or not, may define attributes. This lets slice 3 make inheritance **a read-time derivation over the lineage** (`⋃ attributes of lineageOf(category)`) under the same lock, so **a move stays one row** (ADR-0016 D4, extended): no attribute or option row is touched by a move (AC-25). | **Copying inherited attributes into descendants** (a move or a parent edit rewrites N aggregates). **Leaf-only attributes** (forecloses inheritance). **Deciding overrides now** (slice 3's job). Per-category C-11 deliberately permits "same code lower in the lineage", which is the natural shape of an override. |
| **D5** | **Every attribute and option write runs under `CategoryTreeLock`, and the lock now guards cross-aggregate option invariants** | All six handlers wrap their reads and saves in `exclusively()`, reusing the port and adapter unchanged. **C-17** (unique label): handler pre-check `isLabelTaken()` under the lock gives the message, and the unique index is the guarantee (exactly C-3/I-6). **C-18** (≥ 1 option): `Attribute::allowOptionRemoval(int $optionCount)` decides, on a `countForAttribute()` read under the lock. Without the lock, two concurrent removes of the last two options each count 2 and both succeed: write skew, as in ADR-0016's cycle case. **C-12/C-13** (define on X vs delete X) are mirror checks under the same lock. The name `CategoryTreeLock` stays; its docblock and ADR-0016 record the widened scope. | **A second lock for attributes/options** (two locks require a lock-ordering rule, for one admin). **`SELECT … FOR UPDATE` on the attribute row** for C-18: it would work for this rule, but it makes a second concurrency mechanism to reason about. **No lock**: the index closes C-17's race but nothing closes C-18's. → **ADR-0016 amendment**. |
| **D6** | **Four types, fixed at definition** | `AttributeType` = `Choice`, `Number`, `Boolean`, `Text`. The type is immutable (C-14). Number is integer-only (Q1), mapping to ADR-0004's `value_int`. Four named constructors (`defineChoice`, `defineNumber`, …) put each type's required parts in its signature: `defineNumber` needs `NumberRules`, and `defineChoice` needs a non-empty list of initial labels. | A mutable type (schema drift by design). Single-table inheritance per type (mapping machinery for four small branches). A `range` type (no PRD need). |
| **D7** | **Delete semantics: hard delete; the attribute's handler sweeps its options** | ADR-0012: neither aggregate has a `delete()`. **`RemoveAttributeOptionHandler`**: the precondition (C-18) is the *attribute's* rule (`allowOptionRemoval`), then `options->remove(option)`. **`DeleteAttributeHandler`** (C-20), under the lock, makes two port calls: `attributes->remove($attribute)`, then `options->removeAllForAttribute($id)`, a set-based `DELETE`. That is legitimate per ADR-0012 d.2: the sweep bypasses no transition. C-18 ("never the last option") governs a *living* choice attribute, and once the attribute is gone the rule has no subject. **Order:** attribute first, then options. An option without its attribute is inert (nothing can reach it: every option route goes through its attribute), while a choice attribute without options violates C-18. Both run in one transaction, so the order matters only if someone later splits it, and the call site says so (ADR-0009 rule). **ADR-0004 says attributes are soft-deleted.** That clause protects historical *values*, and none exist yet. → **dated ADR-0004 amendment** (owner, Q4): soft delete and "option in use" guards land with the first `listing_attribute_value`, in these two handlers. | Soft delete now (an `archived_at` with no reader, and an archived code would block C-11). An FK `ON DELETE CASCADE` (ADR-0009 d.4: no FK between aggregates). |
| **D8** | **C-13: the first accreted category-delete guard** | `DeleteCategoryHandler`: C-7 (children) first, then `attributes->existsForCategory()` → `CategoryHasAttributes`. | Cascade-deleting a schema with its category. |
| **D9** | **Code: per-category unique, editable, derived** | Same format and derivation as a category slug. `CategorySlugDeriver` is reused unchanged. | Global uniqueness (Q2: no). An immutable code. |
| **D10** | **No domain events** | Slice-1 D6 applied. | Events with no listener. |
| **D11** | **Admin UI shape** | Unstyled server-rendered pages. The type is chosen before the form (`/new/{type}`), so no JavaScript is needed. Option add/relabel/remove are small POST forms answering **303 + flash**: a fresh request means a fresh EntityManager, so a duplicate-key that closed the EM in the previous request cannot affect the next page. The define/edit forms re-render with 422 from **DBAL read models only** (`DbalAttributeSchema`, `DbalCategoryOutline`), never the EM, because a 23505 inside `flush()` closes it (slice-1 Risk 2). Initial options come from a textarea. **The edit page lists all options unpaginated**: thousands of rows with one CSRF token per attribute is acceptable for an admin page. Pagination or filtering is additive. | A `CollectionType` (needs JS). A Live Component (Phase 2). |
| **D12** | **`AttributeId` / `AttributeOptionId` written out** | Copies 6 and 7 of the UUID VO shape (owner, Q9). | The trait refactor first. |
| **D13** | **Defining a choice attribute saves several aggregates in one transaction: options first, then the attribute** | `DefineAttributeHandler` runs inside one `exclusively()`. `Attribute::defineChoice()` decides C-17/C-18 on the initial labels it is given, in memory. The handler then mints one id per label and gets each option from `$attribute->newOption()`. It then calls **`options->save(...$options)` (one flush), then `attributes->save($attribute)`**. **Why this order (ADR-0009 rule):** the attribute row is the commit point of the whole definition. On create it is written **last**; on delete it is removed **first** (D7). If the transaction were ever split, the only possible leftover is an option with no attribute, which is inert and unreachable. The reverse order could leave a choice attribute with zero options, which violates C-18. Inside one transaction, a refusal at either save (C-17 or C-11 at the index) rolls back both (AC-19). The call site names D7's mirror-image order. | Attribute first (the leftover would violate an invariant). One flush per option (thousands of round trips for a brand list; hence the variadic `save()`). |
| **D14** | **Forward compatibility with dependent options** (owner, Q7) | Nothing is added now. Explicitly: **no `parent_option_id`**. What this slice guarantees: (1) options are roots that can be referenced by id, so a Model option can later hold a `?AttributeOptionId $parentOptionId` of a Brand option. (2) C-17's uniqueness lives **only** in the index and the port's `isLabelTaken(AttributeId, AttributeLabel, ?AttributeOptionId $except)`, never in an in-memory list on `Attribute` beyond the define-time batch. Widening it adds a `?parentOptionId` argument to the pre-check and replaces the index with the slice-1 two-partial-index pair: `(attribute_id, label_key) WHERE (parent_option_id IS NULL)` and `(attribute_id, parent_option_id, label_key) WHERE (parent_option_id IS NOT NULL)`. One plain `(attribute_id, parent_option_id, label_key)` index would *not* do, because NULLs are distinct (the C-3 lesson). (3) `defineChoice()`'s in-memory duplicate check applies to one flat batch, which stays correct for top-level options. (4) C-18 is about the attribute's total count and stays meaningful. (5) Ordering by id is unaffected. | Adding `parent_option_id` now (no reader, and the owner said not to). |

## Domain layer (pure PHP) — `src/src/Domain/Catalog/`

### Value objects (`ValueObject/`)

- **`AttributeId`**, **`AttributeOptionId`**: the `CategoryId` shape. They throw
  `InvalidAttributeId` / `InvalidAttributeOptionId`. Docblock → D12.
- **`AttributeLabel`**: `MAX_LENGTH = 100`. Trim, 1–100 by `mb_strlen`, reject `/\p{Cc}/u` (C-9).
  Methods: `toString()`, `equals()`, and **`key(): string`** = `mb_convert_case(…, MB_CASE_LOWER_SIMPLE)`. `key()` is the
  single definition of "same label" for C-17. The stored column and the pre-check both come from
  it.
- **`AttributeCode`**: the `CategorySlug` rules (C-10).
- **`Unit`**: 1–16, no `\p{Cc}` (C-16).
- **`NumberRules`**: `of(?Unit, ?int $minimum, ?int $maximum)`. `min > max` →
  `InvalidNumberRules`. It has no `allows(int)` yet (no caller).
- **`AttributeType`**: backed enum `choice | number | boolean | text`.

### Aggregates (`Entity/`)

```
final class Attribute
  private AttributeId $id; private CategoryId $categoryId; private AttributeCode $code;
  private AttributeLabel $label; private AttributeType $type; private bool $required; private ?NumberRules $numberRules;

  static defineChoice(AttributeId, CategoryId, AttributeCode, AttributeLabel, bool $required, list<AttributeLabel> $initialOptions): self
      [] → InvalidAttributeOptions (C-18); two equal key() → DuplicateAttributeOption (C-17, this batch)
  static defineNumber(…, bool $required, NumberRules): self
  static defineBoolean(…, bool $required): self
  static defineText(…, bool $required): self

  relabel(AttributeLabel), recode(AttributeCode), changeRequired(bool)
  changeNumberRules(NumberRules)                       type ≠ Number → AttributeRuleNotApplicable
  newOption(AttributeOptionId, AttributeLabel): AttributeOption   type ≠ Choice → AttributeRuleNotApplicable
  allowOptionRemoval(int $optionCount): void           type ≠ Choice → AttributeRuleNotApplicable;
                                                       < 1 → \InvalidArgumentException; == 1 → AttributeNeedsAnOption
  id(), categoryId(), code(), label(), type(), isRequired(), numberRules()

final class AttributeOption
  private AttributeOptionId $id; private AttributeId $attributeId; private AttributeLabel $label; private string $labelKey;
  static create(AttributeOptionId, AttributeId, AttributeLabel): self   // @internal: call Attribute::newOption()
  relabel(AttributeLabel): void                        label and labelKey assigned together; id kept (C-19)
  id(), attributeId(), label(), labelKey()
```

- **`defineChoice` takes the labels but does not keep them.** The initial labels are the *facts* it
  decides C-17/C-18 on for the defining batch. The options themselves are separate aggregates the
  handler builds through `newOption()`. The docblock says so.
- `allowOptionRemoval()` is a guard with no state change, and that is deliberate. "A choice
  attribute has at least one option" is the attribute's invariant, so the attribute decides. The
  count is a fact read under the lock, and the option's own removal is non-existence, which
  ADR-0012 keeps off the aggregate.
- `AttributeOption::create()` is public because PHP has no package visibility. It is marked
  `@internal`, and the Foundry factory and the handlers go through `newOption()`. This is accepted
  as a convention, not an enforcement. It is noted in ADR-0017.
- `labelKey` is a persisted derived field. It exists only for the index (D3), is always set from
  `label->key()`, and has no setter.
- No `delete()`, no setters for ids/owners/type (C-14), no `RecordsEvents` (D10). Every check
  precedes every assignment.

### Exceptions (`Exception/`)

All extend `\DomainException`:
- Value errors: `InvalidAttributeId`, `InvalidAttributeOptionId`, `InvalidAttributeLabel`,
  `InvalidAttributeCode`, `InvalidUnit`, `InvalidNumberRules`, `InvalidAttributeOptions`.
- Option rules: `DuplicateAttributeOption` (carries the label), `AttributeNeedsAnOption`,
  `AttributeOptionNotFound`, `AttributeRuleNotApplicable`.
- Lookups and guards: `AttributeNotFound`, `AttributeCodeAlreadyTaken`, `CategoryHasAttributes`.

### Ports (`Port/`)

```
interface AttributeRepository
  nextIdentity(): AttributeId
  get(AttributeId): Attribute                                          // AttributeNotFound
  isCodeTaken(CategoryId, AttributeCode, ?AttributeId $except): bool   // C-11 pre-check
  existsForCategory(CategoryId): bool                                  // C-13
  save(Attribute): void                                                // 23505 → AttributeCodeAlreadyTaken
  remove(Attribute): void

interface AttributeOptionRepository
  nextIdentity(): AttributeOptionId
  get(AttributeOptionId): AttributeOption                              // AttributeOptionNotFound
  isLabelTaken(AttributeId, AttributeLabel, ?AttributeOptionId $except): bool   // C-17 pre-check (D14: gains ?parent later)
  countForAttribute(AttributeId): int                                  // C-18 fact
  save(AttributeOption ...$options): void                              // one flush; 23505 → DuplicateAttributeOption
  remove(AttributeOption): void
  removeAllForAttribute(AttributeId): void                             // C-20 sweep, set-based (ADR-0012)
```

Both docblocks say that the fact reads are only true inside `CategoryTreeLock::exclusively()`.
`CategoryTreeLock` itself is unchanged except for one docblock sentence (D5).

## Application layer — `src/src/Application/Catalog/`

**Commands** (primitives in):
- `DefineAttribute(string $categoryId, string $type, string $label, string $code, bool $required, ?string $unit, ?int $minimum, ?int $maximum, list<string> $optionLabels)`
- `EditAttribute(string $id, string $label, string $code, bool $required, ?string $unit, ?int $minimum, ?int $maximum)`
- `DeleteAttribute(string $id)`
- `AddAttributeOption(string $attributeId, string $label)`
- `RelabelAttributeOption(string $attributeId, string $optionId, string $label)`
- `RemoveAttributeOption(string $attributeId, string $optionId)`

**Handlers.** Each one's whole body runs inside `$this->lock->exclusively(fn () => …)` (D5):

- **`DefineAttributeHandler`**:
  1. `categories->get()` (C-12; a malformed id → `CategoryNotFound`).
  2. Build the VOs.
  3. Pre-check `isCodeTaken(categoryId, code, null)` (C-11).
  4. `match(type)` → `define…`.
  5. For a choice: build the options via `newOption()`, then `options->save(...$options)` **then**
     `attributes->save($attribute)` (D13; the comment names D7's opposite order).

  It returns `AttributeId`.
- **`EditAttributeHandler`**:
  1. `get()`.
  2. `relabel` / `recode` / `changeRequired` where changed.
  3. Number rules: for a number, `changeNumberRules()`. For another type with non-null rule fields,
     still call it, so the aggregate refuses (the rule's single home).
  4. If the code changed, pre-check C-11 with `except`.
  5. `save()`.

  Any refusal rolls back, and the lock adapter `clear()`s the EM (AC-22).
- **`DeleteAttributeHandler`**: `get()`, `attributes->remove()`, then
  `options->removeAllForAttribute()` (D7 order). The docblock says this is where the Listing slice
  adds its "in use" guard or archiving.
- **`AddAttributeOptionHandler`**:
  1. `attributes->get()`.
  2. Build the label.
  3. Pre-check `isLabelTaken(attributeId, label, null)` → `DuplicateAttributeOption`.
  4. `$attribute->newOption(options->nextIdentity(), label)` (C-15).
  5. `options->save()`.

  It returns `AttributeOptionId`.
- **`RelabelAttributeOptionHandler`**:
  1. `attributes->get()`.
  2. `options->get()`; if it is not this attribute's option → `AttributeOptionNotFound`.
  3. Pre-check `isLabelTaken(…, except: optionId)`.
  4. `relabel()`, then `save()`.
- **`RemoveAttributeOptionHandler`**:
  1. `attributes->get()`.
  2. `options->get()` plus the ownership check.
  3. `$attribute->allowOptionRemoval(options->countForAttribute(attributeId))`.
  4. `options->remove()`.
- **`DeleteCategoryHandler` (changed)**: after C-7, `existsForCategory()` →
  `CategoryHasAttributes`.

A malformed option id is treated as `AttributeOptionNotFound`, and a malformed attribute id as
`AttributeNotFound` (the slice-1 `parentId()` pattern).

**Idempotency:**
- A double submit of define hits C-11; a double add-option hits C-17.
- Edit and relabel are idempotent.
- Deleting or removing an id that is already gone answers 404.

## Infrastructure layer — `src/src/Infrastructure/Catalog/`

### Persistence (`Persistence/Doctrine/`)

- **`mapping/Attribute.orm.xml`**: table `catalog_attribute`, with these fields:
  - `id` with `<generator strategy="NONE"/>`
  - `categoryId → category_id catalog_category_id`, a **plain column**. The header comment cites
    ADR-0009/0017.
  - `code → code` (`length=64`) and `label → label` (`length=100`)
  - `type → type` as a `string`, `length=16`, with
    `enum-type="App\Domain\Catalog\ValueObject\AttributeType"`
  - `required → is_required boolean`
  - `numberRules → number_rules catalog_attribute_number_rules nullable`

  `<unique-constraint name="uniq_catalog_attribute_category_code" columns="category_id,code"/>` also
  serves `existsForCategory()` and the list page.
- **`mapping/AttributeOption.orm.xml`**: table `catalog_attribute_option`, with these fields:
  - `id`
  - `attributeId → attribute_id catalog_attribute_id`, a plain column
  - `label → label` (`length=100`)
  - `labelKey → label_key string length=100`

  `<unique-constraint name="uniq_catalog_attribute_option_label" columns="attribute_id,label_key"/>`.
  It also serves `countForAttribute`, `removeAllForAttribute`, the per-attribute `ORDER BY id`
  list, and Phase 2's `option_id → label` lookup. The header comment covers D3: why `label_key`
  and not an expression index, and why there is no position. It also covers D14: how the index
  widens.
- **DBAL types**:
  - `AttributeIdType` and `AttributeOptionIdType` (`GuidType`)
  - `AttributeCodeType` and `AttributeLabelType` (`StringType`)
  - `NumberRulesType` (`JsonbType`; fatal on a bad shape; messages never quote the data)

  All five are registered in `doctrine.yaml` (`catalog_attribute_*`). The `Catalog:` mapping block
  already covers `App\Domain\Catalog\Entity`.
- **`DoctrineAttributeRepository`** and **`DoctrineAttributeOptionRepository`**:
  - They do not extend `ServiceEntityRepository`. Ids come from `Uuid::v7()`.
  - `save()` catches `UniqueConstraintViolationException` and throws the domain exception, keeping
    the cause. The EM is closed by then (Doctrine behaviour), and that is acceptable because every
    caller either redirects (option actions) or re-renders from DBAL (D11).
  - The fact reads (`isCodeTaken`, `existsForCategory`, `isLabelTaken`, `countForAttribute`) and
    `removeAllForAttribute` use DBAL on the same connection, so they run inside the lock's
    transaction.
  - `isLabelTaken` compares `label_key = :key` using `AttributeLabel::key()`.
  - `removeAllForAttribute` must also `clear()` any managed `AttributeOption`s of that attribute, or
    must run before any is loaded. The handler never loads options on the delete path, and the
    docblock says so.
- **`DbalAttributeSchema`**: a read model with no port (the `DbalCategoryOutline` precedent).
  - `forCategory()`: one query, `LEFT JOIN … GROUP BY` for the option counts,
    `ORDER BY lower(label), id`.
  - `find(id)`: one attribute row.
  - `optionsOf(attributeId)`: `ORDER BY id`.

### HTTP / UI

- **`AttributeAdminController`**: `#[IsGranted('ROLE_ADMIN')]`, `Requirement::UUID` on every id,
  `{type}` restricted to `choice|number|boolean|text`.
  - The routes are those in AC-32. Templates: `attributes`, `attribute_new`, `attribute_edit`.
  - Tokens: `delete-attribute-{id}`; `attribute-options-{id}` for the three option actions.
  - It maps exceptions per the failure contract, logs nothing, and derives the code via
    `CategorySlugDeriver`.
  - Textarea lines are split on `\R`, trimmed and stripped of blanks in the controller ("one per
    line" is a widget property).
- **`AttributeFormType` + `AttributeFormData`**:
  - Fields depend on `attribute_type`: always label, code and required; unit/minimum/maximum
    (`IntegerType`, *"Enter a whole number."*) for a number; options (textarea) for a choice at
    define only.
  - No business constraints: the VOs judge.
  - Extra fields are disallowed (Symfony's default), which makes AC-37's tamper case a 422.
- `index.html.twig` gains an "Attributes" link. `CategoryAdminController::delete()` catches
  `CategoryHasAttributes`.

### DI wiring (`config/services.yaml`)

```
App\Domain\Catalog\Port\AttributeRepository:       '@App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineAttributeRepository'
App\Domain\Catalog\Port\AttributeOptionRepository: '@App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineAttributeOptionRepository'
```

### Async / schedule / external

None.

## Interface boundary & input contract

| Surface | Accepts | Rejects |
|---|---|---|
| `GET /admin/categories/{id}/attributes` | ROLE_ADMIN, existing category | anon → `/login`; other → 403; unknown/malformed → 404 |
| `GET\|POST /admin/categories/{id}/attributes/new/{type}` | `attribute_form[label]`, `[code]` (may be blank), `[required]`, `[unit]`/`[minimum]`/`[maximum]` (number), `[options]` (choice, newline-separated), `[_token]` | unknown type/category → 404; failure contract → 422 |
| `GET\|POST /admin/attributes/{id}/edit` | same minus `[options]`; number fields only for a number | unknown → 404; extra fields → 422 |
| `POST /admin/attributes/{id}/delete` | `_token` (`delete-attribute-{id}`) | bad token → 403; unknown → 404 |
| `POST /admin/attributes/{id}/options[/{optionId}[/delete]]` | `label` (add/relabel), `_token` (`attribute-options-{id}`) | bad token → 403; unknown / foreign option → 404; refusal → 303 + error flash |

All parsing happens in the VOs; commands carry raw strings/ints.

## Data & migrations

One additive migration (`make migration.make`, reviewed by hand):

```
CREATE TABLE catalog_attribute (
  id UUID NOT NULL, category_id UUID NOT NULL, code VARCHAR(64) NOT NULL, label VARCHAR(100) NOT NULL,
  type VARCHAR(16) NOT NULL, is_required BOOLEAN NOT NULL, number_rules JSONB DEFAULT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_catalog_attribute_category_code ON catalog_attribute (category_id, code);
CREATE TABLE catalog_attribute_option (
  id UUID NOT NULL, attribute_id UUID NOT NULL, label VARCHAR(100) NOT NULL, label_key VARCHAR(100) NOT NULL,
  PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_catalog_attribute_option_label ON catalog_attribute_option (attribute_id, label_key);
```

- **No FK, no CHECK, no expression index, no generated column.** Each is either an aggregate
  boundary (ADR-0009) or diff noise DBAL cannot round-trip (D3). AC-30 proves the empty diff.
- `label_key varchar(100)`: `key()` uses simple case mapping (`MB_CASE_LOWER_SIMPLE`), one code point per code point. Full mapping (`mb_strtolower`) lengthens "İ" to two — caught in `/verify`.
- **What search will filter on (postgres-facet-search skill):**
  - Everything here is schema-layer data. Adding an attribute or option is an `INSERT`, never DDL
    (AC-27).
  - The future value layer's generic indexes `(attribute_id, option_id)` / `(attribute_id,
    value_int)` serve every attribute.
  - **Phase 2 facet labels:** `option_id → label` is a primary-key lookup or join on
    `catalog_attribute_option`, and per attribute `ORDER BY id` gives display order.
  - Zero promoted columns.
- Rollback: drop both tables.

## Test plan

- **Domain unit:** `AttributeLabelTest` (incl. `key()`), `AttributeCodeTest`, `AttributeIdTest`,
  `AttributeOptionIdTest`, `UnitTest`, `NumberRulesTest`, `AttributeTest`, `AttributeOptionTest`
  (AC-1…AC-11). Refusals snapshot every reader before and after.
- **Integration (DAMA):**
  - Foundry factories: `AttributeFactory` goes through `define…`; `AttributeOptionFactory` goes
    through `Attribute::newOption()`.
  - Test classes: `AttributeHandlersTest`, `DoctrineAttributeRepositoryTest`,
    `DoctrineAttributeOptionRepositoryTest` (backstops, `ORDER BY id`, sweep), `NoDdlTest`, and
    additions to `CategoryHandlersTest` (C-13, AC-25).
  - AC-19 uses a racing attribute-repository double (the `RacingCategoryRepository` precedent).
  - Read through a fresh repository after writes.
- **Lock (AC-26):** extend the `ObservedCategoryTreeLock` test to the six handlers.
- **Functional:**
  - `AttributeAdminTest`: authz matrix, list + one query, define per type, derivation, every
    failure-contract row, the C-11/C-17 index-race re-render, edit + tamper, option actions,
    delete, CSRF, 404s.
  - A `CategoryAdminTest` addition for the delete-with-attributes flash.
- **Bite proof (AC-43).** No performance test: the facet budget is Phase 2's gate.

## Risks / open questions

1. **(Q10, answered 2026-11-15: yes) Admin edit page with thousands of options.** Unpaginated (D11),
   accepted for now. If a real brand list makes it unusable, add a filter box or pagination as its own
   small change.
2. **EM after a failed flush.** A 23505 closes the EM. Every re-render reads through DBAL, and the
   option actions redirect (D11). Covered by the race tests.
3. **`removeAllForAttribute` and the identity map.** A set-based `DELETE` does not detach managed
   entities. The delete path never loads options (documented on the port's adapter).
4. **UUIDv7 ordering across clock steps** (D3 `ponytail:`). Acceptable. The upgrade is a `position`
   column.
5. **`AttributeOption::create()` is reachable without `newOption()`** (PHP has no package
   visibility). This is a convention (`@internal`), not an enforcement. Recorded in ADR-0017.
6. **ADR work (T0):**
   - **ADR-0017** *"Attribute schema: attributes and options as separate aggregates referencing by
     id; set-rules on options as cross-instance invariants under the tree lock; definitions stored
     once on the defining category"*. It records D1–D4, D6, D13 and D14, with the
     jsonb-inside-`Attribute` alternative rejected for unbounded options, dependent options,
     per-parent uniqueness and direct references from listings.
   - **ADR-0004 amendment** (dated), covering only two points: hard delete until values exist, and
     `is_facet`/`is_searchable` deferred. Its original table sketch stands.
   - **ADR-0016 amendment** (dated): the lock now guards attribute writes and cross-aggregate option
     invariants (C-17 pre-check, C-18 count), and C-13 is the first accreted delete guard.
