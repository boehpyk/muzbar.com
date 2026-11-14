# Task List: identity-login-overlay

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/identity-login-overlay`. Run `make check` before each commit. Check off as you go.

**Approved by the owner 2026-11-14:** D1 = native `<dialog>` + plain JS, no libraries; D2 = stub
`/profile` page as the proving target, with an action-shaped "Profile" trigger as the only overlay
trigger; D8 = ADR-0015 + ADR-0005 amendment, written in T0.

**Rule:** the diff under `src/src/Domain/` and `src/src/Application/` stays empty (AC-26).

## Decisions & prerequisites
- [x] **T0:** `/adr` → **ADR-0015** *"Intended-action redirect: one carrier, one writer, one
      validator; the login overlay is /login in a native dialog"* (D1 incl. the Live Component
      rationale, D3, D4, D5, D6) + dated **ADR-0005 amendment** ("preserved across registration" =
      within the session until the first successful sign-in; cross-device → `/account`; no
      auto-login). No code.

## Domain
- (none — see feature spec *Why there is no Domain code*)

## Application
- (none — no use case; session plumbing owned by Symfony Security)

## Infrastructure
- [x] **T1:** `Infrastructure/Identity/Http/IntendedActionPath.php` — `fromUntrusted(mixed): ?string`,
      `MAX_BYTES = 2048`, the five rules from the plan's *Interface boundary*; docblock names the one
      writer. (AC-9, AC-11, AC-12)
- [x] **T2:** `ProfileController` (`/profile`, `app_profile`, `#[IsGranted('ROLE_USER')]`, no
      variables) + `templates/identity/profile.html.twig` (h1 "Profile", one placeholder sentence) +
      `security.yaml` `access_control` `^/profile`. (AC-1)
- [ ] **T3:** `SecurityController::login()` — on `GET`, `query->all()['_target_path']` →
      `IntendedActionPath` → `saveTargetPath()` with the firewall name from
      `Security::getFirewallConfig()`. No logging. (AC-2, AC-3, AC-6, AC-8, AC-10, AC-15)
- [ ] **T4:** `GoogleAuthenticator::onAuthenticationSuccess()` → `HttpUtils::createRedirectResponse()`
      with a comment naming `form_login` as its twin. (AC-14, AC-16)
- [ ] **T5:** `login.html.twig` — wrap in `section[data-auth-panel][aria-labelledby=auth-panel-title]`,
      `h1#auth-panel-title`, empty `auth_nav` override; `register.html.twig` — empty `auth_nav`
      override. No other markup change. (AC-21, AC-22)
- [ ] **T6:** `base.html.twig` — `{% block auth_nav %}`: guest → `a[data-auth-overlay]` "Profile" to
      `path('app_login', {_target_path: path('app_profile')})`; signed in → plain "Profile" link to
      `path('app_profile')`. (AC-23, AC-24)
- [ ] **T7:** `assets/app.js` — drop the skeleton `console.log`; delegated click handler, modifier
      guard, `fetch` + `DOMParser` + `importNode`, one lazily created `<dialog>` with a
      `method="dialog"` close button, `showModal()`, navigate-on-any-failure; comment on the
      flash-consumption caveat (plan Risk 3). No imports. (AC-25, AC-30)
- [ ] **T8:** Comment corrections — `RegistrationController` (auto-login declined, D6) and
      `config/packages/csrf.yaml` reason 1 (the site now ships JS; still session-backed, and why).

## Tests (qa)
- [ ] **T9:** Unit `tests/Unit/Infrastructure/Identity/Http/IntendedActionPathTest.php` — hostile list
      → `null`, accepted examples → unchanged, 2048/2049, non-strings. No kernel.
- [ ] **T10:** Functional `ProfilePageTest` (AC-1) and `IntendedActionRedirectTest` — `/profile` and
      `/` targets, default unchanged, entry point, newest-wins, consumed-once, failed attempt keeps
      target, hostile list at the writer, non-clobber, array param, length boundary, Symfony's POST
      `_target_path` (browser-style host parse), reader defence (password half), no echo/no log,
      neutral errors compared live, throttle, CSRF. `ClearsRateLimiters`. (AC-1…AC-15, AC-27…AC-29)
- [ ] **T11:** Functional, Google — `/profile` across the round trip, failure-then-retry,
      provisioning lands on `/profile`, reader defence (Google half). One client, `disableReboot()`,
      `state` from the start `Location`. (AC-14, AC-16…AC-18)
- [ ] **T12:** Functional, registrant — register → not authenticated → verify link in the same client
      → still not authenticated → sign in → `/profile`; unverified refusal keeps the target. Fresh
      repository before DB assertions. (AC-19, AC-20)
- [ ] **T13:** Functional, templates & no-JS — panel contract, Google links on `/login` and
      `/register` (closes the google-oauth AC-4 TODO), "Profile" trigger href / suppression /
      signed-in variant, `clickLink('Profile')` → `submitForm` end to end. (AC-21…AC-24)
- [ ] **T14:** Prove the tests bite — identity `fromUntrusted()`, revert T4, drop rule 4, drop rule 5,
      drop both `/profile` guards; a named test fails each time; record in the commit message. Then
      `make test` × 2. (AC-31)

## Docs
- [ ] **T15:** CLAUDE.md — status (Identity 7/7 complete), the one-writer/`HttpUtils`-readers
      footgun, browser TAB/backslash normalisation, 1440 s session idle lifetime, how a future action
      link becomes an overlay trigger. `docs/roadmap.md` — tick, amend "Live Component" → "native
      `<dialog>` + plain JS", close the AC-4 TODO, note `/profile` as a placeholder.
      `FORboehpyk.md` — the chapter (no domain → no Domain code; how open redirects really work; why
      not a Live Component).

## Verify
- [ ] **T16:** Manual JS check AC-25 (a)…(h) on the dev stack in a real browser, results in the
      verify notes; then `/verify` + `/security-review` → reviewer PASS, AC-1…AC-31 checked;
      `git diff main --stat -- src/src/Domain src/src/Application` empty (AC-26); `composer.*` and
      `importmap.php` unchanged (AC-30).
