# Feature Spec: identity-google-oauth

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `identity-google-oauth`, slice **6 of 7** of the `Identity` context
- **Bounded context(s):** `Identity` only. No other context is touched.
- **Related PRD items:** US-1 / FR-2 ("1-click social onboarding"), PRD §3 role-based access,
  Constitution §8 (email isolation, GDPR minimisation)
- **Governing ADRs:** [ADR-0005](../../adr/0005-auth-oauth2-google-plus-api-keys.md), which already
  decides the library, provisioning on first login, "a Google-verified email is trusted", linking by
  email and the `OAuthIdentity` VO collection on `User`;
  [ADR-0007](../../adr/0007-persistence-conventions-for-domain-aggregates.md) (jsonb via a custom
  DBAL type); [ADR-0008](../../adr/0008-domain-events-recorded-on-the-aggregate.md);
  [ADR-0010](../../adr/0010-event-delivery-and-transactional-mail.md) (sync dispatch);
  [ADR-0011](../../adr/0011-password-reset-challenges-modelled-in-the-domain.md) decision 6 (a proof
  of the mailbox from a *channel* verifies the email, and the caller decides that, not `changePassword()`);
  [ADR-0013](../../adr/0013-error-tracking-hosted-sentry-one-capture-path.md) (anything at `error`
  leaves the box)
- **Status:** Draft, awaiting owner sign-off on the decisions in the
  [technical plan](./technical-plan.md) §*Decisions needing sign-off*
- **Date:** 2026-11-13

