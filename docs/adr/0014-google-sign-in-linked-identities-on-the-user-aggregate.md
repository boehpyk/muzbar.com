# ADR-0014: Google sign-in — linked identities on the User aggregate, and the account-linking rules

- **Status:** Accepted
- **Date:** 2026-11-14
- **Established by** the `identity-google-oauth` slice ([spec](../specs/identity-google-oauth/feature-spec.md)).
- **Refines** [ADR-0005](./0005-auth-oauth2-google-plus-api-keys.md) — see its 2026-11-14 amendment.

## Context

ADR-0005 decided Google sign-in via `knpuniversity/oauth2-client-bundle`, provisioning on first
login, "a Google-verified email is trusted", **linking by email** to an existing `User`, and a
collection of `OAuthIdentity` value objects on `User`. Implementing it surfaced three things that
ADR left open or got subtly wrong:

- **Account pre-hijacking.** Linking unconditionally lets an attacker register `victim@example.com`
  with a password, never verify it, and wait. When the victim clicks "Continue with Google" and is
  linked, the attacker's password opens the victim's account.
- **Where the linked identity lives**, and how it is persisted under ADR-0007.
- **The predicted widening of I-5** (`isEmailVerified() || hasVerifiedOAuthIdentity()`) turns out to
  be a clause that can never decide.

## Decision

1. **Library:** knpu + `league/oauth2-google`, scopes `openid email` only. knpu is a protocol
   client, not a model.
2. **`OAuthIdentity` (provider, subject) is a value object held in a `list<OAuthIdentity>` inside
   `User`**, persisted as one `jsonb` column through a custom DBAL type (`RoleSetType` precedent).
   The rules that govern it — at most one per provider (I-28), and I-29 below — are rules about
   *one user's credentials changing together*, which is what an aggregate boundary is for.
3. **The subject is the identifier; the email is consulted only on a miss.** A recognised subject
   signs in regardless of the asserted email or `email_verified`. The account email is never updated
   from Google.
4. **An email is trusted only when `email_verified === true`**, and only on the link and provision
   paths.
5. **Linking to an unverified account discards its password (I-29).** A password that predates any
   proof of the mailbox does not survive someone else proving it. Linking to a *verified* account
   keeps the password. Discarding is **not** `changePassword()`: nobody chose a new password, so
   `passwordChangedAt` and `UserPasswordChanged` are untouched and no "password changed" mail goes out.
6. **Passwords become optional.** `User::$passwordHash` is `?HashedPassword`. A passwordless user
   fails form login exactly like a wrong password (Symfony's `CheckCredentialsListener` rejects a
   `null` hash). Gaining a password is the existing forgot-password flow, unchanged — that is how
   ADR-0005's "or vice versa" works.
7. **I-5 is not widened.** Every link and every provision happens on a provider-verified address that
   is the account's own address, so both call `verifyEmail()`. The predicted second disjunct could
   never be the deciding one; writing it would be dead code with a security-sounding name.
8. **No Domain port for Google.** The authenticator (Infrastructure) does the protocol and hands plain
   claims to an Application command. Nothing inside calls outward, so a port would have no inside
   caller.
9. **PKCE S256 is required, and comes from knpu's `OAuth2PKCEClient`.** knpu's default
   `OAuth2Client` does no PKCE, and league's `Google` provider has no PKCE option
   (`getPkceMethod()` returns null). knpu's `OAuth2PKCEClient` (selectable only with `type: generic`)
   generates the S256 challenge, keeps the verifier in the session and sends it at the token
   exchange — so there is no PKCE code of ours. A small `GoogleProvider` subclass drops the
   `profile` scope league always adds, so the request is exactly `openid email`. `state` is never
   disabled. No `nonce`: no `id_token` is consumed. *(Corrected 2026-11-14 during implementation:
   the plan assumed we would stash and restore the verifier ourselves.)*
10. **Cross-user uniqueness of `(provider, subject)` is application-guaranteed, not a DB constraint.**
    A subject is only attached after a by-subject lookup missed, and only to the single user owning
    the asserted email (`uniq_identity_user_email` makes that user unique). The one race — two
    first-time callbacks for one subject — resolves to the same user.

## Alternatives

- **An `ExternalLogin` aggregate holding a `UserId`:** rejected. I-28 and I-29 would then span two
  aggregates, needing a saga for what is one user's credential change.
- **A child table `identity_user_oauth_identity` with `UNIQUE(provider, subject)`:** rejected *for
  now* — it needs an entity with an id for what is a value, and buys a uniqueness decision 10 already
  provides. **Trigger to revisit:** a second provider, an unlink use case, or any path that attaches
  a subject without the by-subject-then-by-email lookup.
- **Refuse to link an unverified account** (point at forgot-password): closes the hole with zero
  Domain change, but contradicts ADR-0005's "links", adds friction for the legitimate case, and hides
  the slice's most important rule in a controller message.
- **A random placeholder hash for passwordless users:** writes a lie into the data and makes
  `hasPassword()` unanswerable.

## Consequences

- **Easy:** one `User` serves both doors; the user checker and session handling are shared; adding a
  "Google was linked" notice needs no Domain change (`UserOAuthIdentityLinked` carries
  `passwordDiscarded`).
- **Hard / watched:**
  - `UserRegistered` now fires for an already-verified user; listeners must not assume "unverified"
    (`IssueVerificationOnUserRegistered` returns early on `EmailAlreadyVerified`).
  - `redirect_uri` correctness depends on `trusted_proxies`; only the deploy smoke check catches it.
  - Lookup by subject is a sequential scan with a `jsonb @>` predicate. *Declined:* a GIN
    `jsonb_path_ops` index — add it when `identity_user` passes ~100k rows (Doctrine XML cannot
    express it, so it will need a schema-filter note).
  - Any future **unlink** must re-check I-27 (a user holds a password, an identity, or both).
