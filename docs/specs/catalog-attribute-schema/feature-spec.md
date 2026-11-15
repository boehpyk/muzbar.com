# Feature Spec: catalog-attribute-schema

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `catalog-attribute-schema`, slice **2 of 3** of the `Catalog` roadmap item (after
  `catalog-category-tree`, before `catalog-schema-inheritance`)
- **Bounded context(s):** `Catalog` only. Nothing under `Domain/Catalog` or `Application/Catalog`
  imports another context.
- **Related PRD items:** FR-1 ("admins … dynamically create, edit, and delete … their associated
  attribute schemas"), US-1 (pre-defined dropdown specifications), PRD §9 *Schema Drift*, PRD
  validation #2 (Schema-Mutation PoC, which this slice prepares but does not close)
- **Governing ADRs:** [ADR-0004](../../adr/0004-dynamic-category-attribute-schema.md) (attributes are
  data; **no admin action ever runs DDL**; its `attribute` + `attribute_option` table sketch stands),
  [ADR-0007](../../adr/0007-persistence-conventions-for-domain-aggregates.md),
  [ADR-0009](../../adr/0009-email-verification-tokens-modelled-in-the-domain.md) (reference by id, no
  FK, two saves in a deliberate order), [ADR-0012](../../adr/0012-challenge-retention-and-recurring-background-work.md)
  (an aggregate governs its transitions, not its non-existence),
  [ADR-0016](../../adr/0016-category-tree-per-node-aggregates-under-one-tree-lock.md) (the aggregate
  decides on facts passed in; the lock makes them true; an index is the backstop; delete guards accrete)
- **Status:** Approved 2026-11-15 (D1–D14; owner answers Q1–Q10 recorded). `AttributeOption` is its
  own aggregate.
- **Date:** 2026-11-15

> **What this slice teaches, beyond the feature.** Slice 1 asked *"where does a rule live when it
> spans many instances of one aggregate?"* This slice asks the next question with a thing that has
> parts: an attribute "Brand" with thousands of options, and later a "Model" whose options each hang
> under a brand. The tempting answer is to put the options inside the attribute, because every
> option rule looks like a rule about the set. The slice deliberately rejects that (plan D2). An
> unbounded set is not a consistency boundary you load on every edit. Dependent options will make
> "unique label" a rule per *(attribute, parent option)*, not per attribute. And listings will point
> at options directly. So **`AttributeOption` is its own aggregate**, and its set-rules (unique
> label, never the last option) become **cross-instance invariants** handled exactly the way
> ADR-0016 handles the tree's: *the aggregate decides on facts passed in, `CategoryTreeLock` makes
> the facts true, a unique index is the backstop where one exists.*
>
> It also adds the **first reference into the tree from another aggregate** (`Attribute` →
> `CategoryId`) and the **first delete guard** ADR-0016 said would accrete: a category that defines
> attributes cannot be deleted. And it adds the first use case that **creates several aggregates in
> one transaction**, defining a choice attribute together with its initial options, with the save
> order named and justified (ADR-0009).

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Attribute** | An admin-defined, typed structural property of the gear filed under one category ("String count", "Orientation", "Brand"). Aggregate root, `AttributeId`. | A **value** (what one listing says; `Listing` context, later). A facet (a search *presentation* of an attribute; Phase 2). |
| **Attribute schema** (of a category) | The attributes a category **defines itself**. In this slice that is everything a category has. Nothing is inherited yet. | The *effective* schema (own + inherited), which `catalog-schema-inheritance` introduces. |
| **Defining category** | The one category an attribute belongs to, by `CategoryId`. It never changes (C-14). | Categories that will *inherit* it (slice 3). |
| **Attribute type** | Exactly one of **choice**, **number**, **yes/no**, **text**. Fixed at definition (C-14). | A DB column type. Every attribute is a row in the same table. |
| **Choice attribute** | Pick exactly one value from an admin-defined list of options. The roadmap and ADR-0004 call this "enum". The code says `Choice` so it never collides with PHP's `enum`. | A multi-select (not supported). |
| **Option** | One entry of a choice attribute ("Left-handed", "Fender"). **Aggregate root** with a stable `AttributeOptionId`, belonging to one attribute by `AttributeId`. | A category. A part of the `Attribute` object (it is not one). |
| **Option order** | The order options were added in. It is derived from their UUIDv7 ids, and no position is stored (plan D3). | A manual order (not supported). |
| **Label key** | The case-folded form of a label (`mb_strtolower`), stored next to an option's label so that one unique index can enforce case-insensitive uniqueness (plan D3). | The label itself. |
| **Number attribute** | A whole number, optionally with a **unit** and optional **minimum**/**maximum**. | A range value. A range filter (Phase 2). |
| **Unit** | A short display label for a number attribute ("mm", "kg", "W"). Display and meaning only. There is no conversion. | A currency. |
| **Number rules** | Value object holding the unit, minimum and maximum of one number attribute. | |
| **Yes/no attribute** | A boolean ("Has case"). `Boolean` in code. | The required flag. |
| **Text attribute** | Free text ("Serial number"). Never a facet. | A listing's title/description. |
| **Required** | Whether a listing *will have to* give a value. Stored now, enforced by `Listing` later. | Column nullability. |
| **Code** | The URL/API-safe key of an attribute (`string-count`). Same format as a category slug. Unique within its defining category (C-11). Editable. Nothing references an attribute by code. | The id. The label. |
| **Label** | What an admin, and later a user, reads. 1–100 characters. Used for attribute labels and option labels. | Code. |
| **Dependent option** *(future, not this slice)* | An option that belongs under a *parent option* of another attribute (Model "Standard" under Brand "Fender"). The design must leave room for it (plan D14). | |

### Invariants (continuing Catalog's `C-n` series; C-1 … C-8 belong to `Category`)

The pattern for every rule that spans instances (ADR-0016): **the aggregate decides on facts passed
in, `CategoryTreeLock` makes the facts true, and a unique index is the backstop where one exists.**

| # | Invariant | Who decides | Facts / backstop |
|---|---|---|---|
| **C-9** | A label (attribute **or** option) is 1–100 characters after trimming, with no control characters. | `AttributeLabel` VO | `varchar(100)` |
| **C-10** | A code matches `^[a-z0-9]+(?:-[a-z0-9]+)*$`, is 1–64 characters, and is stored trimmed and lower-cased. | `AttributeCode` VO | `varchar(64)` |
| **C-11** | **Code unique within the defining category.** | Handler pre-check under the lock (for the message) | **Unique index `(category_id, code)`**. 23505 → `AttributeCodeAlreadyTaken` (the C-3 pattern). |
| **C-12** | An attribute's defining category exists when the attribute is defined. | `DefineAttributeHandler` under the lock (`CategoryRepository::get()`) | None (no FK, ADR-0009 d.4) |
| **C-13** | **A category that defines attributes cannot be deleted.** | `DeleteCategoryHandler` under the lock (`AttributeRepository::existsForCategory()`) | None |
| **C-14** | An attribute's id, defining category and type never change. An option's id and attribute never change. | No such methods exist | PKs |
| **C-15** | **Type coherence:** a *number* attribute has `NumberRules`; other types have none. Only a *choice* attribute can have options. Number rules or a new option on the wrong type are refused. | `Attribute` (`changeNumberRules()`, `newOption()`) → `AttributeRuleNotApplicable` | None |
| **C-16** | Number rules: `minimum ≤ maximum` when both are given. A unit is 1–16 characters after trimming, with no control characters. | `NumberRules` / `Unit` VOs | None |
| **C-17** | **Option labels are unique within an attribute, case-insensitively.** "Left" and "left" cannot both exist. Labels within one define request are checked in memory. | `Attribute::defineChoice()` decides on the initial labels it is handed. For later adds and relabels, the handler pre-checks under the lock (for the message). | **Unique index `(attribute_id, label_key)`**. 23505 → `DuplicateAttributeOption`. Plan D3/D14: this widens to per parent option later. |
| **C-18** | **A choice attribute always has at least one option.** It is defined with ≥ 1 option, and its last option cannot be removed. | `Attribute::defineChoice()` (non-empty initial labels) and `Attribute::allowOptionRemoval(int $optionCount)`, deciding on a count read under the lock | None. The count is only true under the lock. |
| **C-19** | An option's id survives relabelling, so a later listing's reference survives a label fix. Options are listed in the order they were added. | `AttributeOption::relabel()` keeps the id. Ids are UUIDv7 minted in order under the lock. | PK. Order = `ORDER BY id`. |
| **C-20** | **Deleting an attribute deletes its options**, in the same transaction. No option outlives its attribute. | `DeleteAttributeHandler` under the lock: two port calls (plan D7) | None (no FK cascade) |

---

## User story

As an **admin**, I want to **define, edit and delete typed attributes on a category, including
choices with their options (thousands for a brand list), numbers with a unit and bounds, yes/no
and text**, so that **the structured specifications listings will be filed with exist as data,
without a developer or a migration** (FR-1, US-1, ADR-0004).

As **muzbar**, I want **the schema to be impossible to corrupt**. That means no duplicate code
within a category, no choice without options, no duplicate option label under one attribute, no
number whose minimum exceeds its maximum, no option whose identity changes on a relabel, no
attribute pointing at a deleted category, no option outliving its attribute, and **no admin action
that runs DDL**.

Secondary and ranked (Constitution §2): as the **developer**, I want this slice to show honestly
how to draw an aggregate boundary around a thing with parts, and when *not* to.

---

## In scope

**Domain (`Catalog`):**
- Aggregate `Attribute`: `defineChoice`, `defineNumber`, `defineBoolean`, `defineText`, `relabel`,
  `recode`, `changeRequired`, `changeNumberRules`, `newOption`, `allowOptionRemoval`.
- Aggregate `AttributeOption`: created through `Attribute::newOption()`, with `relabel`.
- Enum `AttributeType`.
- VOs `AttributeId`, `AttributeOptionId`, `AttributeCode`, `AttributeLabel`, `NumberRules`, `Unit`.
- Exceptions.
- Ports `AttributeRepository` and `AttributeOptionRepository`. `CategoryTreeLock` is reused.

**Application:**
- Commands + handlers: `DefineAttribute`, `EditAttribute`, `DeleteAttribute`,
  `AddAttributeOption`, `RelabelAttributeOption`, `RemoveAttributeOption`.
- `DeleteCategoryHandler` gains the C-13 guard.

**Infrastructure:**
- Mappings `Attribute.orm.xml` and `AttributeOption.orm.xml`, plus DBAL types.
- Adapters `DoctrineAttributeRepository` and `DoctrineAttributeOptionRepository`.
- Read model `DbalAttributeSchema`.
- One additive migration (two tables).
- `AttributeAdminController` + one form type + Twig pages.
- An "Attributes" link on each category row, and the category-delete flash.

---

## Non-goals (explicit, hold the line)

| Deliberately excluded | Belongs to / why |
|---|---|
| **Inheritance**: a child seeing its ancestors' attributes, overrides, what a move does to a subtree | `catalog-schema-inheritance`. This slice only fixes *where definitions are stored*: once, on the defining category (plan D4). |
| **Dependent options** (`parent_option_id`, Model under Brand) | Expected later. The owner confirmed it is out of scope here. Plan D14 lists what this slice does so as not to block it. **No `parent_option_id` column is added now.** |
| Moving an attribute to another category, or an option to another attribute | C-14. Delete and re-define while no values exist. |
| **Listing values**, value validation, enforcing `required` | `Listing` context skeleton |
| **Facets / search**: `is_facet`, `is_searchable`, counts, range filters, hot columns | Phase 2 (owner, Q8). No reader yet. The flags are additive columns. |
| "In use by listings" guards, **soft delete** | The `Listing` slice, when values first reference attributes/options (owner, Q4; ADR-0004 amendment) |
| Domain events | No consumer yet (slice-1 D6 rule) |
| Decimal numbers, unit conversion, range values | Owner, Q1: integers only |
| Multi-select choice attributes | Owner, Q6 |
| Manual ordering of attributes or options; pagination or search on the admin option list | Owner, Q3. A `position` column and an admin option filter are additive. |
| Localised labels | Slice-1 D13 precedent |
| Public pages, the morphing listing form, the Schema-Mutation PoC gate | Phase 1 gate / Phase 2 |
| Live Components, JavaScript, Tailwind | Slice-1 D9 |
| A shared `Uuid` base/trait for ids | Owner, Q9: a separate refactor |

---

## Acceptance criteria (the Definition of Done checklist)

### Values and aggregates (Domain unit tests, no kernel)

- [ ] **AC-1 (C-9):** `AttributeLabel` trims and accepts 1–100 characters counted with
      `mb_strlen` (`str_repeat('я', 100)` is accepted). It rejects `""`, `"   "`, 101 characters and
      `"Body\ttype"`, each with `InvalidAttributeLabel`. `key()` returns `mb_strtolower` of the
      trimmed label: `"LEFT-Handed"` → `left-handed`, `"Ёлка"` → `ёлка`.
- [ ] **AC-2 (C-10):** `AttributeCode` trims and lower-cases (`" String-Count "` → `string-count`).
      It accepts `a`, `5-string` and 64 characters. It rejects `""`, 65 characters, `-a`, `a-`,
      `a--b`, `a_b`, `a b` and `ś`, each with `InvalidAttributeCode`.
- [ ] **AC-3:** `AttributeId` and `AttributeOptionId` each accept an RFC 4122 string, lower-case
      it, and reject anything else (`InvalidAttributeId` / `InvalidAttributeOptionId`). `equals()`
      is typed to its own class.
- [ ] **AC-4 (C-16):** `Unit` accepts `"mm"` and `"″"`. It rejects `""`, 17 characters and
      `"m\nm"` with `InvalidUnit`. `NumberRules::of(unit, min, max)` accepts every combination of
      nulls and accepts `min == max`. It throws `InvalidNumberRules` when `min > max`.
- [ ] **AC-5 (define):** `defineNumber` yields `numberRules() !== null`. `defineChoice`,
      `defineBoolean` and `defineText` yield `numberRules() === null`. Each returns the given id,
      category id, code, label, type and required flag unchanged.
- [ ] **AC-6 (C-17, C-18 at define):** `defineChoice` with an empty initial-label list throws
      `InvalidAttributeOptions`. With `["Left", " left "]` it throws `DuplicateAttributeOption`
      naming `left`. With 5 000 distinct labels it succeeds. **There is no upper bound**: no
      `MAX_OPTIONS` constant exists.
- [ ] **AC-7 (C-15):** `newOption()` on a choice attribute returns an `AttributeOption` whose
      `attributeId()` equals the attribute's id and whose `label()`/`id()` are those given. On a
      number, yes/no or text attribute it throws `AttributeRuleNotApplicable`.
      `changeNumberRules()` on a non-number attribute throws `AttributeRuleNotApplicable`. Every
      refused call leaves every reader unchanged.
- [ ] **AC-8 (C-18):** `allowOptionRemoval(1)` on a choice attribute throws
      `AttributeNeedsAnOption`. `allowOptionRemoval(2)` returns without throwing. On a non-choice
      attribute it throws `AttributeRuleNotApplicable`. A count below 1 is a caller bug
      (`\InvalidArgumentException`).
- [ ] **AC-9 (C-19):** `AttributeOption::relabel()` changes `label()` and `labelKey()` together
      and leaves `id()` and `attributeId()` unchanged.
- [ ] **AC-10 (C-14):** Neither aggregate has a public method changing its id, its owner
      (category / attribute) or the attribute's type. This is asserted by reflection: the public
      method names of both classes equal an explicit allow-list.
- [ ] **AC-11:** Neither aggregate uses `RecordsEvents`. Nothing in `src/src/Domain/Catalog/` or
      `src/src/Application/Catalog/` imports another bounded context (`grep` in `/verify`).

### Use cases against a real database (Application/Integration, DAMA)

- [ ] **AC-12 (define):** `DefineAttribute` for each type on an existing category persists exactly
      one `catalog_attribute` row with `category_id` = the category's **id**. For a choice with
      labels `[A, B, C]` it persists exactly three `catalog_attribute_option` rows with that
      `attribute_id`. A fresh repository returns equal aggregates, and the options listed by the
      read model come back in input order `A, B, C`.
- [ ] **AC-13 (order by id):** Defining a choice with 50 labels, then adding two more with
      `AddAttributeOption`, yields option ids that sort (as `uuid`, `ORDER BY id`) in exactly
      insertion order. There is no `position` column.
- [ ] **AC-14 (C-12):** `DefineAttribute` naming an unknown or malformed category id throws
      `CategoryNotFound`. Neither table gains a row.
- [ ] **AC-15 (C-11):** A second `string-count` on the same category is refused with
      `AttributeCodeAlreadyTaken`, both via `DefineAttribute` and via `EditAttribute`. The same
      code on another category is accepted. Saving an attribute with its own unchanged code is
      accepted.
- [ ] **AC-16 (C-11 backstop):** A raw-DBAL duplicate `(category_id, code)` insert fails with a
      unique violation. With the pre-check bypassed, `DoctrineAttributeRepository::save()` throws
      `AttributeCodeAlreadyTaken`, not a DBAL exception.
- [ ] **AC-17 (C-17 through handlers):** On an attribute holding `Right`:
      - `AddAttributeOption("RIGHT")` is refused with `DuplicateAttributeOption`.
      - `RelabelAttributeOption(<other>, "right")` is refused the same way.
      - `RelabelAttributeOption(<Right>, "RIGHT")` (same option, case change) is accepted.
      - `AddAttributeOption("Right")` on a **different** attribute is accepted.
      - Every refusal leaves both tables unchanged.
- [ ] **AC-18 (C-17 backstop):** A raw-DBAL duplicate `(attribute_id, label_key)` insert fails with
      a unique violation. With the pre-check bypassed, `DoctrineAttributeOptionRepository::save()`
      throws `DuplicateAttributeOption`, not a DBAL exception.
- [ ] **AC-19 (define is all-or-nothing):** If the attribute's code collides at the index after the
      options were written (a racing repository double), `DefineAttribute` throws
      `AttributeCodeAlreadyTaken` and **neither table** gains a row.
- [ ] **AC-20 (C-18 through handlers):** `RemoveAttributeOption` on an attribute's only option
      throws `AttributeNeedsAnOption` and the row remains. With two options it removes one, and the
      survivor is unchanged.
- [ ] **AC-21 (option belongs to attribute):** `RelabelAttributeOption` / `RemoveAttributeOption`
      with an option id that exists but belongs to **another** attribute throw
      `AttributeOptionNotFound` and change nothing. The same holds for an unknown or malformed id.
- [ ] **AC-22 (edit):** `EditAttribute` changes label, code, required and (for a number) the number
      rules in one call. An edit combining a valid relabel with `min > max` persists **nothing**: a
      fresh repository shows the old label.
- [ ] **AC-23 (C-20):** `DeleteAttribute` on a choice with three options removes the attribute row
      **and** all three option rows. Options of other attributes are untouched. An unknown id throws
      `AttributeNotFound`.
- [ ] **AC-24 (C-13):** `DeleteCategory` on a leaf that defines an attribute throws
      `CategoryHasAttributes`, and both rows remain. After `DeleteAttribute`, the same
      `DeleteCategory` succeeds. A category with children **and** attributes reports
      `CategoryHasChildren` (C-7 runs first).
- [ ] **AC-25 (ids, not slugs):** Renaming, re-slugging and moving the defining category leaves
      every attribute and option row byte-for-byte unchanged.
- [ ] **AC-26 (the lock):** Each of the six attribute handlers performs every repository call
      inside `CategoryTreeLock::exclusively()`. The existing `ObservedCategoryTreeLock` recording
      double proves it (concrete service id + `disableReboot()`). The test fails when
      `exclusively()` is removed from any one handler.
- [ ] **AC-27 (no DDL):** Running every handler (define each type, edit, add/relabel/remove option,
      delete) leaves `information_schema.columns` and `pg_indexes` for schema `public` identical
      before and after. This is ADR-0004's hard invariant as a test.
- [ ] **AC-28 (corrupt row is loud):** A `catalog_attribute` row whose `number_rules` holds an
      unreadable shape (raw SQL `'{"min":"x"}'`) makes `get()` throw a DBAL `ConversionException`.
      It never hydrates a partial attribute.

### Persistence conventions (ADR-0007 / ADR-0009)

- [ ] **AC-29:** Table `catalog_attribute` has these columns:
      - `id uuid PK`
      - `category_id uuid NOT NULL`
      - `code varchar(64) NOT NULL`
      - `label varchar(100) NOT NULL`
      - `type varchar(16) NOT NULL`
      - `is_required boolean NOT NULL`
      - `number_rules jsonb NULL`

      It has the unique index `uniq_catalog_attribute_category_code (category_id, code)`. Table
      `catalog_attribute_option` has these columns:
      - `id uuid PK`
      - `attribute_id uuid NOT NULL`
      - `label varchar(100) NOT NULL`
      - `label_key varchar(100) NOT NULL`

      It has the unique index `uniq_catalog_attribute_option_label (attribute_id, label_key)`. There
      is **no foreign key, no association, no `position`, no `parent_option_id`, and no
      expression index**. `number_rules` is `jsonb`, not `json`.
- [ ] **AC-30:** After `make migrate`, `doctrine:schema:validate` passes and `make migration.make`
      generates **no** migration.
- [ ] **AC-31:** Ids are UUIDv7 from `nextIdentity()` on each repository, with
      `<generator strategy="NONE"/>`. These DBAL types are registered:
      - `catalog_attribute_id`
      - `catalog_attribute_option_id`
      - `catalog_attribute_code`
      - `catalog_attribute_label`
      - `catalog_attribute_number_rules`

      `type` maps through Doctrine's native `enum-type`. Both mappings live in
      `src/Infrastructure/Catalog/Persistence/Doctrine/mapping/`, inside the existing `Catalog`
      mapping block.

### Admin HTTP surface (Functional)

- [ ] **AC-32 (authz):** This applies to every new route:
      - `GET /admin/categories/{id}/attributes`
      - `GET|POST /admin/categories/{id}/attributes/new/{type}`
      - `GET|POST /admin/attributes/{id}/edit`
      - `POST /admin/attributes/{id}/delete`
      - `POST /admin/attributes/{id}/options`
      - `POST /admin/attributes/{id}/options/{optionId}`
      - `POST /admin/attributes/{id}/options/{optionId}/delete`

      An anonymous request is redirected to `/login`. A verified `ROLE_USER` gets **403**. A
      `ROLE_ADMIN` gets 200 on GET and 303 after a successful POST. No row changes in the first two
      cases. The controller carries `#[IsGranted('ROLE_ADMIN')]`.
- [ ] **AC-33 (list):** `GET /admin/categories/{id}/attributes` shows the category name and one row
      per attribute. Rows are ordered by label case-insensitively, with id as the tie-break. Each
      row shows label, code, type and "required", plus unit/bounds for a number and the option
      count for a choice. Each row has "Edit" and a POST "Delete", and there is one "define" link
      per type. The attributes **and** their option counts come from **one** SQL query. Every row
      on `/admin/categories` links here ("Attributes").
- [ ] **AC-34 (define via form):** For each `{type}` ∈ `choice|number|boolean|text` the form shows
      label, optional code and required. For `number` it adds unit, minimum and maximum. For
      `choice` it adds an options textarea: one label per line, lines trimmed, blank lines dropped.
      A valid submit answers 303 to the list with a flash naming the attribute. An unknown `{type}`
      or an unknown/malformed category `{id}` answers **404**.
- [ ] **AC-35 (code derivation):** A blank code is derived from the label by `CategorySlugDeriver`
      (`"Fretboard wood"` → `fretboard-wood`, `"Гриф"` → `grif`). An underivable label (`"!!!"`)
      shows *"Enter a code: one could not be derived from this label."* on the code field, with
      422 and nothing persisted.
- [ ] **AC-36 (errors on the right field, 422):** Every *user error* row of the failure contract
      that targets a form field re-renders with HTTP **422**, nothing persisted, the exact message
      on the stated field, and every other field keeping its submitted value. This includes the
      index-race rows (C-11, C-17), whose re-render reads only through DBAL read models.
- [ ] **AC-37 (edit):** The edit page is pre-filled and shows the type as read-only text. Number
      fields appear only for a number attribute. For a choice it lists every option in insertion
      order, each with a relabel form and a POST remove button, plus an add-option form. Posting
      `unit`/`minimum`/`maximum` to a non-number attribute's form is rejected by the form as extra
      fields (422), and nothing changes.
- [ ] **AC-38 (option actions):** Add, relabel and remove each answer 303 back to the edit page:
      with a success flash, or with the failure contract's error flash and no change. No GET route
      changes state.
- [ ] **AC-39 (delete):** A valid `POST /admin/attributes/{id}/delete` deletes the attribute and its
      options and answers 303 to the category's attribute list with a flash. Deleting a category
      that defines attributes from `/admin/categories` answers 303 with *"“<name>” has attributes.
      Delete them first."* and deletes nothing.
- [ ] **AC-40 (CSRF):** Every POST with a missing or wrong token mutates nothing. Define and edit
      re-render with 422. Delete and the three option actions answer **403**.
- [ ] **AC-41 (unknown ids):** An unknown or malformed attribute `{id}` or `{optionId}`, or an
      option of another attribute, answers **404** on every route and writes nothing.

### Cross-cutting

- [ ] **AC-42:** Domain refusals (*user error* rows) are not logged at `error`. A corrupt row
      (AC-28) is: an uncaught exception, a 500, and a Sentry event.
- [ ] **AC-43 (tests bite):** Each mutation below makes a named test fail. Record which test in
      the commit message.
      - (a) compare `label()` instead of `labelKey()` in the option pre-check **and** drop the
        unique index: AC-17/AC-18 fail
      - (b) remove the `allowOptionRemoval()` call
      - (c) remove `exclusively()` from `AddAttributeOptionHandler`
      - (d) remove the C-13 guard
      - (e) remove the option sweep from `DeleteAttributeHandler`
      - (f) make `relabel()` mint a new id
      - (g) remove the `min > max` check
      - (h) map `label_key` from `label` without folding case
- [ ] **AC-44:** `make check` is green, `make test` passes **twice in a row**, and Deptrac reports 0
      violations with `--fail-on-uncovered`.

---

## Failure contract

| Condition | Expected behaviour | Kind |
|---|---|---|
| Label empty, > 100 characters or containing control characters | `InvalidAttributeLabel`. 422. **label**: *"Label must be 1–100 characters, without line breaks."* | user error |
| Code malformed or > 64 | `InvalidAttributeCode`. 422. **code**: *"Use lower-case letters, digits and single hyphens (max 64)."* | user error |
| Code blank and not derivable | 422. **code**: *"Enter a code: one could not be derived from this label."* No command sent. | user error |
| Code already used in this category (pre-check **or** index race) | `AttributeCodeAlreadyTaken`. 422. **code**: *"“<code>” is already used by another attribute of this category."* | user error |
| Unit invalid | `InvalidUnit`. 422. **unit**: *"Unit must be 1–16 characters, without line breaks."* | user error |
| Minimum above maximum | `InvalidNumberRules`. 422. **maximum**: *"Maximum must not be lower than minimum."* | user error |
| Minimum/maximum not an integer | Form transformation error. 422. **minimum**/**maximum**: *"Enter a whole number."* No command sent. | user error |
| Choice defined with no options | `InvalidAttributeOptions`. 422. **options**: *"A choice attribute needs at least one option."* | user error |
| An option line invalid (define) | `InvalidAttributeLabel`. 422. **options**: *"Each option must be 1–100 characters."* | user error |
| Duplicate option label at define (case-insensitive, or index race) | `DuplicateAttributeOption`. 422. **options**: *"“<label>” is listed twice."* | user error |
| Add/relabel to a duplicate label (pre-check **or** index race) | `DuplicateAttributeOption`. 303 to edit, error flash: *"“<label>” is already an option."* | user error |
| Add/relabel with an invalid label | `InvalidAttributeLabel`. 303, error flash: *"Each option must be 1–100 characters."* | user error |
| Remove the last option | `AttributeNeedsAnOption`. 303, error flash: *"A choice attribute needs at least one option. Delete the attribute instead."* | user error |
| Option action on a non-choice attribute (tampered request) | `AttributeRuleNotApplicable`. 303, error flash: *"That does not apply to this attribute type."* | user error |
| Number fields posted for a non-number attribute | Form rejects extra fields. 422. Nothing changes. | user error |
| Unknown/malformed category, attribute or option id; option of another attribute; unknown `{type}` | 404 | user error |
| Defining category deleted between page load and submit | `CategoryNotFound` → 404 | user error |
| Delete a category that defines attributes | `CategoryHasAttributes`. 303 to `/admin/categories` with the AC-39 flash | user error |
| Missing/invalid CSRF token | No mutation. 422 on define/edit, 403 on delete and option actions | user error |
| Anonymous / non-admin | `/login` redirect / 403 | authz |
| Two admin writes at once | Serialized by the tree lock; the second runs against committed state. | concurrency |
| Two tabs edit the same attribute/option | Last write wins (slice-1 Risk 4, accepted for one admin) | concurrency |
| `number_rules` holds an unreadable shape | Hydration throws, 500, logged at `error` → Sentry. No write. | corruption |
| Postgres down | The usual 500. Each command is one transaction, so there is no partial write. | dependency |
