# Technical Plan: uuid-id-trait

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order. This is a mechanical refactor: the order is the trait, then each
> context's ids, then tests, then docs. Every commit stays green.

## Decisions (owner to confirm)

| # | Question | Recommendation | Alternatives considered |
|---|---|---|---|
| **Q1** | **Trait, base class or interface?** | **A trait, and nothing else.** Inside a trait, `self` resolves to the using class, so `equals(self $other)` in `CategoryId` still refuses a `UserId` with a `TypeError` (checked on PHP 8.3: the reflection type name stays `'self'`, so `PasswordResetRequestIdTest`'s reflection test passes unchanged). This meets ADR-0009's 2026-07-29 criterion exactly. It also fits the cross-context rule: each context keeps its **own class** (ADR-0019 d.2), and only the code is shared. **No interface.** Nothing type-hints "any id", and adding one is speculative. | **Abstract base class:** `self` resolves to the base, so a cross-type `equals()` returns `false` instead of failing. That is ADR-0009's type hole. **Base + `instanceof static` check:** recovers the guarantee at runtime only, and PHPStan stops seeing it. **Generic `Uuid` VO wrapped by each id:** eleven wrappers each forwarding four methods, which is more code than today. |
| **Q2** | **How does each id keep its own exception?** | **An abstract private static hook.** The trait declares `abstract private static function malformed(string $value): \DomainException;` and its `fromString()` does `throw self::malformed($value);`. Each class implements it in one line, `return InvalidUserId::malformed($value);`, with the **narrowed** return type `InvalidUserId` (covariance; checked on PHP 8.3). | **One shared `InvalidUuid` exception:** changes the type that the nine DBAL types and the per-id tests catch, which is a behaviour change. **A `private const EXCEPTION = InvalidUserId::class` and `(self::EXCEPTION)::malformed()`:** a dynamic call PHPStan can type only loosely, with no gain over a three-line method. |
| **Q3** | **Rows 2 and 3 (`EmailVerificationRequestId`, `PasswordResetRequestId`) lack the `D` flag. Keep it that way, or tighten?** | **Tighten.** One trait means one `FORMAT`, and the nine-to-two majority is the correct behaviour (a bare `$` matches before a trailing `"\n"`, so `"<uuid>\n"` is accepted and **stored with the newline**). This is unobservable: the only non-test callers are `nextIdentity()` (`Uuid::v7()->toRfc4122()`) and the DBAL types reading a `uuid` column. Pinned by two new provider cases (AC-6). | **Preserve the quirk** with a second trait or an overridable format: that keeps a latent bug in order to stay "behaviour-identical". |
| **Q4** | **Are the nine DBAL id types in scope?** (`UserIdType`, `EmailVerificationRequestIdType`, `PasswordResetRequestIdType`; `Catalog` `CategoryIdType`/`AttributeIdType`/`AttributeOptionIdType`; `Listing` `ListingIdType`/`SellerIdType`/`CategoryIdType`. Listing's `AttributeId`/`AttributeOptionId` have none: values live in the unmapped table.) | **No, deferred, not scheduled.** They are duplicated, but extracting them is **not equally mechanical**. An abstract `UuidIdType extends GuidType` needs a `class-string<T>` template, a dynamic `$class::fromString()`, and either a `catch (\DomainException)` (wider than today's specific catch) or a second template for the exception. That is a design to review, with PHPStan-max generics in Infrastructure. Today the duplication is cheap and nothing in it is changing. Recorded in the ADR-0009 amendment, with the trigger "a fix that would have to be made nine times". | **Do it here:** doubles the diff and mixes a mechanical change with a design change. |
| **Q5** | **Testing: a trait-level test, or rely on the per-id tests?** | **Keep every per-id test unchanged. Add one ring test, plus two cases and one tightening.** The per-id tests (six files plus two Listing grouped tests, covering all eleven) already prove each class's shape, and they pass unchanged. That is the refactor's proof. Gaps they leave: (i) Listing has **no** cross-type test → `Shared/ValueObject/UuidIdShapeTest` runs a ring over all eleven (+11 cases); (ii) rows 2 and 3 have no `"\n"` case (+2); (iii) Listing's grouped tests catch `\DomainException` only, so a hook returning the wrong exception would pass → assert the exact class (no count change). **No "every id uses the trait" test:** it could not find a twelfth *copy* (it would need the list it is checking). AC-2's grep at `/verify` covers it. | **Test the trait through an anonymous test-only class:** tests a twelfth class that production does not have. **Collapse the per-id tests into the ring test:** loses each class's own proof and deletes tests in a refactor that claims "unchanged count". |
| **Q6** | **Docblocks.** Four classes carry ADR-0009's argument in long form (`PasswordResetRequestId`'s runs ~35 lines). | **Trim to what is specific to the class.** That is what it identifies, plus Listing's "Listing's name for another context's id; never import X" sentences, which stay verbatim because they guard ADR-0019 d.2. The shared reasoning (version-agnostic, no `generate()`, why `D`, why a trait and not a base) is written **once**, on the trait. The history is already in ADR-0009's amendments. Stale test docblocks that call the duplication "deliberate" (`PasswordResetRequestIdTest`, `EmailVerificationRequestIdTest` headers) are corrected. | Leave them: they would then describe a duplication that no longer exists. |
| **Q7** | **New ADR?** | **No. A dated amendment to ADR-0009.** The decision ("a trait, because it keeps the type") was already taken in ADR-0009's 2026-11-14 amendment. This slice carries it out and records three things: Q2's hook, Q3's tightening, Q4's deferral. ADR-0019's "Shared/ id kernel" alternative and the ADR-0020/0021 "eleven copies" consequences are discharged **by reference** from that amendment. Their text stays as written. | A new ADR: there is no new decision to record. |
| **Q8** | **Name and location.** | `App\Domain\Shared\ValueObject\UuidIdShape` (a new `ValueObject/` directory beside `Event/` and `Port/`). "The UUID id shape" is the phrase every ADR and docblock already uses. | `Uuid` (reads as a class); `IdentifiedByUuid` (it *is* the id, not identified by one); `Shared/Id/`. |