> **What this slice teaches, beyond the feature.** Slices 1–5 taught one aggregate, a second one
> referenced by identity, the same shape with inverted rules, a legitimate bulk `DELETE`, and a
> consequence with zero Domain code. This slice teaches the thing none of them could: **a
> collection of value objects inside an aggregate, and an invariant that only exists because of
> it.** It also teaches **a credential the aggregate can lose** (a password becomes optional), and
> **an external protocol that never crosses into the Domain.** Google is an adapter's problem. The
> Domain only ever sees "this provider vouches for this subject and this address".
>
> **Two places where this spec refines ADR-0005, deliberately** (ADR-0014 + an ADR-0005 amendment,
> task T0):
> 1. ADR-0005 says Google sign-in for an existing email "**links** to the same `User`",
>    unconditionally. Done literally, that is the classic **account pre-hijacking** hole: an attacker
>    registers `victim@example.com` with a password they know and never verifies it. The victim
>    later clicks "Continue with Google", gets linked, and the attacker's password now opens the
>    victim's account. This spec still links (no duplicate `User`, so ADR-0005 holds), but
>    **linking to an unverified account discards its password** (AC-12, invariant I-29).
> 2. ADR-0005 (and `User::isUsable()`'s docblock) predict widening I-5 to
>    `isEmailVerified() || hasVerifiedOAuthIdentity()`. Under this design every link happens on a
>    Google-verified address that is also the account's own address, so a link always verifies the
>    email and the second clause could **never** be the one that decides. It would be dead code
>    with a security-sounding name. `isUsable()` is **left unchanged** (AC-16) and the amendment says
>    why.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **OAuthIdentity** | Value object: *(provider, subject)*, the stable identifier an external identity provider gives one of its accounts. Equality is on both fields. Carries **no** email, name, token or picture. | A Google *access token* (never stored), the account's `Email`, the Symfony "user identifier" (which stays the email). |
| **OAuthProvider** | Enum of identity providers muzbar accepts. One case today: `Google`. It is a label stored with the subject, so that a later provider's subject can never collide with Google's. It is not a plug-in system. | A "client" in knpu's sense (Infrastructure). |
| **Subject (`sub`)** | Google's immutable account id, a string of ≤ 255 chars. **The only thing a returning Google user is recognised by.** | The Google account's email, which can change and is never used as an identifier after the first link. |
| **Linked identity** | An `OAuthIdentity` held in a `User`'s collection. A `User` holds **at most one per provider** (I-28). | A second `User`. Linking never creates one. |
| **Provider-verified email** | An address the provider asserts it has verified (`email_verified === true` in Google's userinfo). The **only** kind of address this slice will link or create on. | An address muzbar verified by its own link (`EmailVerificationRequest`). Both result in `User::verifyEmail()`. They differ in the channel, not the fact. |
| **Sign in with Google** | The use case: resolve a provider assertion to exactly one `User` by **recognising** it (sub match), **linking** it (email match), or **provisioning** a user (no match). | Login with a password. Different authenticator, same firewall, same user checker. |
| **Provision** | Creating a `User` from a provider assertion via `User::registerWithOAuth()`. The account has **no password** and a verified email. | `User::register()`, the password path. |
| **Passwordless account** | A `User` whose `passwordHash` is `null`. It can sign in with Google, and can gain a password only through the existing forgot-password flow, which proves the mailbox. | An unusable account. A passwordless account is usable. |
| **Unproven password** | The password on an account whose email was never verified. Nobody has shown that whoever set it reads the mailbox. | A forgotten password. |
| **Neutral sign-in failure** | One message, *"Google sign-in did not complete. Please try again or use your email and password."*, shown on `/login` for every failure except the unverified-email case (AC-9). | A 4xx or error page. |

---

## User story

As a **musician who already has a Google account**, I want to **sign in to muzbar with one click,
without inventing another password**, so that **the step between "I want to sell this pedal" and
"I'm listing it" costs me nothing.**

As a **musician who registered with email and password months ago**, I want **"Continue with
Google" to bring me into the account I already have, with my listings**, so that I never end up
with two half-accounts for one address.

As **muzbar**, I want **nobody to get into an account through this door who could not already get
in through the mailbox**, so that adding a convenience does not create a takeover path.

Secondary and ranked (Constitution §2): as the **developer**, I want this slice to show a VO
collection inside an aggregate, an aggregate rule that exists *because* of that collection, and a
vendor protocol kept entirely outside the Domain.

---

## In scope

**Domain (`Identity`)**: the `OAuthIdentity` VO and `OAuthProvider` enum; `User` gains a collection
of linked identities, `registerWithOAuth()`, `linkOAuthIdentity()`, `hasPassword()` and a nullable
password; one new event `UserOAuthIdentityLinked`; one new port method
`UserRepository::findByOAuthIdentity()`; two new exceptions.

**Application**: `SignInWithOAuth` command + handler, which recognise, link or provision.

**Infrastructure**: `knpuniversity/oauth2-client-bundle` + `league/oauth2-google`; a
`GoogleAuthenticator` on the existing `main` firewall; the start route and the callback route; PKCE;
a DBAL type plus one additive migration; "Continue with Google" on `/login` and `/register`; a
one-line fix to `IssueVerificationOnUserRegistered`; two env vars; a deploy smoke check for the
redirect URI.

---

## Non-goals (explicit, hold the line)

| Deliberately excluded | Belongs to |
|---|---|
| Intended-action redirect across the OAuth round trip (ADR-0005), and the login/register **overlay** | `identity-login-overlay` (next slice). This slice only honours Symfony's saved target path, the same as `form_login` does today |
| A "Google was linked to your account" **security notice** mail | follow-up. `UserOAuthIdentityLinked` ships with a full payload and no listener, the same move slice 3 made with `UserPasswordChanged`, which slice 5 then cashed in with zero Domain code |
| **Unlinking** Google, linking a second provider, linking from `/account` while logged in | later. With no unlink, invariant "a usable account keeps at least one credential" has nothing to protect yet |
| Updating the account's email when the Google account's email changes | later (needs an email-change use case of its own) |
| Profile picture, display name, locale, hosted-domain from Google | not required by the PRD. Scopes are `openid email` only (GDPR minimisation) |
| Storing Google access/refresh tokens | never. muzbar calls no Google API after sign-in |
| OpenID Connect `id_token` validation / `nonce` | not applicable. Claims come from the userinfo endpoint over a server-to-server TLS call authenticated by the access token, not from a front-channel token |
| A GIN index on `identity_user.oauth_identities` | added when `identity_user` is large enough to matter (see technical plan) |
| Fixing the existing registration form's "An account with this email already exists" enumeration | not this slice. Recorded in *Risks* |
| Visual design of the button | ADR-0006's pipelines, with the first real layout |

---

## Acceptance criteria (the Definition of Done checklist)

Values pinned: scopes `openid email`, `email_verified` compared with `=== true`, PKCE method `S256`,
subject ≤ 255 chars.

### Starting the flow

- [ ] **AC-1:** `GET /connect/google` answers **302** to `https://accounts.google.com/o/oauth2/v2/auth`
      with `response_type=code`, `scope=openid email` (exactly those two), `client_id` = the
      configured value, a non-empty `state`, `code_challenge_method=S256` and a 43-character
      `code_challenge`. The state and the PKCE verifier are stored in the session, never in a cookie
      or URL of our own.
- [ ] **AC-2:** the `redirect_uri` parameter is the absolute URL of `app_oauth_google_check`
      (`/connect/google/check`). In prod it is `https://muzbar.com/connect/google/check`, asserted
      by the **deploy smoke check** (`curl -sI https://muzbar.com/connect/google` → `location`
      contains `redirect_uri=https%3A%2F%2Fmuzbar.com%2Fconnect%2Fgoogle%2Fcheck`). No functional
      test can catch this, because `KernelBrowser` never goes through `trusted_proxies`.
- [ ] **AC-3:** with `GOOGLE_CLIENT_ID` empty, `GET /connect/google` does **not** redirect to Google.
      It answers 302 to `/login` with a flash *"Google sign-in is temporarily unavailable."* and
      writes **one `error` log record** (→ Sentry) naming the missing configuration. Every kernel
      (app, `messenger-worker`, console, CI, `docker build`) still boots with both vars unset.
- [ ] **AC-4:** "Continue with Google" is a plain link to `/connect/google` on `/login` and on
      `/register`.

### Recognise, link, provision (the callback, `GET /connect/google/check`)

- [ ] **AC-5 (recognise):** a callback whose `(Google, sub)` is already linked to a `User` signs that
      user in. There is no write to `identity_user` and no event. This holds **even if** the asserted
      email differs from the account's email, and **even if** `email_verified` is not `true`.
      The sub is the identifier. The email is consulted only on a miss.
- [ ] **AC-6 (provision):** a callback with an unknown sub and a provider-verified email that no
      account holds creates **exactly one** `identity_user` row: `email` = the normalised address,
      `password_hash` = `NULL`, `roles` = `["ROLE_USER"]`, `email_verified_at` = `registered_at` =
      the `Clock`'s value, `oauth_identities` = `[{"provider":"google","subject":"<sub>"}]`. The user
      is signed in. `UserRegistered` and `UserEmailVerified` are dispatched, in that order.
- [ ] **AC-7 (no verification mail for a provisioned user):** provisioning sends **no** email,
      creates **no** `identity_email_verification_request` row, and writes **no** `error` log record.
      Without the listener fix, every Google sign-up logs an `error` "could not issue an
      email-verification request" and sends a Sentry event.
- [ ] **AC-8 (link to a verified account):** an unknown sub plus a provider-verified email equal to a
      **verified** account's email appends the identity to that account and signs it in. The password
      hash is **unchanged**. `email_verified_at` is unchanged. **No second `User`** exists
      (row count unchanged). One `UserOAuthIdentityLinked` is dispatched with
      `passwordDiscarded = false`.
- [ ] **AC-9 (refuse unverified provider email):** an unknown sub plus `email_verified` ≠ `true`
      (`false`, missing, or the string `"true"`) creates nothing, links nothing, and redirects to
      `/login` showing *"Your Google account's email address is not verified by Google. Verify it
      with Google, or sign up with email and password."*
- [ ] **AC-10 (case-folding):** a Google email `Musician@Example.COM` links to the account
      registered as `musician@example.com` (the `Email` VO normalises it). An asserted email the
      `Email` VO rejects produces the neutral sign-in failure and no write.
- [ ] **AC-11 (one Google account per user, I-28):** if the matched account already holds a
      **different** Google subject, the callback creates and links nothing and shows the neutral
      sign-in failure. Linking the **same** identity twice is a no-op with no event (idempotent).

### The account-takeover rules

- [ ] **AC-12 (pre-hijack defence, I-29):** an unknown sub plus a provider-verified email equal to an
      **unverified password** account's email links the identity, **sets `password_hash` to
      `NULL`**, sets `email_verified_at` to the `Clock`'s value, and signs the user in. Afterwards a
      `/login` POST with the old password fails exactly like a wrong password (AC-14). Events:
      `UserEmailVerified` then `UserOAuthIdentityLinked` with `passwordDiscarded = true`.
      `UserPasswordChanged` is **not** recorded, so no "your password was changed" mail goes out.
- [ ] **AC-13 (login CSRF / state):** a callback whose `state` is missing or differs from the session's
      signs nobody in, creates nothing, and shows the neutral sign-in failure. A callback in a
      **different browser session** from the one that started the flow fails the same way.
      `?error=access_denied` (the user pressed Cancel at Google) fails the same way.
- [ ] **AC-14 (passwordless accounts and form login):** a `/login` POST for a passwordless account,
      with any password, returns the **same** response as a wrong password for a password account
      (asserted against each other, live). The response is never a 500.
- [ ] **AC-15 (adding a password is the forgot-password flow, unchanged):** a passwordless account
      can request a reset, follow the link and set a password. Afterwards both form login and Google
      sign in to the same `User`. No code in the reset flow changes for this.
- [ ] **AC-16 (usable-account invariant):** every `User` produced by provisioning or linking reports
      `isUsable() === true` and passes `VerifiedAccountUserChecker`. `User::isUsable()`'s body is
      unchanged in the diff.
- [ ] **AC-17 (registration form over a Google account):** `POST /register` with the email of a
      Google-provisioned account behaves exactly as for any existing email today. It sets no password
      and links nothing.

### Session and privacy

- [ ] **AC-18:** a successful sign-in migrates the session id (session-fixation defence) and
      redirects to the saved target path if one exists, otherwise to `/account`.
- [ ] **AC-19 (no secret or address in logs):** across every scenario in AC-5…AC-14, no log record's
      message or context contains the asserted email, the subject, the authorization `code`, the
      `state`, the PKCE verifier, or the access token. Asserted with `RecordingLogger` by scanning
      every record for each value.
- [ ] **AC-20 (Sentry):** a provider/HTTP failure during the token or userinfo exchange writes one
      `error` record (→ Sentry, via the existing single path). User-caused failures (AC-9, AC-11,
      AC-13) write at most a `warning` and produce **zero** Sentry events. A Sentry event captured on
      `/connect/google/check` carries no `code` or `state` value (the existing scrubber already drops
      query strings, so this test proves it rather than relying on it).
- [ ] **AC-21:** nothing from Google is stored except the subject. There are no new columns for name,
      picture, tokens or locale, and `oauth_identities` entries carry exactly the keys `provider` and
      `subject`.

### Structure

- [ ] **AC-22:** `Domain/` and `Application/` contain no `League\`, `KnpU\` or `Google` vendor import
      (Deptrac, `--fail-on-uncovered`). There is **no Domain port for Google**.
- [ ] **AC-23:** after the migration, `make migration.make` produces **no** diff.
- [ ] **AC-24:** the suite passes twice in a row (`make test` × 2) with the fake provider, and never
      contacts Google.

---

## Failure contract

| Condition | Expected behaviour |
|---|---|
| User cancels at Google (`?error=access_denied`) | Neutral sign-in failure on `/login`; `warning` log, no Sentry |
| `state` missing / mismatched / different session | Neutral sign-in failure; nothing written; `warning` |
| `code` missing | Neutral sign-in failure; `warning` |
| Token exchange or userinfo call fails (Google down, bad secret, timeout) | Neutral sign-in failure; **one `error` record → Sentry** with the exception class, no claims |
| `email_verified` ≠ `true` on a link/provision path | Specific message (AC-9); nothing written; `info` |
| Asserted email rejected by `Email` VO | Neutral failure; nothing written; `warning` |
| Subject empty or > 255 chars | Neutral failure; `warning` (malformed provider response) |
| Matched account already has a different Google subject | Neutral failure; nothing written; `warning` with the `user_id` only |
| Two first-time callbacks race to provision the same email | The loser hits `uniq_identity_user_email` → `EmailAlreadyRegistered` → neutral failure; retrying links |
| `GOOGLE_CLIENT_ID` empty in prod | AC-3: no redirect to Google; flash; one `error` → Sentry |
| Redis down | Session unavailable → state cannot be checked → neutral failure (same class of outage as password reset; already accepted in CLAUDE.md) |
| Postgres down | Neutral failure; `error` → Sentry (via the handler's exception) |
| Passwordless account at the password form | Identical to a wrong password (AC-14) |
