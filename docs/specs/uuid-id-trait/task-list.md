# Task List: uuid-id-trait

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `refactor/uuid-id-trait`. Run `make check` before each commit. Check off as you go.

**Status:** Approved by owner 2026-11-21.

**Rules for the whole slice:**
- **Every commit is green.** PHPStan reports an unused trait, so the trait lands together with its
  first user (T1).
- No file under `src/src/Application`, `src/src/Infrastructure`, `src/config`, `src/migrations` or
  `src/templates` changes. No exception class changes.
- Each id class keeps its FQCN, stays `final readonly`, and keeps its own `Invalid<Name>Id`.
- Listing ids keep their "never import Catalog's / Identity's X" docblock sentence verbatim.

## Prerequisites
- [x] **T0:** On `refactor/uuid-id-trait` (up to date with `main`), run `make test` and record the
  **baseline test count** in the PR description. The last known count is 1746. *(Measured: 1746.)* AC-9 is measured
  against this number.

## Domain
- [x] **T1: the trait + `UserId`.** Create `src/src/Domain/Shared/ValueObject/UuidIdShape.php` (plan
  §Domain: `FORMAT` with `iD`, a `private readonly` promoted constructor, abstract private static
  `malformed()`, `fromString`/`equals`/`toString`/`__toString`). Its docblock carries the shared
  reasoning once: version-agnostic, no `generate()`, why `D`, why a trait and not a base class
  (`self` resolves to the user), and the readonly-trait footgun. Convert `UserId` and trim its
  docblock (plan Q6). `make check`.
- [x] **T2: `EmailVerificationRequestId`, `PasswordResetRequestId`.** Convert both and trim their
  docblocks. **This is the commit that adds `D` to them (plan Q3)**, so say so in the commit message.
- [x] **T3: Catalog's three** (`CategoryId`, `AttributeId`, `AttributeOptionId`), **plus**
  `CatalogIsolationTest`'s lookahead widened to `(?!Catalog\\\\|Shared\\\\)`, with the same comment
  as `ListingIsolationTest`. Without the widening this commit is red.
- [x] **T4: Listing's five** (`ListingId`, `SellerId`, `CategoryId`, `AttributeId`,
  `AttributeOptionId`). Remove the "tenth"/"eleventh copy" wording and keep the cross-context
  sentence.
- [x] **T5: AC-2 grep.** Run both greps from AC-2. Expect 11 users and zero re-implementations.
  Paste the output into the PR.

## Application
_None._

## Infrastructure
_None._ The DBAL types are deferred (plan Q4).

## Tests (qa)
- [x] **T6:** `EmailVerificationRequestIdTest` and `PasswordResetRequestIdTest`: add
  `yield 'trailing newline' => ["018f5b2a-0000-7000-8000-000000000000\n"];`, and correct both
  headers' "knowingly duplicated" wording (plan Q6). **+2 cases.**
- [x] **T7:** `ListingIdValueObjectsTest` and `AttributeIdValueObjectsTest`: the provider yields
  `[class, exceptionClass]` and the malformed test asserts the **exact** exception class. Same case
  count.
- [x] **T8:** `tests/Unit/Domain/Shared/ValueObject/UuidIdShapeTest.php`: an 11-case ring of
  cross-type `equals()` → `\TypeError` (plan §Test plan, with the same-name pairs next to each other).
  **+11 cases.**
- [x] **T9: bite-proof.** Apply AC-11 mutations (a), (b) and (c) one at a time, run the suite, record
  which tests fail, and revert. Then run `make test` **twice** and
  `php -d zend.assertions=-1 vendor/bin/phpunit` once. Confirm the count is baseline + 13.

## Docs
- [x] **T10: ADR-0009 amendment** ("the trait is extracted"), dated the day it is written. Cover:
  `UuidIdShape` in `Shared/ValueObject`; why a trait meets the 2026-07-29 criterion (the ring test is
  the proof the 2026-11-14 amendment asked for); the `malformed()` hook; the `D` tightening on two
  Identity ids; the DBAL types deferred, with their trigger. Also state that ADR-0019's "Shared/ id
  kernel" alternative and ADR-0020/0021's "eleven copies" consequences are discharged. Their text is
  not edited.
- [x] **T11: CLAUDE.md.** Change the status line ("Next: `uuid-id-trait` (eleven copies)" → shipped).
  Add one Conventions line: *a new id VO is `final readonly class X { use UuidIdShape; private static
  function malformed(string $v): InvalidX { … } }` with its own exception; never a copy, never a
  base class.* Add one footgun line: *a `readonly class` cannot use a trait whose property is not
  declared `readonly`.* **Roadmap:** tick `uuid-id-trait` with a one-paragraph "Shipped" note.
  **FORboehpyk.md:** a story entry covering shared code without a shared type, the `self`-in-a-trait
  trick, the two copies that had quietly drifted (no `D`), and why the DBAL types were left alone.

## Verify
- [ ] **T12:** `/verify` → reviewer PASS, AC-1…AC-12 checked, docs updated.