## Domain layer (pure PHP)

- **Aggregate / entity changes:** none.
- **Value objects:** the eleven id VOs listed in the feature spec. Each becomes:

  ```php
  final readonly class UserId
  {
      use UuidIdShape;

      private static function malformed(string $value): InvalidUserId
      {
          return InvalidUserId::malformed($value);
      }
  }
  ```

  The trait, `src/src/Domain/Shared/ValueObject/UuidIdShape.php`:

  ```php
  trait UuidIdShape
  {
      private const string FORMAT = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

      private function __construct(private readonly string $value) {}

      abstract private static function malformed(string $value): \DomainException;

      public static function fromString(string $value): self { /* preg_match → throw self::malformed → new self(mb_strtolower) */ }
      public function equals(self $other): bool { return $this->value === $other->value; }
      public function toString(): string { return $this->value; }
      public function __toString(): string { return $this->value; }
  }
  ```

  **Footgun (checked on PHP 8.3, the same rule applies on 8.4):** a `readonly class` **cannot use a
  trait whose property is not explicitly `readonly`**. The error is `Fatal error: Readonly class A
  cannot use trait with a non-readonly property`. The promoted property must say `private readonly`.
  Constants in traits need PHP ≥ 8.2, and abstract private methods in traits need ≥ 8.0. Both hold.
- **Domain events:** none. **Ports:** none.

## Application layer

None. No command, handler or caller changes. The public API is identical (AC-3).

## Infrastructure layer

None. The nine DBAL types still call `X::fromString()` and catch `InvalidX` (Q4). No mapping, DI,
config, controller or template changes.

## Interface boundary & input contract

Unchanged per class: `fromString(string): static-type`, accepting
`/^[0-9a-f]{8}-…-[0-9a-f]{12}$/iD` (any version, any hex case) and throwing that class's own
`Invalid<Name>Id` otherwise; `equals(self)`; `toString()`; `__toString()`. The **only** delta is
Q3: rows 2 and 3 now also refuse a trailing `"\n"`.

## Data & migrations

None. No DDL. `make migration.make` must still produce no diff.

## Test plan

- **Domain unit (existing, unchanged, all must pass):** `UserIdTest`,
  `EmailVerificationRequestIdTest`, `PasswordResetRequestIdTest` (including its reflection test),
  `Catalog/{CategoryId,AttributeId,AttributeOptionId}Test` (including their `TypeError` tests),
  `Listing/{ListingIdValueObjects,AttributeIdValueObjects}Test`.
- **Domain unit (new or changed):**
  - `tests/Unit/Domain/Shared/ValueObject/UuidIdShapeTest.php`: a data provider yields
    `[A::class, B::class]` for the eleven in a fixed cycle (row *n* against row *n+1*, row 11 against
    row 1). It asserts `\TypeError` on `A::fromString(U)->equals(B::fromString(U))`, using
    `@phpstan-ignore argument.type` as `CategoryIdTest` does. Put the same-name pairs next to each other
    in the cycle (Catalog `CategoryId` → Listing `CategoryId`, and the same for `AttributeId` and
    `AttributeOptionId`) so the most plausible confusions are among the 11 cases.
  - `EmailVerificationRequestIdTest`/`PasswordResetRequestIdTest`: add `yield 'trailing newline'` to
    `malformedIdProvider()`.
  - `ListingIdValueObjectsTest`/`AttributeIdValueObjectsTest`: the provider yields
    `[class, exceptionClass]`, and `testRejectsMalformedValues` asserts
    `$e instanceof $exceptionClass` (or `assertSame($exceptionClass, $e::class)`) instead of only
    catching `\DomainException`. Do not put the assertion inside `assert()`.
  - `CatalogIsolationTest`: widen the negative lookahead to `(?!Catalog\\\\|Shared\\\\)`, with the
    same comment as Listing's.
- **Application/Feature:** none new. The whole suite is the regression net: every handler, repository
  and HTTP test builds these ids.
- **Bite-proof:** AC-11 (a)–(c), run by hand and recorded in the PR. Three mutations, one each for
  format, type and exception, which matches the three things that could silently change.

## Risks / open questions

1. **PHPStan on an abstract private static in a trait.** If PHPStan max reports the hook as unused
   (`method.unused`) or cannot resolve `self::malformed()`, first confirm it is a false positive, then
   make the hook `protected` in both trait and classes. A `final` class makes `protected` equivalent
   in practice. Do not add an ignore without that check.
2. **php-cs-fixer** (`@Symfony`) orders `use` trait statements first in the class body. Let it.
3. **Deptrac** sees `App\Domain\Shared\ValueObject\UuidIdShape` as Domain → Domain, which is allowed.
   `\DomainException` is `php_internal`. No config change is expected. If `--fail-on-uncovered`
   complains about the trait, check the collector before touching `deptrac.yaml`.
4. **Identity has no isolation test.** None is added: out of scope, and Identity Domain imports
   nothing cross-context today. Noted only so nobody assumes one exists.
5. **A twelfth copy** can still be written by hand. The guard is a convention line in CLAUDE.md plus
   the AC-2 grep. A test that enumerates the users cannot find a class it does not know about (Q5).
