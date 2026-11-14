# Technical Plan: identity-login-overlay

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order — which for this slice means two empty sections, on purpose.

**Status: approved by the owner 2026-11-14.** D1, D2 and D8 were the owner's calls and are decided
as recorded below; the rest follow from the code as it stands.

## Decisions

**Bold** marks the ones recorded in **ADR-0015** (task T0).

| # | Decision | Chosen | Alternatives considered |
|---|---|---|---|
| D1 | **How the overlay is built** — *owner decision 2026-11-14* | **Native `<dialog>` + plain JS in `assets/app.js`, no libraries.** The overlay *is* `/login`'s auth panel: fetch the trigger's `href`, lift `[data-auth-panel]` out with `DOMParser`, put it in a `<dialog>`, `showModal()`. Forms inside do ordinary full-page submits. The no-JS path is the same URL. | **Live Component (the roadmap's original wording) — rejected, and the reason is worth keeping.** A Live Component re-renders server state through LiveActions. Login cannot be a LiveAction without leaving `form_login`: the firewall intercepts `POST /login` before any controller runs, and the only alternative, `Security::login()`, is *programmatic* login — it skips password verification, `login_throttling` (a `CheckPassportEvent` listener) and the CSRF badge. Using it would mean re-implementing authentication outside the firewall, with every neutral-error and throttling guarantee to rebuild by hand. What remains for a Live Component is toggling a box open and closed — a server round trip to do what `<dialog>` does natively. **Rule of thumb: a Live Component is for server-side state that changes as the user edits (the Phase 2 morphing listing wizard); authentication is a firewall concern and must go through the firewall.** Turbo Frame was the other option: it would keep a failed password inside the dialog, at the price of Turbo Drive going site-wide and the Google link needing `data-turbo="false"`. Not taken. |
| D2 | **The proving target and the trigger** — *owner decision 2026-11-14* | A stub **`/profile`** page (`app_profile`, `ROLE_USER`, placeholder template). It is distinguishable from the default `/account` by path, so every redirect AC asserts a clean `Location`. The **only** trigger is action-shaped: a "Profile" link in `base.html.twig` — for a guest, `a[data-auth-overlay]` to `/login?_target_path=/profile`; for a signed-in user, a plain link to `/profile`; not rendered on `/login` or `/register`. **No generic "Sign in → back to current page" trigger:** the Profile link already gives the JS a real caller (AC-25), and it is the same shape every future action link ("Sell Gear") will take. `/account` stays `default_target_path`. | A generic "Sign in → back here" header link: dropped as unnecessary once `/profile` exists; add it when a page needs it. `/account?probe=…` as the target: worked, but only via a query-string trick. A stub `/sell` route: would fake a future feature. |
| D3 | **One carrier for the intended action: Symfony's session target path** | `_security.main.target_path` via `TargetPathTrait`. It is what the firewall entry point already writes (anonymous `GET` of a protected route), what `form_login`'s success handler already reads, and what `GoogleAuthenticator` already reads. It survives `form_login` failures (the failure handler redirects to `/login` without the param, but the session keeps it), the Google round trip (same session as `state`/PKCE), session-id migration on login, and the registration + verification trip in the same browser. **This slice adds exactly one writer: `GET /login?_target_path=`.** | (a) A hidden `_target_path` field in every form + a query param on `/connect/google`: three carriers, three validators, and `form_login`'s failure redirect drops the field, so the first typo loses the intent. (b) A signed/HMAC'd target token: protects integrity of a value we can simply validate; no attacker gain to defend against once it is same-origin. |
| D4 | **One validator, at the writer: `IntendedActionPath`** | Pure final class in `Infrastructure/Identity/Http/`, `fromUntrusted(mixed): ?string`. Rules in *Interface boundary* below. Infrastructure, not Domain — see the feature spec's *Why there is no Domain code*. | A Domain VO: no business vocabulary, no invariant, only HTTP callers. Symfony's own check only: `DefaultAuthenticationSuccessHandler` accepts any `/…` string and relies on `HttpUtils` absolutising it; `GoogleAuthenticator` currently puts the raw session value in a relative `Location`, where `/\t/evil.example` becomes `//evil.example` in a browser. |
| D5 | **Defence in depth at the readers** | `GoogleAuthenticator::onAuthenticationSuccess()` builds its response with `HttpUtils::createRedirectResponse($request, $target ?? 'app_account')` — the same call `form_login` makes. It absolutises a relative path onto our host and (via `AddSessionDomainConstraintPass`) resets a foreign absolute host to `/`. One changed line plus an injected `HttpUtils`. | Validate again at the reader with `IntendedActionPath`: wrong, because the entry point writes *absolute* same-host URLs (`http://localhost/account?marker=…`, see `GoogleSignInFlowTest`) which a relative-only validator rejects. Leave as is: the only reader that bypasses Symfony's guard is the one we wrote. |
| D6 | **The unverified registrant** | Keep the target in the session; honour it at the **first successful sign-in** in that browser. No auto-login after registering (I-5) and none on clicking the verification link (ADR-0009 makes that link replay-absorbing — it would become a reusable login token). No target in any mail. The comment in `RegistrationController` that says auto-login "belongs to `identity-login-overlay`" is replaced with this decision. | Auto-login on verify-link click: magic-link login via a replayable token, rejected. Target in the verify mail: crosses devices with a navigation value and puts attacker-influenceable text into a security mail, rejected. |
| D7 | Registration stays a full page | The overlay links to `/register`; Google (1-click provisioning, ADR-0014) is the in-overlay registration. | Register-in-dialog: ends at "check your inbox" for password users; no benefit. |
| D8 | **ADR** — *approved by the owner 2026-11-14* | **ADR-0015 "Intended-action redirect: one carrier, one writer, one validator; the login overlay is `/login` in a native dialog"** (D1, D3–D6, incl. the Live Component rationale), plus a dated **ADR-0005 amendment**: "preserved across registration" means *within the session, until the first successful sign-in*; cross-device verification lands on `/account`. Written in T0 during `/implement`. The roadmap line is amended to drop "Live Component". | — |

---

## Domain layer (pure PHP)

**No change.** Not one file under `src/src/Domain/` is touched (AC-26).

- **Aggregates / invariants:** none. `User`, I-5 and `isUsable()` are unchanged; the unverified
  registrant is handled by *not* authenticating them, which `VerifiedAccountUserChecker` already does.
  `/profile` is a placeholder page with no model behind it.
- **Value objects:** none. The intended action is a same-origin path, a property of URLs and
  browsers (D4).
- **Domain events:** none. No listener would exist for a sign-in event.
- **Ports:** none. Nothing inside the hexagon reaches outward.

## Application layer

**No change.** There is no use case: no command mutates state, no query reads the model. Stashing a
string in the session and reading it back on login is HTTP plumbing owned by Symfony Security, and
the profile page reads nothing.

## Infrastructure layer

### HTTP (Identity)

- **`Infrastructure/Identity/Http/IntendedActionPath.php` (new).** `final class`, private ctor,
  `public static function fromUntrusted(mixed $raw): ?string` and `public const int MAX_BYTES = 2048`.
  No Symfony import (it is pure string logic, so it is unit-tested without a kernel), but it lives in
  Infrastructure because it is boundary validation. Its docblock names the one writer and states the
  rule "there is one writer; add a second and it must go through here".
- **`SecurityController::login()`** gains `Request $request` and `Security $security`, uses
  `TargetPathTrait`. On a request whose method is `GET` only:
  ```
  $raw = $request->query->all()['_target_path'] ?? null;   // all(), never get(): get() throws on arrays (AC-11)
  $path = IntendedActionPath::fromUntrusted($raw);
  if (null !== $path && $request->hasSession()) {
      $this->saveTargetPath($request->getSession(), $security->getFirewallConfig($request)->getName(), $path);
  }
  ```
  Nothing is logged for a rejected value (AC-15). The `POST` path is never reached (firewall
  intercepts), and the failed-login re-render arrives as a `GET` without the param, so it leaves the
  target alone. Firewall name from `getFirewallConfig()`, not a `'main'` literal, so it cannot drift
  from `security.yaml`.
- **`Infrastructure/Identity/Http/Controller/ProfileController.php` (new).** The `AccountController`
  shape: `#[Route('/profile', name: 'app_profile', methods: ['GET'])]`, `#[IsGranted('ROLE_USER')]`,
  renders `identity/profile.html.twig` with **no variables**. Docblock: a placeholder that exists to
  be an intended action distinct from `default_target_path`; content belongs to a later slice.

### Security

- **`GoogleAuthenticator::onAuthenticationSuccess()`:** inject `HttpUtils` (`security.http_utils`,
  autowirable); replace `new RedirectResponse($target ?? $this->urls->generate('app_account'))` with
  `$this->httpUtils->createRedirectResponse($request, $target ?? 'app_account')` (D5). A comment
  names `form_login` as the twin that already does this. `UrlGeneratorInterface` stays (the failure
  path uses it).
- **`security.yaml`:** one `access_control` line, `- { path: ^/profile, roles: ROLE_USER }`, beside
  the `^/account` rule and for the same belt-and-braces reason. Nothing else: `default_target_path:
  app_account` stays; `use_referer` stays at its default `false`; `target_path_parameter` stays
  `_target_path` (Symfony's POST door, tested shut by AC-13, unused by us).

### UI (Twig + AssetMapper)

- **`templates/identity/profile.html.twig` (new):** extends `base.html.twig`; `<h1>Profile</h1>` and
  one sentence saying the page is coming. No user data (AC-1).
- **`templates/identity/login.html.twig`:** wrap the existing body (error block, form, links) in
  `<section data-auth-panel aria-labelledby="auth-panel-title">`, `<h1 id="auth-panel-title">`. No
  change to field names, the `authenticate` CSRF id, the error rendering, or the existing comments.
  Override the layout's `auth_nav` block to empty.
- **`templates/identity/register.html.twig`:** override `auth_nav` to empty. Nothing else.
- **`templates/base.html.twig`:** a `{% block auth_nav %}` before `flashes`:
  - guest (`not is_granted('IS_AUTHENTICATED')`):
    `<a href="{{ path('app_login', {_target_path: path('app_profile')}) }}" data-auth-overlay>Profile</a>`
  - signed in: `<a href="{{ path('app_profile') }}">Profile</a>`

  The target is generated from the route, not typed, so it cannot drift from the route; `path()`
  URL-encodes it and Twig autoescapes the attribute.
- **`assets/app.js`:** remove the skeleton `console.log`; add the overlay enhancement, plain JS, no
  imports beyond the existing stylesheet (AC-30):
  - one delegated `click` listener on `document` for `a[data-auth-overlay]`; ignore when
    `event.defaultPrevented`, `button !== 0`, or any of `ctrl/meta/shift/alt` (AC-25h);
  - `preventDefault()`, `fetch(link.href, {credentials: 'same-origin'})`;
  - if `!response.ok` or no `[data-auth-panel]` in `new DOMParser().parseFromString(html, 'text/html')`
    → `location.assign(link.href)` (AC-25f); same in a `catch`;
  - lazily create one `<dialog aria-labelledby="auth-panel-title">` appended to `body`, containing a
    `<form method="dialog"><button>Close</button></form>` and the imported panel
    (`document.importNode`), replacing any previous panel (each open re-fetches, so the CSRF token and
    the target are always fresh);
  - `dialog.showModal()` — native focus to the `autofocus` email field, Esc to close, page inert.

  No inline scripts in the panel (none are executed by `DOMParser` anyway). The fetch is what stashes
  the target (it is the same `GET /login?_target_path=/profile`), so the dialog's forms need no
  hidden field.
- **No CSS** beyond user-agent defaults (ADR-0006: public styling arrives with the first real layout).

### Comment corrections (code that documents a promise this slice declines)

- **`RegistrationController`:** replace the paragraph saying auto-login "belongs to
  `identity-login-overlay`" with D6's decision and its reason (I-5; the replay-absorbing verify link).
- **`config/packages/csrf.yaml`:** reason 1 says "this slice ships no JS"; update to say the site now
  ships a small module but the overlay carries the session-backed token in a fetched panel, so the
  revisit trigger (an HTTP-cacheable public front end) is still not met.

### Persistence, async, external, DI

None. No migration, no Messenger message, no new service definition (`IntendedActionPath` is static;
`HttpUtils` and `Security` autowire; `ProfileController` is picked up like every controller), no new
env var, no Composer or importmap change (AC-30).

## Interface boundary & input contract

**New inputs:** query parameter `_target_path` on `GET /login`, and the route `GET /profile`
(no input; `ROLE_USER`).

`IntendedActionPath::fromUntrusted($raw)` returns the string unchanged iff **all** hold, else `null`:

1. `is_string($raw)` (arrays from `_target_path[]=` → `null`, AC-11);
2. `1 <= strlen($raw) <= 2048` (bytes, AC-12);
3. `$raw[0] === '/'` and, if longer than 1, `$raw[1]` is neither `/` nor `\`
   (kills `//host`, `/\host`, and anything scheme- or host-shaped);
4. no byte in `\x00–\x20`, `\x7F`, or `\` anywhere (kills spaces, TAB/CR/LF — which browsers strip
   from URLs, so `/\t/evil` would *become* `//evil` — and backslashes, which browsers treat as `/`);
5. **after percent-decoding, repeatedly until stable (at most 3 rounds)**, the result — with every
   TAB/CR/LF removed and every `\` turned into `/`, i.e. what a browser's URL parser does — still
   does not start with `//` (kills `/%2F/evil`, `/%5Cevil`, `/%09/evil`, `/%252F%252Fevil` against
   any downstream decoder). Only the *start* is checked after decoding: an encoded space or `%2F`
   later in the path or query (`/a?x=%20y`, `/search?q=a%2Fb`) is legitimate and must survive.

Accepted examples: `/`, `/profile`, `/?from=overlay`, `/profile?pad=aaa`, `/a?x=%20y`,
`/listings/new?category=bass`. Rejected: the feature spec's hostile list, every entry.

**What a valid target grants an attacker:** nothing a plain link to the same muzbar URL does not.
`/login?_target_path=/reset-password/<token>` lands the victim exactly where
`/reset-password/<token>` would have, just after signing in. State-changing endpoints are POST + CSRF
(logout, register, reset submit) or token-bound GETs that behave the same with or without a session.

**What the rest of the system sees:** the session attribute Symfony already defined and one new
protected route. No new session key, no new header.

## Data & migrations

None.

## Test plan

- **Unit (no kernel), `tests/Unit/Infrastructure/Identity/Http/IntendedActionPathTest.php`:** a data
  provider of every hostile value (→ `null`) and every accepted example (→ unchanged), plus the
  2048/2049 boundary and non-string inputs (`null`, `[]`, `123`). Precedent:
  `tests/Unit/Infrastructure/Identity/Presentation/`.
- **Functional, `tests/Functional/Identity/ProfilePageTest.php`:** AC-1.
- **Functional, `tests/Functional/Identity/IntendedActionRedirectTest.php`:** AC-2…AC-15 (password
  half of AC-14), AC-27…AC-29. Uses `RegistersAUserWithKnownCredentials`, `ClearsRateLimiters`
  (every test POSTs `/login`), one `KernelBrowser` per scenario. AC-13 parses `Location` the browser
  way (strip TAB/CR/LF, `\`→`/`). AC-14 writes `_security.main.target_path` into the test session
  directly (the one place a test touches the attribute, because its purpose is to simulate a writer
  that bypasses the validator). AC-27 compares the two bodies against each other live, not against
  a literal.
- **Functional, Google:** add to `GoogleSignInFlowTest` (or a sibling using `DrivesGoogleOAuth`):
  AC-14 (Google half), AC-16, AC-17, AC-18. `disableReboot()` before scripting; one client; take
  `state` from the start response's `Location`.
- **Functional, registrant:** AC-19, AC-20 — read the verification link from
  `RecordingVerificationMailer` (or `MailerAssertionsTrait`; `async` is `sync` under test) and follow
  it in the same client; fetch a fresh repository before any DB assertion (identity-map footgun).
- **Functional, templates:** AC-21…AC-24 with `DomCrawler` selectors; AC-24 uses
  `clickLink('Profile')` + `submitForm('Sign in', …)` — the no-JS path end to end.
- **Proving the tests bite (T13):** (1) make `fromUntrusted()` return its input unchanged →
  AC-9/10/15 must fail; (2) revert D5 → AC-14's Google half must fail; (3) drop rule 4 only → the TAB
  and backslash cases must fail; (4) drop rule 5 only → the `%2F`/`%5C`/`%09` cases must fail;
  (5) drop the `^/profile` `access_control` line *and* the `#[IsGranted]` → AC-1 and AC-5 must fail.
  Record each in the commit message.
- **Manual (AC-25):** in `/verify`, drive the dev stack in a real browser (Playwright MCP is
  available) through (a)…(h); paste the outcome into the verify notes.
- **Performance:** not applicable.

## Risks / open questions

1. **Failed password in the overlay leaves the dialog.** The full `/login` page shows the error and
   the target survives (AC-8). Revisit only if the public UI ever adopts Turbo wholesale.
2. **Session idle lifetime is PHP's default 1440 s.** `RedisSessionHandler` is configured with no
   `ttl`, and no ini sets `session.gc_maxlifetime`, so a registrant who takes more than ~24 idle
   minutes to click the verification link loses the target (and lands on `/account`). Pre-existing
   and not this slice's to change; recorded in CLAUDE.md so nobody files it as a bug in the redirect.
3. **The background fetch of `/login` consumes flash messages.** `base.html.twig` renders (and so
   pops) flashes on every page, including the fetched one whose layout is discarded. Benign today —
   a flash is always displayed by the page the redirect lands on, before any trigger can be clicked —
   but a future flash set by an XHR-ish flow would vanish. Noted in the JS module's comment.
4. **Symfony's `_target_path` POST parameter is a second door we cannot remove** without
   misconfiguring `form_login`. It is safe because `HttpUtils` absolutises and host-checks; AC-13
   proves it rather than assuming it. If a future Symfony changes `determineTargetUrl()`, AC-13 is
   the alarm.
5. **`/profile` is an empty page in production.** A signed-in user can reach a page that says
   "coming". Accepted by the owner as the price of a real, path-distinct proving target.

## Doc updates the implementation must make

- **ADR-0015** (new) + dated **ADR-0005 amendment** (T0).
- **CLAUDE.md:** status line (Identity complete, 7 of 7); a footgun entry: *the session target path
  has exactly one writer (`GET /login`, through `IntendedActionPath`) and every reader redirects via
  `HttpUtils::createRedirectResponse()`; a new writer must validate, and a raw
  `new RedirectResponse($target)` is an open redirect*; the TAB/backslash browser-normalisation note;
  the 1440 s session lifetime note; how a future action link becomes an overlay trigger (the
  "Profile" link's shape).
- **docs/roadmap.md:** tick the slice; amend "Live Component" to "native `<dialog>` + plain JS";
  close the google-oauth AC-4 TODO; note `/profile` as a placeholder awaiting content.
- **FORboehpyk.md:** chapter — "the slice with no Domain code because there was no domain", how open
  redirects actually work, and why a Live Component was the wrong hammer for a login form.
