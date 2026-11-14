# Technical Plan: identity-google-oauth

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

## Decisions needing sign-off

Each is recommended. **Bold** marks the ones that refine an accepted ADR and so go into ADR-0014
plus a dated ADR-0005 amendment (T0).

| # | Decision | Recommendation | Alternative considered |
|---|---|---|---|
| 1 | Library | `knpuniversity/oauth2-client-bundle` + `league/oauth2-google`, as ADR-0005 decides. Scopes `openid email` | Hand-rolled over `symfony/http-client`: rejected. ADR-0005 names knpu, and that bundle is a protocol client, not a model (ADR-0005's 2026-07-29 amendment). |
| 2 | **Where a linked Google identity lives** | `OAuthIdentity` **value object**, a `list<OAuthIdentity>` **inside the `User` aggregate**, persisted as one `jsonb` column through a custom DBAL type (the `RoleSetType` precedent, ADR-0007) | (a) Its own aggregate `ExternalLogin` holding a `UserId`: rejected. I-28 and I-29 are rules about *this user's* credentials changing together, which is exactly what an aggregate boundary is for, and ADR-0005 / `User`'s docblock already put it here. (b) A child entity + `identity_user_oauth_identity` table with `UNIQUE(provider, subject)`: rejected for now. It needs an entity with an id for what is a value, and the uniqueness it buys is already guaranteed (decision 3). Revisit when a second provider or unlink ships. |
| 3 | Lookup precedence | **sub first**; the email is consulted only when no user holds the sub. The account email is never updated from Google. | Email first: wrong. Google documents that `sub` is the identifier and that emails change. |
| 4 | When the email is trusted | Only `email_verified === true`, and only on the link/provision paths. A recognised sub signs in regardless (otherwise a Google-side email change locks the user out of an account they demonstrably own). | Require it on every callback: simpler, but locks people out for no security gain. |
| 5 | **Linking to an existing account** | **Verified** account → auto-link (both sides proved the same mailbox; ADR-0005). **Unverified** account → link, **discard the unproven password**, verify the email (I-29). | Refuse and point to forgot-password: zero Domain change, but it contradicts ADR-0005's "links", it adds friction for the legitimate case, and it hides the most interesting rule of the slice in a controller message. **Owner's call. This is the one to argue about.** |
| 6 | **Passwordless accounts** | `User::$passwordHash` becomes `?HashedPassword`; `password_hash` nullable. Form login rejects them generically (Symfony's `CheckCredentialsListener` throws `BadCredentialsException` when `getPassword()` is `null`). Gaining a password = the existing forgot-password flow, untouched. That is how "vice versa" linking (ADR-0005) works. | A random unguessable hash as a placeholder: rejected. It writes a lie into the data and makes `hasPassword()` impossible to answer. |
| 7 | **Google verification discharges email verification; I-5 is not widened** | Provisioning sets `emailVerifiedAt`; linking calls `verifyEmail()`. `isUsable()` stays `isEmailVerified()`. The predicted disjunct can never decide, so adding it would be dead code. | Widen as ADR-0005 predicted: rejected (dead clause, no test could make it the deciding one). |
| 8 | Events | `registerWithOAuth()` records `UserRegistered` + `UserEmailVerified`. `linkOAuthIdentity()` records `UserEmailVerified` (if it verified) + **new** `UserOAuthIdentityLinked(userId, provider, linkedAt, passwordDiscarded)`. No listener this slice. `IssueVerificationOnUserRegistered` gains `catch (EmailAlreadyVerified) { return; }`. | A separate `UserRegisteredWithOAuth` event: rejected. The fact is "a user registered", and the listener should decide whether the fact applies to it. |
| 9 | No Domain port for Google | The authenticator (Infrastructure) does the protocol and hands plain claims to an Application command. Nothing inside calls outward, so a port would have no inside caller (slice 5's lesson). | `IdentityProviderPort` in Domain: rejected, it would have no caller in Domain or Application. |
| 10 | CSRF / PKCE / nonce | knpu's session `state` (on by default, never disabled). **PKCE S256 added by us**, because knpu's `OAuth2Client` does *not* persist a code verifier (checked against its source). About 4 lines: store `$provider->getPkceCode()` in the session at redirect, `setPkceCode()` before the token exchange. No nonce (no `id_token` consumed). | State only: defensible (confidential client + secret), but RFC 9700 recommends PKCE for confidential clients too, and it costs 4 lines. |
| 11 | Config | `%env(default::GOOGLE_CLIENT_ID)%` / `%env(default::GOOGLE_CLIENT_SECRET)%`, never bare (CLAUDE.md footgun, ADR-0010 amendment). Set in `src/.env` (empty), `src/.env.test` (dummy), `.env.example`, box `.env`. The start route turns an empty id into a loud `error` (AC-3) so the fallback is not a silent misconfiguration. | Bare `%env()%`: the knpu client is lazily built, so it *might* only fail on the route, but "might" is how `TRUSTED_PROXIES` took down the worker. |
| 12 | Test seam | Compile-time override in `when@test` of service **`knpu.oauth2.provider.google`** with `App\Tests\Fixture\FakeGoogleProvider` (extends league's `Google`, overrides `getAccessToken()` / `getResourceOwner()` to return scripted claims or throw). knpu's real `OAuth2Client` still runs, so **state checking is exercised**. Tests script claims on the fake through the container, so they need `$client->disableReboot()`. | Faking the whole authenticator or a wrapper class: rejected. It would bypass the state check, which is the security-critical code path. |

## Domain layer (pure PHP)

### Value objects (`Domain/Identity/ValueObject/`)

- **`OAuthProvider`**: backed enum, `case Google = 'google';`.
- **`OAuthIdentity`**: `final readonly`, `of(OAuthProvider $provider, string $subject): self`;
  subject non-empty, ≤ 255 (`MAX_SUBJECT_LENGTH`), **not trimmed or case-folded** (an opaque
  provider id is matched byte-for-byte). Throws `InvalidOAuthIdentity`. `equals()`, `provider()`,
  `subject()`, `isFrom(OAuthProvider)`. No `__toString()`: the subject is a personal identifier and
  shouldn't interpolate into log lines by accident.

### `User` aggregate changes, and how small they are kept

- `private HashedPassword $passwordHash` becomes **`?HashedPassword`**. `passwordHash(): ?HashedPassword`.
  New `hasPassword(): bool`. `register()` still demands a `HashedPassword` (non-null) by type.
- New property `/** @var list<OAuthIdentity> */ private array $oauthIdentities = [];`, declared
  outside the constructor like `passwordChangedAt`, for the same reason (no existing creation path
  supplies it). Reader `oauthIdentities(): array`, `hasOAuthIdentity(OAuthIdentity): bool`.
- **`registerWithOAuth(UserId, Email, OAuthIdentity, \DateTimeImmutable $at): self`** creates the
  user with a `null` password, `[Role::User]`, `emailVerifiedAt = $at` and identities `[$identity]`.
  Records `UserRegistered`, then `UserEmailVerified`. **Precondition, documented in the docblock and
  enforced by the handler:** the provider verified `$email`.
- **`linkOAuthIdentity(OAuthIdentity, \DateTimeImmutable $at): void`**
  1. already holds an equal identity → return, no event (idempotent, like `verifyEmail()`);
  2. holds a *different* identity from the same provider → throw `OAuthProviderAlreadyLinked` (I-28);
  3. if `!isEmailVerified()` → `passwordHash = null`, `$discarded = true` (I-29). **This is the line
     the slice exists for**, and its comment must say why it is not a `changePassword()`. Nobody chose
     a new password, so `passwordChangedAt` and `UserPasswordChanged` are untouched. Any reset link
     outstanding for this address lives in the very mailbox that just proved itself, so I-23 needs
     nothing extra;
  4. append; `verifyEmail($at)` (idempotent, records `UserEmailVerified` only if it changed);
  5. record `UserOAuthIdentityLinked($id, $provider, $at, $discarded)`.
  Same precondition as above.
- `changePassword()` is unchanged and works on a passwordless account (its first password). Its
  docblock's "the one it registered with" wording for `passwordChangedAt` is corrected to cover
  "never had one".
- `isUsable()`: **body unchanged** (decision 7); its docblock's forward-looking paragraph is
  replaced with the reason the widening was declined.

**Invariants** (continuing from I-26):
- **I-27**: a `User` holds a password, a linked identity, or both. *(Every creation path enforces
  it today: `register()` demands a hash, `registerWithOAuth()` demands an identity, and the only way
  to drop a password, I-29, happens in the same call that adds an identity. Stated so that a future
  unlink must re-check it.)*
- **I-28**: at most one linked identity per provider per `User`.
- **I-29**: linking an identity to an account whose email is unverified discards that account's
  password. A password that predates any proof of the mailbox does not survive someone else proving it.
- **(Cross-user, application-guaranteed)**: one `(provider, subject)` belongs to at most one `User`.
  No database constraint enforces it (jsonb). It holds because a subject is only ever attached after
  a by-subject lookup missed, and only to the single user owning the asserted email, and
  `uniq_identity_user_email` makes that user unique. The only race (two first-time callbacks for one
  sub) resolves to the same user. Written down in ADR-0014 with the trigger for moving to a table.

### Domain events

- **`UserOAuthIdentityLinked`** (`UserId $userId`, `OAuthProvider $provider`, `\DateTimeImmutable $linkedAt`,
  `bool $passwordDiscarded`). **No subject, no email** (ADR-0008: payloads are VOs, never secrets.
  The sub is not a secret but no listener needs it). Complete enough that the deferred "Google was
  linked" notice needs no Domain change.
- Reused: `UserRegistered`, `UserEmailVerified`.

### Ports

- `UserRepository::findByOAuthIdentity(OAuthIdentity $identity): ?User`, one method on the existing
  port. **No Google port** (decision 9).

### Exceptions

`InvalidOAuthIdentity`, `OAuthProviderAlreadyLinked`, `OAuthEmailNotVerified` (Domain, thrown by
the handler because the rule "we only trust a provider-verified address" is a domain rule even
though the boolean arrives from outside).

## Application layer

- **Command `SignInWithOAuth`**: `public string $provider` (`OAuthProvider` value), `string $subject`,
  `string $email`, `bool $emailVerified`. Primitives, like every other command here.
- **`SignInWithOAuthHandler::__invoke(SignInWithOAuth): Email`** returns the **account's** email (the
  Symfony user identifier), which is not necessarily the asserted one (AC-5).
  1. `$identity = OAuthIdentity::of(OAuthProvider::from(...), $subject)`
  2. `findByOAuthIdentity($identity)` → found: **return** its email (no save, no events)
  3. `!$emailVerified` → throw `OAuthEmailNotVerified`
  4. `$email = Email::fromString(...)`
  5. `findByEmail($email)` → found: `linkOAuthIdentity($identity, now)`; `save`; dispatch; return
  6. else `User::registerWithOAuth(nextIdentity(), $email, $identity, now)`; `save`
     (`EmailAlreadyRegistered` on a race propagates); dispatch; return
- **Transaction boundary:** one aggregate, one `save()`, no save-order question. Worth saying, since
  the last three slices had one.
- **Idempotency:** a replayed callback is stopped by Google (codes are single-use) before the
  handler. If the handler did run twice, the second call takes path 2 (recognise).

## Infrastructure layer

### Persistence
- `Type/OAuthIdentitySetType extends JsonbType`, name `identity_oauth_identity_set`. Stores
  `[{"provider":"google","subject":"…"}]` and validates on read like `RoleSetType`.
  Registered under `doctrine.dbal.types`.
- `User.orm.xml`: `passwordHash` `nullable="true"`; new field `oauthIdentities`, column
  `oauth_identities`, `nullable="false"`. **No `default` option** in the mapping (see migration).
- `DoctrineUserRepository::findByOAuthIdentity()`: DBAL
  `SELECT id FROM identity_user WHERE oauth_identities @> :needle::jsonb` with the needle encoded
  **by the type** (one encoder), then `findById()`. Seq scan, which is fine at this size.
  *ponytail: no GIN index. Add `USING gin (oauth_identities jsonb_path_ops)` when `identity_user`
  exceeds ~100k rows. Doctrine cannot express it in XML, so it then needs a `schema_filter` or an
  ignored-asset note.*

### Security
- **`Security/GoogleAuthenticator extends KnpU\…\OAuth2Authenticator`**
  - `supports()`: route `app_oauth_google_check`.
  - `authenticate()`: `?error=` → fail. Get the client, restore the PKCE verifier from the session,
    `fetchAccessToken($client)` (knpu checks state here), `fetchUserFromToken()`. Read `getId()`,
    `getEmail()` and `toArray()['email_verified']` (strict `=== true`; `GoogleUser` has no getter for
    it). Call `SignInWithOAuthHandler`. Return
    `new SelfValidatingPassport(new UserBadge($accountEmail->toString()))`, so the existing
    `DomainUserProvider` loads the user and **`VerifiedAccountUserChecker` runs unchanged**.
  - Exception mapping (the only place that knows): `InvalidStateException`, `MissingAuthorizationCodeException`,
    `InvalidOAuthIdentity`, `InvalidEmail`, `OAuthProviderAlreadyLinked`, `EmailAlreadyRegistered` →
    `warning` + neutral `CustomUserMessageAuthenticationException`. `OAuthEmailNotVerified` → `info` +
    the AC-9 message. `IdentityProviderException` / Guzzle exceptions → `error` (Sentry) + neutral.
    Log context: exception class and, where one exists, `user_id`. **Never** email, sub, code, state,
    verifier or token.
  - `onAuthenticationSuccess()`: `TargetPathTrait` → saved path or `app_account`.
  - `onAuthenticationFailure()`: store the exception under
    `SecurityRequestAttributes::AUTHENTICATION_ERROR` in the session and 302 to `app_login`. The
    existing login template already renders `error.messageKey`, so there is **no template logic change**.
- `security.yaml` `main`: `custom_authenticators: [App\…\GoogleAuthenticator]`. `form_login` stays the
  sole entry point (knpu's authenticator is not one). `user_checker` already applies to every
  authenticator on the firewall.
- `SecurityUser::getPassword(): ?string` (the interface allows it). `hasUserChanged()` compares hashes,
  so a passwordless user setting a password via reset ends other sessions, consistent with slice 3.

### HTTP / UI
- `Http/Controller/GoogleOAuthController`:
  - `GET /connect/google` → `app_oauth_google_start`: empty client id → `error` log + flash + 302
    `/login` (AC-3). Otherwise `$client->redirect(['openid', 'email'], [])`, then store
    `$provider->getPkceCode()` in the session under `identity.oauth.google.pkce`.
  - `GET /connect/google/check` → `app_oauth_google_check`: body `throw new \LogicException(...)`,
    intercepted by the authenticator (the `logout()` precedent).
- `templates/identity/login.html.twig`, `register.html.twig`: one `<a href="{{ path('app_oauth_google_start') }}">Continue with Google</a>`.
- GET start is acceptable: a cross-site link can only bounce a visitor to Google's account chooser,
  and the callback is protected by state + PKCE + the session.
- No `Referer` leak of `code`: both callback outcomes are an immediate 302 and the page is never rendered.

### Config
- `config/packages/knpu_oauth2_client.yaml`: `clients.google: { type: google, client_id: '%env(default::GOOGLE_CLIENT_ID)%', client_secret: '%env(default::GOOGLE_CLIENT_SECRET)%', redirect_route: app_oauth_google_check, redirect_params: {} }`,
  plus `provider_options: { pkceMethod: S256 }` (league ≥ 2.7). `when@test`: override the
  `knpu.oauth2.provider.google` service class with `FakeGoogleProvider`.
- `trusted_proxies` is already correct in prod. It is what makes `redirect_uri` `https://muzbar.com`,
  and it is exactly what the `APP_ENV=production` incident silently disabled. Hence the deploy smoke
  check (AC-2) rather than trust.

### Async / schedule
None. Events dispatch synchronously (ADR-0010). There are no listeners besides the existing
`IssueVerificationOnUserRegistered`, which gets the one-line `EmailAlreadyVerified` early return.

### DI wiring
Autowired. `GoogleOAuthController` gets the client id bound from the same `%env(default::GOOGLE_CLIENT_ID)%`.
No port→adapter binding changes (the new method sits on the existing `UserRepository` alias).

## Interface boundary & input contract

| Input | Source | Validation | Rejected → |
|---|---|---|---|
| `state` | query | knpu vs session | neutral failure |
| `code` | query | presence (knpu); redeemed at Google with the PKCE verifier + secret | neutral |
| `error` | query | any value | neutral |
| `sub` | userinfo | `OAuthIdentity` (non-empty, ≤ 255) | neutral |
| `email` | userinfo | `Email` VO (normalise, validate, ≤ 180) | neutral |
| `email_verified` | userinfo | `=== true` only | AC-9 message |

No other claim is read. The Application surface is `SignInWithOAuth` → `Email` or one of the listed
exceptions.

## Data & migrations

One additive migration (`Version2026111…`):
```
ALTER TABLE identity_user ALTER COLUMN password_hash DROP NOT NULL;
ALTER TABLE identity_user ADD oauth_identities JSONB DEFAULT '[]' NOT NULL;  -- backfills existing rows
ALTER TABLE identity_user ALTER COLUMN oauth_identities DROP DEFAULT;        -- mapping has no default → no perpetual diff
```
`down()`: `abortIf` any row has `password_hash IS NULL` (restoring NOT NULL would fail, or would need
data deletion), then the reverse. Backward compatible: existing rows get `[]` and keep their hash.
**`make migration.make` must produce no diff afterwards (AC-23).**

## Test plan

- **Domain unit (no kernel):** `OAuthIdentity` validation/equality/no-trim; `registerWithOAuth`
  state + event order; `linkOAuthIdentity` on verified (hash kept, no discard), on unverified (hash
  null, verified, event flag true, **no** `UserPasswordChanged`), same identity twice (no event),
  different Google subject (throws, state untouched); `changePassword` on a passwordless user;
  `isUsable()` after each.
- **Application integration (DAMA, `FrozenClock`, `SpyDomainEventDispatcher`):** the handler's five
  paths, incl. AC-5 with a changed email and `emailVerified=false`, AC-9's three non-true values
  (`false`, missing → handled at the authenticator, so `false` here), the provision race via
  `ThrowingUserRepository` or a pre-inserted row.
- **Integration (persistence):** `OAuthIdentitySetType` round-trip and corrupt-row rejection;
  `findByOAuthIdentity` hit/miss/other-provider; null `password_hash` round-trip.
- **Functional (`KernelBrowser`, one client per scenario, `disableReboot()`, `ClearsRateLimiters`,
  `RecordingLogger`, `RecordingSentryTransport`):** start redirect params (AC-1), empty client id
  (AC-3, override the bound parameter in a dedicated test kernel env var), each callback path through
  the **real knpu client + `FakeGoogleProvider`** (state taken from the start response's `Location`),
  state mismatch / fresh client (AC-13), `access_denied`, passwordless form login vs wrong password
  live comparison (AC-14), passwordless → forgot-password → both logins work (AC-15), log scan for
  every secret value (AC-19), provider exception → one Sentry event; user-caused → zero (AC-20).
  **Each security test is shown to bite** (remove the I-29 line / disable state / drop the strict
  `=== true` → the test fails), per CLAUDE.md.
- **Factory:** `UserFactory` gains a path through `User::registerWithOAuth()` (never around it).
- **Performance:** n/a (no search). The one seq-scan lookup is noted above.

## Risks / open questions

1. **Decision 5 — RESOLVED 2026-11-14: discard (owner approved option a).** Discard is more faithful to ADR-0005 and
   teaches more. Refuse is zero Domain change. Either closes the pre-hijack hole. Silently doing
   neither (ADR-0005 read literally) is not an option.
2. **A different Google subject for the same email** (AC-11) dead-ends the second Google account
   with a neutral message. That is correct but unhelpful. Rare (Workspace "conflicting accounts").
3. **Password-changed mail wording** says "changed" when a passwordless user sets their first
   password via reset (AC-15). Acceptable, and a template tweak if it reads oddly.
4. **`login_throttling` and the Google route.** Symfony's throttling listener sees every
   authenticator on the firewall. A Google sign-in for an email under a password-guessing lockout
   may be refused for the rest of the window. Acceptable, arguably desirable. Verify at implementation.
5. **knpu's state is not removed after validation** (from its source). Harmless: Google codes are
   single-use, and the session id migrates on success.
6. **Existing enumeration on `/register`** ("An account with this email already exists") predates
   this slice. Not fixed here. It is a separate decision with UX cost.
7. **knpu config key for PKCE** (`provider_options.pkceMethod`) and league version ≥ 2.7 must be
   confirmed at T9. If knpu's provider factory ignores it, construct the provider in a `when@all`
   service override instead.

## Doc updates the implementation must make

- **ADR-0014** *"Google sign-in: linked identities on the User aggregate, and the account-linking
  rules"*: decisions 2–7, 9, 10, the cross-user uniqueness argument and its trigger, the declined
  GIN index.
- **ADR-0005 amendment (dated):** linking to an unverified account discards the password; I-5 not
  widened, and why; knpu used as planned, PKCE added by us.
- **CLAUDE.md:** status line; env vars in Commands; footguns: (a) `redirect_uri` correctness depends
  on `trusted_proxies` and only the deploy smoke check catches it, (b) knpu does not do PKCE,
  (c) a passwordless `SecurityUser` has `getPassword() === null`, (d) the test seam is the
  `knpu.oauth2.provider.google` override + `disableReboot()`, (e) `UserRegistered` now fires for
  already-verified users, so listeners must not assume "unverified".
- **docs/infrastructure.md:** Google Cloud Console runbook (OAuth client, authorised redirect URIs
  for prod `https://muzbar.com/connect/google/check` and dev `http://localhost:8080/connect/google/check`,
  consent screen with `openid email` only), env vars, smoke check.
- **.env.example**, `src/.env`, `src/.env.test`; **deploy.yml** smoke check; **roadmap** tick;
  **FORboehpyk.md** chapter (pre-hijacking as the anecdote; "the identifier is the sub, not the
  email"; the dead clause we declined to write).
