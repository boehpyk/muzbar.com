# Feature Spec: identity-login-overlay

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `identity-login-overlay`, slice **7 of 7** (the last) of the `Identity` context
- **Bounded context(s):** `Identity` only, and **only its Infrastructure layer**. No Domain or
  Application code changes (see *Why there is no Domain code*).
- **Related PRD items:** PRD §6 *Entry Points* ("a 1-click social authentication overlay … redirected
  cleanly to their intended action"), US-1 / FR-2, Constitution §8 (untrusted input validated at the
  boundary)
- **Governing ADRs:** [ADR-0005](../../adr/0005-auth-oauth2-google-plus-api-keys.md) ("the intended
  action is preserved across login/registration and OAuth round-trips"), which this slice refines for
  the registration case; [ADR-0014](../../adr/0014-google-sign-in-linked-identities-on-the-user-aggregate.md)
  (the Google round trip, state + PKCE in the session); [ADR-0001](../../adr/0001-framework-symfony.md)
  (Symfony UX is *available*, not mandated per widget); [ADR-0006](../../adr/0006-dual-styling-admin-public.md)
  (AssetMapper, no Node). New: **ADR-0015** + an ADR-0005 amendment (task T0, approved).
- **Status:** **Approved** by the owner 2026-11-14 — D1 = native `<dialog>` + plain JS, no libraries;
  D2 = a stub `/profile` page as the proving target and the action-shaped trigger; D8 (ADR-0015 +
  ADR-0005 amendment) approved as planned. See the [technical plan](./technical-plan.md).
- **Date:** 2026-11-14

> **What this slice teaches, beyond the feature.** Six slices added Domain code, or deliberately
> added none because a complete event made a consequence Infrastructure-only. This one adds none for
> a different reason: **the thing being built is not a business fact.** "Where was this visitor
> trying to go?" is navigation state of one browser session. No aggregate owns it, no invariant
> constrains it, nothing persists it past the session, and nobody but this browser will ever ask.
> Inventing an `IntendedAction` value object would put HTTP in the Domain wearing a DDD costume.
>
> What the slice *does* teach is a security lesson: **an open redirect is a trust-boundary bug, and
> the fix is to have exactly one place where untrusted input becomes a redirect target.** Symfony
> already has a carrier for "where to go after login" (the firewall's session target path) and both
> authenticators already read it. This slice adds **one writer** for it, puts **one validator** in
> front of that writer, and does not add a second carrier.

---

## Why there is no Domain code (the honest DDD answer)

| Candidate | Why it is not Domain |
|---|---|
| `IntendedAction` / `TargetPath` value object | Its only rule is "safe to put in a `Location` header on our origin" — a property of URLs and browsers, not of musicians or accounts. It would carry no Identity vocabulary, and its only callers are a controller and an authenticator. It lives in Infrastructure as a pure, unit-testable class. |
| An `AuthenticationIntent` aggregate | There is no invariant to protect and nothing to persist. An aggregate with no rule is a table with a costume. |
| A domain event (`UserSignedIn`) | Nobody reacts to it. Logging-in is Symfony Security's business (ADR-0005: credential checking crosses the security layer, not the domain). |
| A change to `User` / `isUsable()` (I-5) | None. The unverified registrant case is answered by **not** signing them in, which `VerifiedAccountUserChecker` already enforces. |
| A `Profile` aggregate for the new `/profile` page | The page is a placeholder with no data. A profile model arrives with the slice that gives it fields and rules, not before. |

The diff under `src/src/Domain/` and `src/src/Application/` is **empty** (AC-26).

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Intended action** | The same-origin page a guest was trying to reach when they were asked to sign in. In this slice: `/profile`. Later: "Sell Gear", "Add Studio", "Subscribe to Search". | A route name, a command, or anything stored in the database. |
| **Target path** | Symfony's per-firewall session attribute (`_security.main.target_path`, read/written through `TargetPathTrait`). **The one carrier** of the intended action in this codebase. Consumed (removed) by the first successful sign-in. | The `_target_path` *request parameter*, which is only an input to the one writer (and, separately, a Symfony form_login feature this slice does not use but does test). |
| **Same-origin path** | A string that, placed in a `Location` header, cannot leave muzbar.com in any browser: starts with exactly one `/`, contains no backslash, whitespace or control character, and its percent-decoded, browser-normalised form does not start with `//`. Validated by `IntendedActionPath`. | A URL. Absolute URLs are rejected, even our own. |
| **Profile page** | `/profile` (`app_profile`): a protected placeholder page, the slice's proving target and the first action-shaped destination. Empty by design until a later slice gives it content. | `/account`, which stays the default post-login landing page (`default_target_path`). |
| **Login overlay** | The `/login` page's **auth panel**, fetched and shown in a native modal `<dialog>` when JavaScript is available. It is not a second login implementation: same form, same POST, same firewall. | A Live Component (see technical plan D1), a second login endpoint. |
| **Auth panel** | The element on `/login` marked `data-auth-panel`: the password form, "Continue with Google", and the register / forgot-password / resend links. | The whole `/login` page (which also has the layout, flashes and title). |
| **Overlay trigger** | A link marked `data-auth-overlay` whose `href` is `/login?_target_path=<intended action>`. Without JS it is an ordinary link to the full login page; with JS it opens the overlay. This slice has exactly one: the layout's "Profile" link for guests. | A button that only works with JS. |

---

## User story

As a **guest browsing muzbar**, when I click something that needs an account (today: "Profile";
later: "Sell Gear"), I want **a sign-in box over the page I am on, with "Continue with Google" as
the one-click option**, and **after signing in I want to land on the thing I clicked**, not on a
generic account page.

As a **guest who has no account yet and registers with a password**, I accept that I must confirm my
email first; I want **the site to still take me to what I was doing** when I sign in after confirming,
as long as I am in the same browser.

As **muzbar**, I want **no link anyone can craft to turn our login page into a redirect to somebody
else's site**, and I want the overlay to change *nothing* about CSRF, throttling or the neutral error
messages.

Secondary and ranked (Constitution §2): as the **developer**, I want to see a slice where the honest
answer is "this is not Domain", and to learn how open redirects actually work (protocol-relative
URLs, backslashes, browsers stripping tabs) instead of trusting a regex someone pasted.

---

## In scope

- **The proving target:** a stub `/profile` page (`app_profile`, `ROLE_USER`), placeholder template,
  guarded like `/account` (`#[IsGranted]` + an `access_control` rule).
- **One writer of the target path:** `GET /login?_target_path=<path>` validates the value with
  `IntendedActionPath` and, if valid, stores it as the firewall's target path. Invalid values are
  ignored silently.
- **One validator:** `IntendedActionPath`, a pure Infrastructure class with a table-driven unit test.
- **Google redirect hardening:** `GoogleAuthenticator::onAuthenticationSuccess()` builds its redirect
  through Symfony's `HttpUtils::createRedirectResponse()`, exactly as `form_login` does, so both
  authenticators treat the target path identically.
- **The auth panel:** `login.html.twig` wraps its existing content in `data-auth-panel`; no field,
  token id, action or message changes.
- **One overlay trigger, action-shaped:** a "Profile" link in `base.html.twig`. For a guest it is an
  overlay trigger to `/login?_target_path=/profile`; for a signed-in user it is a plain link to
  `/profile`. Not rendered on `/login` or `/register`. There is **no** generic "Sign in → back to
  this page" trigger.
- **The overlay enhancement:** a small plain-JS module in `assets/app.js`, no libraries: on a trigger
  click, fetch the trigger's `href`, lift `[data-auth-panel]` into a `<dialog>`, `showModal()`; on
  any failure, navigate to the `href` instead.
- **The unverified registrant's answer:** the target path survives registration and the email round
  trip **in the same browser session** and is honoured at the first successful sign-in. No
  auto-login. The misleading comment in `RegistrationController` that promised auto-login to this
  slice is corrected.
- **Carry-over from `identity-google-oauth`:** the missing AC-4 test ("Continue with Google" links
  on `/login` and `/register`).

---

## Non-goals (explicit, hold the line)

| Deliberately excluded | Why / where it belongs |
|---|---|
| **Profile content** — any field, form, edit action, or `User` change behind `/profile` | A later slice. `/profile` exists only to be an intended action that is not the default landing page. |
| **Building "Sell Gear", "Add Studio", "Subscribe to Search"** | Phase 2/3. Their links later become overlay triggers by using the same `href` shape and attribute as "Profile". |
| A generic **"Sign in → back to the current page"** trigger | The action-shaped "Profile" trigger already gives the overlay a real caller (AC-25), and the plain `/login` link from the forms stays. Add one when a page actually needs it. |
| **Auto-login after registration or after clicking the verification link** | I-5: an unverified account gets no session. Signing in by clicking the verification link would make that link a magic-login credential — and ADR-0009 deliberately makes it *replayable* (a replay is absorbed), so it would be a reusable login token sitting in a mailbox. |
| **Carrying the intended action in the verification (or any) email** | A navigation value has no place in a security mail, and verification may happen on another device where there is no session to resume. The cross-device registrant lands on `/account`. |
| **Registration inside the overlay** | A password registrant cannot be signed in until verified, so an in-dialog registration would end at "check your inbox" anyway. The overlay links to the full `/register` page (target already in session). Google is the 1-click registration path and *is* in the overlay. |
| **Keeping a failed password attempt inside the dialog** | A failed POST shows the full `/login` page with the existing error (target preserved). Re-rendering inside the dialog would need Turbo or hand-rolled form submission; not worth it for the first version. |
| A Symfony UX **Live Component**, Stimulus or Turbo for the overlay | Owner decision D1: plain JS, no libraries. The reason a Live Component is the wrong tool for a login form is kept in the technical plan as teaching material. |
| Switching to stateless CSRF now that the site ships JS | `csrf.yaml`'s revisit note stays open; the fetched panel carries a session-backed token that works today. Its comment is updated to say JS now exists. |
| A dedicated fragment endpoint (`/login/panel`) or `X-Requested-With` branching | The panel is lifted out of the normal `/login` response. One page, one template, one set of tests. |
| Redirecting an already-authenticated visitor away from `/login` | Pre-existing behaviour, unrelated. |
| Logging rejected `_target_path` values | They are attacker-controlled text and may contain anything (including someone else's reset URL). A rejection is a visitor-caused non-event. |
| Visual design of the dialog, the header link or `/profile` | ADR-0006's public SCSS arrives with the first real layout; the dialog uses user-agent styles. |
| A JS test harness | No Node (ADR-0006). The JS is verified manually during `/verify` (AC-25); every server-side behaviour it depends on is covered by PHPUnit. |

---

## Acceptance criteria (the Definition of Done checklist)

Pinned values: target length limit **2048 bytes**; parameter name **`_target_path`**; default target
**`/account`**; proving target **`/profile`**; session attribute **`_security.main.target_path`**. In
tests the origin is `http://localhost`. "Signs in" means a `POST /login` with a verified user's
correct credentials and a valid CSRF token, unless stated otherwise. All scenarios run in **one**
`KernelBrowser`.

### The proving target

- [ ] **AC-1 (`/profile` exists and is protected):** anonymous `GET /profile` → 302 to `/login`; a
      signed-in user's `GET /profile` → **200** with an `h1` reading "Profile". The page renders no
      email, role or other account data. `default_target_path` is still `/account`.

### The intended-action redirect (form login)

- [ ] **AC-2 (protected target):** `GET /login?_target_path=/profile` answers **200**; signing in
      then answers **302** with `Location: http://localhost/profile`.
- [ ] **AC-3 (public target, with query):** `GET /login?_target_path=/?from=overlay` → sign in →
      `Location: http://localhost/?from=overlay` (any valid same-origin path works, query included).
- [ ] **AC-4 (no target, unchanged):** signing in with no target ever supplied →
      `Location: http://localhost/account`.
- [ ] **AC-5 (entry-point path, the no-JS fallback for every future action route):** anonymous
      `GET /profile` → 302 to `/login`; signing in → `Location: http://localhost/profile`. (Existing
      Symfony behaviour, pinned because every future action route relies on it.)
- [ ] **AC-6 (newest intent wins):** `GET /login?_target_path=/`, then
      `GET /login?_target_path=/profile`, then sign in → `…/profile`.
- [ ] **AC-7 (consumed once):** after AC-2's successful sign-in, a **second** successful sign-in in
      the same session, without logging out (`GET /login`, then `POST /login` again) →
      `Location: http://localhost/account` (no stale target).
- [ ] **AC-8 (failed attempt keeps the target):** `GET /login?_target_path=/profile`, one
      `POST /login` with a wrong password (existing "Invalid credentials." page), then a correct one →
      `…/profile`.

### Open-redirect prevention

The **hostile list** (each value URL-encoded into the query string as a whole):
`//evil.example`, `///evil.example`, `/\evil.example`, `\/evil.example`, `\\evil.example`,
`https://evil.example/`, `http://localhost/profile` (absolute, even our own), `http:evil.example`,
`javascript:alert(1)`, `data:text/html,x`, `evil.example`, `profile` (no leading slash), `` (empty),
` /profile` (leading space), `/\t/evil.example` (a literal TAB), `/\n/evil.example`, `/%2F/evil.example`,
`/%2f%2fevil.example`, `/%5Cevil.example`, `/%09/evil.example`, `/%252F%252Fevil.example`, and a
`/` followed by 2048 `a` characters (2049 bytes).

- [ ] **AC-9 (rejected at the writer):** for **every** hostile value, `GET /login?_target_path=<v>`
      answers **200** with the normal login page (never 4xx/5xx), and signing in afterwards answers
      exactly `Location: http://localhost/account`.
- [ ] **AC-10 (a hostile value does not clobber a good one):** `GET /login?_target_path=/profile`,
      then `GET /login?_target_path=//evil.example`, then sign in → `…/profile`.
- [ ] **AC-11 (non-string parameter):** `GET /login?_target_path[]=/profile` and
      `GET /login?_target_path[x]=/profile` answer **200** and are ignored (sign-in → `/account`).
- [ ] **AC-12 (length boundary):** a valid path of exactly **2048 bytes** (`/profile?pad=` padded with
      `a`) is honoured; 2049 bytes is ignored.
- [ ] **AC-13 (Symfony's own POST parameter is safe too):** for every hostile value, a successful
      `POST /login` carrying `_target_path=<v>` **in the form body** answers 302 with a `Location`
      whose host is `localhost` **as a browser would parse it**: strip every TAB/CR/LF and turn every
      `\` into `/` (what the WHATWG URL parser does for `http(s)`), then `parse_url(…, PHP_URL_HOST)`
      must be `localhost`. (This is `form_login`'s built-in `target_path_parameter`, which the
      overlay does not use; asserted so the second, unused door is proven shut rather than assumed
      shut.)
- [ ] **AC-14 (defence in depth at the readers):** if the session's target path holds a foreign
      absolute URL (`https://evil.example/x`, written straight into the test session to simulate a
      future careless writer), then **both** a successful password sign-in **and** a successful
      Google callback answer with a `Location` on `localhost` — never `evil.example`.
- [ ] **AC-15 (no echo, no log):** across AC-9…AC-13, no log record's message or context contains
      any hostile value or the substring `evil.example` (`RecordingLogger` scan), and no response
      body contains `evil.example`. *Amended at `/verify`:* the scan covers every `app`-channel record
      and every record at `warning`+ on any channel; framework records below `warning` are exempt,
      because the `request` channel's "Matched route" line carries `request_uri` by design (the
      pre-existing footgun in CLAUDE.md) and is not this slice's code. The AC as first written could
      not be met; the test won, on purpose.

### The Google round trip

- [ ] **AC-16:** `GET /login?_target_path=/profile`, `GET /connect/google`, then a successful
      scripted callback (`DrivesGoogleOAuth`) → `Location: http://localhost/profile`. State and PKCE
      checks run unchanged (the existing Google security tests stay green unmodified).
- [ ] **AC-17:** the same, but the first callback fails (`?error=access_denied`) → `/login` with the
      neutral Google failure message; a second full round trip that succeeds → `…/profile`.
- [ ] **AC-18:** a Google sign-in that **provisions** a new account (unknown sub, verified email)
      also lands on `/profile` — the 1-click registration path honours the intended action.

### The unverified registrant

- [ ] **AC-19:** `GET /login?_target_path=/profile`, then `POST /register` (valid) → 302 to
      `/verify-email/sent`; the visitor is **not** authenticated (the session holds no
      `_security_main` token — *amended at `/verify`:* the originally written probe, `GET /profile`
      → 302 `/login`, would itself overwrite the stashed target via the entry point, so the AC as
      written could not pass; the test won, on purpose).
      Following the verification link from the recorded mail → 302 to `/login`, and the visitor is
      **still not** authenticated. Signing in → `Location: http://localhost/profile`.
- [ ] **AC-20:** an unverified account's correct-password sign-in attempt with `/profile` stashed is
      refused with the existing *"Please verify your email address before signing in."* and does
      **not** consume the target (after verifying, sign-in → `/profile`).

### The auth panel, the trigger and the no-JS path

- [ ] **AC-21 (panel contract):** the `GET /login` response contains **exactly one**
      `[data-auth-panel]`, with `aria-labelledby` naming an element inside it, and that panel
      contains: a `form[method=post][action="/login"]` with inputs `_username`, `_password` and
      `_csrf_token`; an `a[href="/connect/google"]`; and links to `/register`, `/forgot-password`
      and `/verify-email/resend`. The panel's links carry **no** `_target_path`.
- [ ] **AC-22 (carry-over, `identity-google-oauth` AC-4):** "Continue with Google" is an
      `a[href="/connect/google"]` on both `/login` and `/register`.
- [ ] **AC-23 (layout trigger):** for a guest, `GET /forgot-password` contains exactly one
      `a[data-auth-overlay]`, with link text "Profile", whose `href` has path `/login` and, after
      `parse_str()` of its query, `_target_path === '/profile'` (asserted on the decoded value, not
      on a particular percent-encoding). `/login` and `/register` contain **no**
      `a[data-auth-overlay]`. For a signed-in user, `/account` contains **no** `a[data-auth-overlay]`
      and one layout link `a[href="/profile"]`.
- [ ] **AC-24 (no-JS end to end):** guest `GET /forgot-password`, `clickLink('Profile')`, sign in
      via the rendered form (`submitForm`) → `Location: http://localhost/profile`.
- [ ] **AC-25 (JS, manual — recorded in the `/verify` notes):** in a real browser with JS:
      (a) clicking "Profile" as a guest opens a modal `<dialog>` holding the auth panel, with focus
      on the email field and the page behind it inert; (b) Esc and the close button close it with no
      navigation; (c) signing in from the dialog lands on `/profile`; (d) a wrong password shows the
      full `/login` page with "Invalid credentials." and the next correct sign-in still lands on
      `/profile`; (e) "Continue with Google" in the dialog is a top-level navigation; (f) with the
      network request for `/login` blocked, the click navigates to the full `/login` page instead of
      doing nothing; (g) with JS disabled, (c) still works via AC-24's path; (h) a modifier-click
      (Ctrl/Cmd/middle) opens `/login?_target_path=/profile` in a new tab as an ordinary link.

### Preserved behaviour and structure

- [ ] **AC-26 (no Domain or Application change):** `git diff main --stat -- src/src/Domain src/src/Application`
      is empty.
- [ ] **AC-27 (neutral errors unchanged):** with `/profile` stashed, a wrong password for a real
      account and any password for an unknown email produce **byte-identical** response bodies
      (asserted against each other, live, after normalising the echoed email and the re-issued CSRF
      token — the only two values that legitimately differ); every existing login, throttling and Google test passes
      **unmodified**.
- [ ] **AC-28 (throttling unchanged):** with `/profile` stashed, the 6th failed `POST /login` for one
      username in 15 minutes is refused by the throttle exactly as in `ThrottlingTest`.
- [ ] **AC-29 (CSRF unchanged):** a `POST /login` with a missing or wrong `_csrf_token` and
      `/profile` stashed does not authenticate (`GET /account` → 302 `/login`) and does not consume
      the target.
- [ ] **AC-30 (no libraries):** `composer.json`, `composer.lock` and `importmap.php` are unchanged in
      the diff; `assets/app.js` imports nothing but its stylesheet.
- [ ] **AC-31:** `make check` green; `make test` passes **twice in a row**.

---

## Failure contract

| Condition | Expected behaviour |
|---|---|
| `_target_path` absent | Nothing written; any existing target path untouched |
| `_target_path` hostile, malformed, non-string, too long | Ignored silently: page renders 200, existing target untouched, nothing logged, nothing echoed (AC-9…AC-12, AC-15) |
| Target path holds an off-site URL anyway (future bug) | Both authenticators redirect via `HttpUtils::createRedirectResponse()`, which absolutises relative paths onto our host and resets a foreign host to `/` (AC-14) |
| Failed password / throttled / bad CSRF | Existing behaviour, unchanged; target kept for the next attempt (AC-8, AC-28, AC-29) |
| Unverified account signs in with the right password | Existing refusal message; target kept until a successful sign-in (AC-20) |
| Google cancelled / state mismatch / provider down | Existing neutral failure on `/login`; target kept (AC-17) |
| Session expired between stash and sign-in (PHP's `session.gc_maxlifetime` idle lifetime, currently the 1440 s default — not overridden anywhere) | The target is gone with the session; sign-in lands on `/account`. Accepted; noted in docs |
| Registrant verifies on a different device / browser | No session there; sign-in lands on `/account` (non-goal: no target in mail) |
| Redis down | Sessions unavailable: login itself is already broken (existing, accepted in CLAUDE.md); nothing new |
| JS disabled / fetch fails / response has no `[data-auth-panel]` | The trigger navigates to the full `/login?_target_path=/profile` page (AC-24, AC-25f) |
| JS overlay opened but never used | Target already stashed by the fetch; harmless — consumed by the next sign-in in that session, or dropped at logout / session expiry |
