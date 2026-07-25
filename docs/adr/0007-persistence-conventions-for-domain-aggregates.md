# ADR-0007: Persistence conventions for Domain aggregates

- **Status:** Accepted
- **Date:** 2026-07-26
- **Established by** the `identity-user-password-auth` slice — the first Domain code and the first
  migration in the repository.
- **Implements** [Constitution §4.2](../constitution.md) (Domain imports nothing external) in the one
  place where that rule is hardest to keep: the ORM.

## Context

`identity-user-password-auth` had to persist the first aggregate this codebase has ever had. Every
decision it made about *how* — mapping format, value-object storage, identity generation, table
naming — becomes the default the six remaining bounded contexts (`Catalog`, `Listing`, `Directory`,
`Billing`, `Notification`, `Search`) inherit by imitation. Getting it wrong once is cheap; getting it
wrong and then copying it seven times is a rewrite.

The forcing constraint is Constitution §4.2: `Domain` may import nothing external. Doctrine's default
and best-documented mapping driver is PHP attributes — and an `#[ORM\Entity]` attribute on
`App\Domain\Identity\Entity\User` is a `use Doctrine\ORM\Mapping as ORM;` statement inside the Domain
layer. The most idiomatic Symfony choice is, here, a direct violation of the architecture.

A second pressure came from the Symfony skeleton itself. `config/packages/doctrine.yaml` shipped an
`App` → `%kernel.project_dir%/src/Entity` attribute mapping pointing at a directory containing
nothing but a `.gitignore`. Left in place it is a loaded trap: `make:entity`, every tutorial and every
reflex would put entities in `App\Entity` with ORM attributes on them — a namespace outside all three
hexagonal layers.

## Decision

**1. Mapping is XML, one file per aggregate, living in that context's `Infrastructure` layer.**

`src/Infrastructure/<Context>/Persistence/Doctrine/mapping/<Aggregate>.orm.xml`, declared with
`prefix: 'App\Domain\<Context>\Entity'`. XML is chosen precisely because it is the only driver that
keeps the metadata *physically outside* the class, leaving the aggregate a plain PHP object that some
other persistence mechanism could map instead. `validate_xml_mapping: true` stays on, so a malformed
mapping fails at cache warm-up rather than at runtime.

**2. The skeleton's `App` → `src/Entity` mapping is removed, not merely left unused,** and
`src/Entity/` is deleted. Removing the temptation beats documenting it.

**3. One `doctrine.orm.mappings` block per bounded context,** named for the context.

**4. Value objects map through custom DBAL types, not embeddables.** Each type lives in
`Infrastructure/<Context>/Persistence/Doctrine/Type/` and is registered under `doctrine.dbal.types`
with a `<context>_<concept>` name. Embeddables were considered and rejected: every VO here is a single
value in a single column, so an embeddable demands its own mapping document per one-field object and
still cannot express a single-column unique index cleanly.

**5. Identity is application-assigned UUIDv7, minted by `Repository::nextIdentity()`,** with
`<generator strategy="NONE"/>`. Never a database sequence or auto-increment for aggregate roots.

**6. Tables are named `<context>_<aggregate>`, singular** — `identity_user`, later
`catalog_category`, `listing_listing`. **Every table and column name is spelled out explicitly** in
the XML rather than derived by the naming strategy.

**7. Repository adapters implement the Domain port and do not extend `ServiceEntityRepository`.** The
`EntityManagerInterface` is a collaborator, not a parent. The adapter is also the only place allowed
to translate a database failure into a domain exception — `UniqueConstraintViolationException` becomes
`EmailAlreadyRegistered` inside `save()`.

## Alternatives

- **ORM attributes on Domain classes.** The idiomatic Symfony choice, and the one every tutorial
  teaches. Rejected outright: it is a `use Doctrine\...` in `Domain/`, which Constitution §4.2 forbids
  and Deptrac now fails the build over. This is the single decision most likely to be "fixed" back by
  a future contributor who has not read this ADR.
- **A separate persistence model with hand-written hydration** (Domain entities mapped to plain
  `*Record` classes). Maximum purity, and genuinely used in large systems. Rejected as disproportionate
  for a one-person project: it doubles the class count per aggregate and hand-written hydration is
  exactly the kind of boilerplate that rots. XML gets ~95% of the isolation for ~5% of the cost.
- **YAML mapping.** Also external to the class, but deprecated and removed in Doctrine ORM 3.
- **Embeddables for value objects.** See decision 4.
- **Database-generated identity (`IDENTITY`/sequence).** Simpler on the surface, but the aggregate is
  then invalid until it has met a transaction — it cannot raise an event carrying its own id, and it
  cannot be discarded on a validation failure without a wasted round trip. `identity_generation_
  preferences` is left in the config for future aggregates that genuinely want it.
- **Random UUIDv4 keys.** Rejected on index physics: v4 ids scatter across the primary key's B-tree, so
  every insert dirties a different page, the buffer-cache hit rate collapses as the table grows, and
  page splits leave the index half empty. v7 leads with a millisecond timestamp, so inserts stay on the
  tree's right-hand edge. On a single VDS with finite RAM this is not academic.

## Consequences

- **Easy:** aggregates stay plain PHP and are unit-testable with no kernel and no database. Swapping
  Doctrine out is an Infrastructure job. Deptrac can enforce §4.2 mechanically because there is nothing
  in `Domain/` for it to forgive. Every new context has a template to copy.
- **Hard / watched:**
  - **XML has no IDE autocomplete and no compiler.** A typo is caught at cache warm-up, not by the
    editor. `validate_xml_mapping: true` is load-bearing — do not turn it off.
  - **`make:entity` and `make:migration` reflexes now fight the architecture.** `make:entity` in
    particular will try to recreate `src/Entity`. It is not used in this project.
  - **Doctrine's UnitOfWork compares custom-typed values with `!==`.** Replacing a value object with an
    equal-but-distinct instance marks the entity dirty and emits a harmless UPDATE. Benign because our
    VOs are immutable and only replaced on real change — but it is exactly why a VO must never be
    mutated in place.
  - **Aggregate properties are plain `private`, not `readonly`,** even where nothing reassigns them:
    Doctrine hydrates by reflection and readonly properties still trip its refresh and proxy paths.
    Immutability from outside is guaranteed by the private constructor and the absence of setters.
  - **Two places now know each column's width** (the VO's `MAX_LENGTH` constant and the XML `length`).
    The constant is quoted where possible; where it cannot be, they must be changed together.
