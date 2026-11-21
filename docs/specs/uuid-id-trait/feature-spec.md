# Feature Spec: uuid-id-trait

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `uuid-id-trait`: a **refactor** slice, branch `refactor/uuid-id-trait`. Comes straight
  after `listing-publish` (that plan's Q7).
- **Bounded context(s):** **`Identity`**, **`Catalog`**, **`Listing`**: the id value objects only.
  The trait lives in the **shared kernel** (`App\Domain\Shared`). That is not a bounded context.
  **No aggregate, event, port, handler, adapter, mapping, DBAL type or migration changes.**
- **Related PRD items:** none. This is code health. Constitution §2 applies: the secondary goal is to
  learn DDD, and this slice shows **shared code without a shared type**.
- **Governing ADRs:** [ADR-0009](../../adr/0009-email-verification-tokens-modelled-in-the-domain.md)
  (the 2026-07-29 amendment sets the criterion: *"only if the extraction can preserve cross-type
  comparison as a compile-time error"*. The 2026-11-14 amendment says *"a trait meets it; its own small
  refactor, whose test is that every cross-type `equals()` is still a `TypeError`"*. **This slice
  carries that out.**) Also [ADR-0019](../../adr/0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
  decision 2 (contexts keep their own id classes),
  [ADR-0020](../../adr/0020-attribute-values-parts-of-listing-validated-against-catalog-schema-guarded-by-refusal.md)
  and [ADR-0021](../../adr/0021-listing-lifecycle-in-the-aggregate-completeness-on-public-writes-dispatch-after-commit.md)
  ("eleven copies" consequences, **discharged here**).
- **Status:** Approved by owner 2026-11-21 (Q1–Q8 as recommended)
- **Date:** 2026-11-21

> **What this slice teaches.** *Shared code is not a shared type.* A base class would merge eleven
> types into one: `equals(self)` would resolve `self` to the base, so `$userId->equals($categoryId)`
> would quietly return `false`. A trait copies the code into each class. Inside a trait, `self` means
> the class that uses it, so each id keeps its own type, and comparing a user id with a category id is
> still a `TypeError`.
> This does not conflict with *"structurally identical aggregates do not share a base class"*. That
> rule covers aggregates whose **behaviour differs** (four inverted rules) behind a similar shape.
> The eleven ids have **identical behaviour**, and a trait shares behaviour without sharing a type.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Id value object** | A `final readonly class` naming one aggregate's identity (or, in Listing, another context's identity in Listing's words). Built only by `fromString()`. Compared only with its own type. | The aggregate itself (ADR-0009 d.4: hold the id, never the object). |
| **UUID id shape** | The behaviour all eleven share: a version-agnostic RFC 4122 format check (`/…$/iD`), lower-casing, `equals(self)`, `toString()`/`__toString()`, a private constructor, and **no `generate()`**. | Minting an id, which is `Repository::nextIdentity()` in Infrastructure (ADR-0007). |
| **`UuidIdShape`** | The trait in `App\Domain\Shared\ValueObject` that holds the shape once. | A base class or interface. Neither exists, on purpose. |
| **Malformed hook** | The one thing each id class still writes itself: `private static function malformed(string): Invalid<Name>Id`, so each keeps throwing **its own** exception. | A shared `InvalidUuid` exception, which does not exist. |

## The eleven classes (verified 2026-11-21)

`grep -rln fromString src/src/Domain | xargs grep -l -i uuid` finds exactly these. Nothing else in
`Domain/` has the shape.

| # | Class | File (`src/src/Domain/…`) | Exception | Regex `D` flag |
|---|---|---|---|---|
| 1 | `Identity\ValueObject\UserId` | `Identity/ValueObject/UserId.php` | `Identity\Exception\InvalidUserId` | yes |
| 2 | `Identity\ValueObject\EmailVerificationRequestId` | `Identity/ValueObject/EmailVerificationRequestId.php` | `Identity\Exception\InvalidEmailVerificationRequestId` | **no** |
| 3 | `Identity\ValueObject\PasswordResetRequestId` | `Identity/ValueObject/PasswordResetRequestId.php` | `Identity\Exception\InvalidPasswordResetRequestId` | **no** |
| 4 | `Catalog\ValueObject\CategoryId` | `Catalog/ValueObject/CategoryId.php` | `Catalog\Exception\InvalidCategoryId` | yes |
| 5 | `Catalog\ValueObject\AttributeId` | `Catalog/ValueObject/AttributeId.php` | `Catalog\Exception\InvalidAttributeId` | yes |
| 6 | `Catalog\ValueObject\AttributeOptionId` | `Catalog/ValueObject/AttributeOptionId.php` | `Catalog\Exception\InvalidAttributeOptionId` | yes |
| 7 | `Listing\ValueObject\ListingId` | `Listing/ValueObject/ListingId.php` | `Listing\Exception\InvalidListingId` | yes |
| 8 | `Listing\ValueObject\SellerId` | `Listing/ValueObject/SellerId.php` | `Listing\Exception\InvalidSellerId` | yes |
| 9 | `Listing\ValueObject\CategoryId` | `Listing/ValueObject/CategoryId.php` | `Listing\Exception\InvalidCategoryId` | yes |
| 10 | `Listing\ValueObject\AttributeId` | `Listing/ValueObject/AttributeId.php` | `Listing\Exception\InvalidAttributeId` | yes |
| 11 | `Listing\ValueObject\AttributeOptionId` | `Listing/ValueObject/AttributeOptionId.php` | `Listing\Exception\InvalidAttributeOptionId` | yes |

**What is identical in all eleven:** `final readonly class`; `private const string FORMAT` (the
same pattern); a private promoted constructor `string $value`; `fromString(string): self` with
`preg_match` → `throw Invalid<X>::malformed($value)` → `new self(mb_strtolower($value))`;
`equals(self $other): bool` (`===` on the value); `toString()`; `__toString()`. **None** has
`generate()`, `jsonSerialize()` or a version check. Every exception `extends \DomainException`,
exposes `malformed(string): self`, and has the message `'"%s" is not a valid <noun> id.'`. None uses
`symfony/uid`. Validation is a plain regex, so the Domain stays pure, and `Uuid::v7()` appears only in
the Infrastructure repositories' `nextIdentity()`.

**Where they differ, and only here:**
1. **The exception class.** Each throws its own. The DBAL types catch the specific class
   (`catch (InvalidUserId $e)` → `ValueNotConvertible`), so this must be kept.
2. **The `D` modifier.** Rows 2 and 3 lack it, so they accept `"<uuid>\n"` and store the newline. The
   other nine refuse it. Fixing this is the slice's **one** behaviour change (AC-6).
3. **Docblocks and comment wording.** These are history: ADR-0009's argument copied into the
   classes. They do not affect behaviour.

## User story

As **the maintainer**, I want **the UUID id shape written once** so that a fix to it (for example the
`D` flag that two copies missed) happens in one place, and the next id is ~8 lines rather than a
twelfth ~45-line copy. **No id type may be able to compare equal to another.**

## In scope

- One trait, `App\Domain\Shared\ValueObject\UuidIdShape`, with the shared members.
- All eleven classes `use` it and keep only their docblock, the `use` line and the `malformed()` hook.
- `CatalogIsolationTest` widened to admit `Shared`, exactly as `ListingIsolationTest` already does.
  **Today it admits only `Catalog`**, so without this change the first Catalog id to use the trait
  fails it.
- Tests: two trailing-newline cases (rows 2 and 3), one cross-type ring test over all eleven, and the
  Listing grouped tests tightened to assert each id's **own** exception class.
- Docs: an ADR-0009 amendment, CLAUDE.md, the roadmap and FORboehpyk.md.

## Non-goals (explicit: hold the line)

- **The nine DBAL id types** (`*IdType` under `Infrastructure/{Identity,Catalog,Listing}/Persistence/Doctrine/Type/`)
  stay as copies. They are duplicated too (62–101 lines each), but extracting them is **not equally
  mechanical**. It needs a `class-string` generic, a dynamic `$class::fromString()`, and either a
  `catch` widened to `\DomainException` or a second generic for the exception. That is a design, not a
  rename. Deferred and **not scheduled**. Revisit only if a type's conversion logic needs a fix that
  would have to be made nine times.
- **No interface** (`UuidIdentifier`, `AggregateId`). Nothing type-hints "any id", and one would
  reopen the type hole.
- **No base class.** That is ADR-0009's type hole.
- **No `generate()`**, no UUIDv7 pinning, no `jsonSerialize()`, no `symfony/uid` in the Domain.
- **No consolidation of the per-id unit tests.** They are each class's own proof of the shape and stay
  where they are.
- **No renamed classes, namespaces, exceptions or messages.** Nothing new in Identity, Catalog or
  Listing apart from the hook method.

## Acceptance criteria (the Definition of Done checklist)

- [ ] **AC-1: one trait.** `src/src/Domain/Shared/ValueObject/UuidIdShape.php` is a `trait` holding
  `FORMAT` (`'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD'`), the private
  constructor (`private readonly string $value`), `fromString`, `equals(self)`, `toString`,
  `__toString` and `abstract private static function malformed(string $value): \DomainException`. It
  imports nothing.
- [ ] **AC-2: eleven users, zero copies.** `grep -rl 'use UuidIdShape;' src/src/Domain | wc -l` is
  **11**, and the list is exactly the table above. `grep -rlE 'function (fromString|equals|toString|__toString)|const string FORMAT|preg_match' <the eleven files>`
  prints **nothing**. ~~`grep -rl "preg_match(self::FORMAT" src/src/Domain` prints only the trait.~~ *Amended
  2026-11-21 at T5 — the grep was wrong, not the code:* it also matches `AttributeCode`,
  `CategorySlug`, `VerificationToken` and `ResetToken`, which have their own non-UUID formats. The
  intended check is that the UUID regex exists once: `grep -rlF '[0-9a-f]{8}-[0-9a-f]{4}' src/src/Domain`
  prints only the trait.
- [ ] **AC-3: public API unchanged.** Each of the eleven is still `final readonly class` with the
  same FQCN. It exposes `fromString(string): self`, `equals(self): bool`, `toString(): string` and
  `__toString(): string`. Its constructor is private. It has no `generate()`. No caller outside the
  eleven files and the tests listed in the task list changes: `git diff main --stat` shows no file
  under `src/src/Application`, `src/src/Infrastructure`, `src/config`, `src/migrations` or
  `src/templates`.
- [ ] **AC-4: each id throws its own exception, with an unchanged message.** For every one of the
  eleven, `fromString('not-a-uuid')` throws exactly `Invalid<Name>Id` from **its own context**, with
  message `"not-a-uuid" is not a valid <noun> id.`. `git diff main -- 'src/src/Domain/*/Exception/'`
  is empty. Pinned by the existing Identity and Catalog per-id tests and by the tightened Listing
  grouped tests (T7).
- [ ] **AC-5: cross-type comparison is still a `TypeError`, for all eleven.** A ring test calls
  `A::fromString(U)->equals(B::fromString(U))` over the eleven classes in a fixed cycle (each id
  against the next, so 11 cases), with the same `U`, and expects `\TypeError` every time. That
  includes the same-named pairs `Catalog\CategoryId` vs `Listing\CategoryId`, `AttributeId` and
  `AttributeOptionId`. The existing `CategoryIdTest`/`AttributeIdTest`/`AttributeOptionIdTest`
  `TypeError` tests and `PasswordResetRequestIdTest`'s reflection test (`'self' === getName()`) pass
  unchanged.
- [ ] **AC-6: the one behaviour change.** `EmailVerificationRequestId::fromString("<uuid>\n")` and
  `PasswordResetRequestId::fromString("<uuid>\n")` now throw their own exceptions, as the other nine
  already do. This is unobservable in production: the only non-test callers are `nextIdentity()`
  (`Uuid::v7()->toRfc4122()`) and the DBAL types reading a PostgreSQL `uuid` column, and neither can
  produce a newline. Pinned by one new `'trailing newline'` provider case in each of the two tests.
- [ ] **AC-7: normalisation and equality unchanged.** Upper-case input is stored lower-case, two ids
  that differ only in hex case are `equals()`, and two different ids are not. Covered by the existing
  per-id tests, which pass unchanged.
- [ ] **AC-8: context isolation holds.** `CatalogIsolationTest`'s pattern becomes
  `(?!Catalog\\\\|Shared\\\\)`, the same as `ListingIsolationTest`'s, and both pass. No Domain or
  Application file in any context imports another context. `Shared` is the only namespace admitted.
- [ ] **AC-9: suite count.** The full suite is green. The test count is the **T0 baseline + 13**
  exactly: +2 newline cases (AC-6) and +11 ring cases (AC-5). No existing test method or provider case
  is deleted. Tightened assertions (T7) do not change the count.
- [ ] **AC-10: gates.** `make check` is green (php-cs-fixer, PHPStan max, Deptrac
  `--fail-on-uncovered`, PHPUnit). The CI reproduction `php -d zend.assertions=-1 vendor/bin/phpunit`
  is also green. Run `make test` twice in a row, and both runs pass.
- [ ] **AC-11: the tests bite.** Each mutation is applied to the trait or one class, the suite is run
  and fails as stated, the mutation is reverted, and the result is recorded in the PR description:
  - **(a)** Drop `D` from the trait's `FORMAT`. The `'trailing newline'`/`"\n"` cases fail in
    **all three contexts** (UserIdTest, the three Catalog tests, both Listing grouped tests, and the
    two new Identity cases).
  - **(b)** Change the trait's `equals(self $other)` to `equals(object $other)`. All 11 ring cases
    fail, plus the three Catalog `TypeError` tests and `PasswordResetRequestIdTest`'s reflection test.
  - **(c)** Make `Listing\SellerId::malformed()` return `InvalidListingId::malformed($value)`. The
    tightened `ListingIdValueObjectsTest` fails, which shows T7 pins every id's own exception and not
    just `\DomainException`.
- [ ] **AC-12: docs.** The ADR-0009 amendment is dated the day it is written and says the trait is extracted,
  why a trait, and that the DBAL types are deferred. CLAUDE.md's status no longer says "Next:
  `uuid-id-trait` (eleven copies)", and its conventions gain the "new id = trait + hook" line and the
  readonly-trait footgun. The roadmap item is ticked. FORboehpyk.md has a story entry.

## Failure contract

| Condition | Expected behaviour |
|---|---|
| Malformed string to any `X::fromString()` (empty, not a UUID, trailing junk, trailing `\n`) | Throws `InvalidX::malformed($value)` from X's own context, with the same message as before. Nothing is constructed. |
| `$a->equals($b)` where `$b` is a different id class | `\TypeError` at the call. PHPStan reports `argument.type` statically. Never a `false`. |
| A corrupted `uuid` row (DBAL type reading) | Unchanged: `ValueNotConvertible`, because each DBAL type still catches its specific `InvalidX`. |
| A twelfth id is written as a copy instead of `use UuidIdShape` | Not caught by a test. Caught by the AC-2 grep at `/verify` and by the CLAUDE.md convention line. Accepted: see the plan's Q5. |
| A future id class forgets `readonly` on the trait property, or the class is not `readonly` | `readonly` on the trait property is fixed once, in the trait. A non-`readonly` class still compiles. Its own unit test is the guard, and the convention line says to copy an existing id. |
