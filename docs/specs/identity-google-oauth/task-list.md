# Task List: identity-google-oauth

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/identity-google-oauth`. Run `make check` before each commit. Check off as you go.

**Approved by the owner 2026-11-14 — decision 5: *discard* (option a).** Linking Google to an
unverified password account discards that password (I-29); T5's step 3 stays.

**Rule:** T1–T7 contain no `use Symfony\`, `use Doctrine\`, `use League\` or `use KnpU\`
(`make deptrac`).

## Decisions & prerequisites
- [x] **T0:** `/adr` → **ADR-0014** *"Google sign-in: linked identities on the User aggregate, and
      the account-linking rules"* + dated **ADR-0005 amendment** (unverified-account linking
      discards the password; I-5 not widened; PKCE added by us). No code.

## Domain
- [x] **T1:** `ValueObject/OAuthProvider` enum + `ValueObject/OAuthIdentity` + `Exception/InvalidOAuthIdentity`.
      (AC-21)
- [x] **T2:** `Event/UserOAuthIdentityLinked` (userId, provider, linkedAt, passwordDiscarded). No sub, no email.
- [x] **T3:** `Exception/OAuthProviderAlreadyLinked`, `Exception/OAuthEmailNotVerified`.
- [x] **T4:** `User`: nullable `passwordHash`, `hasPassword()`, `oauthIdentities` property + readers.
      Docblock fixes on `passwordChangedAt` / `isUsable()` (body unchanged). (AC-16)
- [x] **T5:** `User::registerWithOAuth()` and `User::linkOAuthIdentity()` with I-27/28/29. The I-29
      line carries the *why-not-`changePassword()`* comment. (AC-6, AC-8, AC-11, AC-12)
- [x] **T6:** `UserRepository::findByOAuthIdentity()` on the port.

## Application
- [x] **T7:** `Command/SignInWithOAuth` + `Handler/SignInWithOAuthHandler` (recognise → verified gate
      → link → provision). (AC-5, AC-6, AC-8, AC-9, AC-10)

## Infrastructure
- [x] **T8:** `Type/OAuthIdentitySetType` + `doctrine.dbal.types` registration; `User.orm.xml`
      (nullable hash, `oauth_identities`); `DoctrineUserRepository::findByOAuthIdentity()`.
- [x] **T9:** Migration (drop NOT NULL; add column with default, then drop default; guarded `down()`).
      Run `make migrate` + `make migration.make` → empty diff. (AC-23)
- [x] **T10:** `composer require knpuniversity/oauth2-client-bundle league/oauth2-google`;
      `knpu_oauth2_client.yaml` with `default::` env, `pkceMethod: S256`; env vars in `src/.env`
      (empty), `src/.env.test` (dummy), `.env.example`. Confirm `docker build` + worker boot with
      vars unset. (AC-3 boot half)
- [x] **T11:** `SecurityUser::getPassword(): ?string`. (AC-14)
- [x] **T12:** `GoogleOAuthController` (start with empty-id guard + PKCE stash; check stub). (AC-1, AC-3)
- [x] **T13:** `GoogleAuthenticator` (state via knpu, PKCE restore, strict `email_verified`,
      exception→log-level map, no secrets in context, target-path success, session-error failure);
      `security.yaml` `custom_authenticators`. (AC-5…AC-13, AC-18, AC-19, AC-20)
- [x] **T14:** `IssueVerificationOnUserRegistered`: `catch (EmailAlreadyVerified) { return; }` above
      the `\Throwable` arm, commented. (AC-7)
- [ ] **T15:** "Continue with Google" link on `login.html.twig` and `register.html.twig`. (AC-4)
- [ ] **T16 (devops):** `deploy.yml` smoke check: `location` of `/connect/google` contains the https
      `redirect_uri`. (AC-2)

## Tests (qa)
- [ ] **T17:** `tests/Fixture/FakeGoogleProvider` + `when@test` override of `knpu.oauth2.provider.google`;
      `UserFactory` path through `registerWithOAuth()`.
- [ ] **T18:** Domain unit: `OAuthIdentity`; `registerWithOAuth`; `linkOAuthIdentity` × 4 cases incl.
      no `UserPasswordChanged` on discard; `changePassword` on passwordless. (AC-6, 8, 11, 12, 16)
- [ ] **T19:** Integration: `OAuthIdentitySetType` round-trip/corrupt row; `findByOAuthIdentity`;
      null hash round-trip; handler's five paths + provision race. (AC-5, 6, 8, 9, 10, 11, 21)
- [ ] **T20:** Functional, flow: start params; empty-id guard; recognise/link/provision/pre-hijack
      via real knpu client; no verification mail on provision. (AC-1, 3, 5–12, 16, 18)
- [ ] **T21:** Functional, security: state mismatch, cross-session, `access_denied`; passwordless vs
      wrong password compared live; passwordless → reset → both logins; `/register` over a Google
      account. (AC-13, 14, 15, 17)
- [ ] **T22:** Functional, privacy: log scan for email/sub/code/state/verifier/token; provider
      failure → exactly one Sentry event; user-caused → zero. (AC-19, AC-20)
- [ ] **T23:** Prove the security tests bite: temporarily remove the I-29 line, the strict `=== true`,
      and the PKCE restore, and confirm a test fails each time. Record it in the commit message. `make test` × 2. (AC-24)

## Docs
- [ ] **T24:** CLAUDE.md (status, env vars, five footguns from the plan), docs/infrastructure.md
      Google Console runbook, roadmap tick, FORboehpyk.md chapter.

## Verify
- [ ] **T25:** `/verify` + `/security-review` → reviewer PASS, AC-1…AC-24 checked, docs updated.
      Deptrac confirms AC-22.
