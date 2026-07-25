# ADR-0005: Authentication — email/password + OAuth2 (Google) for humans, API keys for machines

- **Status:** Accepted
- **Date:** 2026-07-23

## Context

Two human onboarding paths, one machine path, one identity model:

- The PRD wants **1-click social onboarding** (US-1, FR-2) — friction budget is a single overlay, then
  redirect to the intended action.
- We also want a **classic email/password** login so users are **not forced to have (or trust) a
  Google account**, and so onboarding doesn't have a single external point of failure.
- Commercial shops need programmatic **API-key** access to sync inventory (US-4, FR-2, Phase 3).

## Decision

Use **Symfony Security** with three authenticators over one `User` aggregate in the `Identity` context:

- **Humans — email/password:** Symfony form login. Passwords hashed with Symfony's `auto` hasher
  (Argon2id/bcrypt) — **never rolled-by-hand crypto**. **Email verification required** before a
  password account can act (`symfonycasts/verify-email-bundle`); **password reset** via
  `symfonycasts/reset-password-bundle`. Login is **throttled** (Symfony `login_throttling`) against
  brute force.
- **Humans — OAuth2 (Google):** Authorization Code via `knpuniversity/oauth2-client-bundle`. First
  successful login provisions the `User`; a Google-verified email is trusted without a second
  verification step.
- **Account linking:** a `User` is keyed by email and may hold **both** a password *and* linked OAuth
  identities. Signing in with Google for an email that already has a password (or vice versa) **links**
  to the same `User` rather than creating a duplicate.
- **Machines — API keys:** a custom authenticator on `/api/*`. Keys are minted per business user,
  stored **hashed** (never plaintext), scoped to a role, and revocable.

Roles: `ROLE_USER`, `ROLE_BUSINESS`, `ROLE_ADMIN` (+ implicit guest). Guests browse and run faceted
search/maps unauthenticated; any action-oriented task triggers the login/register overlay (which
offers both password and "Continue with Google"). The **intended action is preserved** across
login/registration and OAuth round-trips (deep-link back to Sell Gear / Add Studio / Subscribe).

### DDD framing

Password **hashing and verification are infrastructure** (`PasswordHasherInterface`), not domain
logic. The `User` aggregate holds a `HashedPassword` value object (opaque wrapper) and a collection of
linked `OAuthIdentity` value objects; it enforces invariants like "a usable account has a verified
email or a linked verified OAuth identity." Credential checking crosses the security layer, not the
domain.

## Alternatives

- **OAuth-only (the earlier draft):** rejected — forces a Google account on every user and makes Google
  a single point of onboarding failure.
- **Rolling custom password crypto / session handling:** rejected — we use Symfony's battle-tested
  Security component and the verify-email/reset-password bundles. "Don't roll your own auth" means the
  *crypto and flows*, not the decision to support passwords.
- **A heavyweight IdP (Keycloak):** another container and operational burden on a one-box budget — no.
- **JWT for the API:** viable, but plain hashed API keys are simpler for "shop pastes a key into its
  sync tool" and easier to revoke. Revisit if third parties need delegated scopes.

## Consequences

- **Easy:** users choose their path; no hard dependency on Google; one `User` model serves all three
  authenticators; simple, revocable machine auth.
- **Hard / watched:** password support adds real surface — **we now own** email verification,
  password reset, and brute-force throttling, and verification/reset emails ride the external SMTP
  relay (Constitution §8) and must clear spam filters (PRD validation #3). Account-linking edge cases
  (same email, different methods; case-folding) need explicit tests. Password hashes and API keys are
  security-critical at rest. The email-isolation rule still governs how addresses are **displayed**
  publicly.

### Amendment, 2026-07-26 — the usable-account invariant is modelled now, enforced later

The `identity-user-password-auth` slice implements this ADR's password half. It **models** the
invariant *"a usable account has a verified email or a linked verified OAuth identity"* as
`User::isUsable()`, ships `emailVerifiedAt` in the first migration, and ships `verifyEmail()` with a
real caller (`muzbar:identity:verify-email`). It **does not enforce** the invariant at login: the
firewall installs no `UserCheckerInterface`, so an unverified user can currently authenticate.

This is deliberate and bounded. Email verification — the flow by which a user can actually *become*
verified — is the next slice, `identity-email-verification`. Enforcing the rule before that flow
exists would ship a feature whose happy path only the box operator can reach, which is not a
shippable slice. The alternatives were worse: marking users verified at registration writes a lie
into the data, and deferring the column and the state entirely would cost a migration against
`identity_user` plus a reopening of the aggregate's design.

Enforcement is deferred at **exactly one point**, and it is an Infrastructure one — consistent with
this ADR's own framing that *"credential checking crosses the security layer, not the domain."*
`identity-email-verification` adds a `VerifiedAccountUserChecker` (~15 lines) plus one line of
`security.yaml`. Nothing in `Domain` or `Application` changes.

**Residual risk, accepted:** between these two slices, unverified accounts can log in. Mitigated by
Phase 1 not being publicly launched, by `identity-email-verification` being the very next cycle, and
by the debt being visible in that slice's spec as an explicitly invertible acceptance criterion
(AC-24) rather than buried in code.
