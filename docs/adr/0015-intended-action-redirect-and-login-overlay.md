# ADR-0015: Intended-action redirect — one carrier, one writer, one validator; the login overlay is `/login` in a native dialog

- **Status:** Accepted
- **Date:** 2026-11-14
- **Established by** the `identity-login-overlay` slice ([spec](../specs/identity-login-overlay/feature-spec.md)).
- **Refines** [ADR-0005](./0005-auth-oauth2-google-plus-api-keys.md) — see its second 2026-11-14 amendment.

## Context

ADR-0005 promised that "any action-oriented task triggers the login/register overlay" and that "the
intended action is preserved across login/registration and OAuth round-trips". The roadmap named a
Symfony UX **Live Component** for the overlay. Implementing it surfaced four forces:

- **"Where was this visitor going?" is not a business fact.** It is navigation state of one browser
  session: no aggregate owns it, no invariant constrains it, nothing persists it. A Domain value
  object for it would be HTTP wearing a DDD costume.
- **Symfony already has a carrier.** The firewall's session target path (`_security.main.target_path`,
  `TargetPathTrait`) is written by the entry point on an anonymous `GET` of a protected route and read
  by `form_login`'s success handler and by our `GoogleAuthenticator`. It survives a failed password,
  the Google round trip (same session as `state`/PKCE), session-id migration on login, and the
  registration + verification trip in the same browser.
- **An open redirect is a trust-boundary bug.** Browsers strip TAB/CR/LF from URLs and treat `\` as
  `/`, so `/\t/evil.example` *becomes* `//evil.example` — a regex that checks "starts with `/`" is not
  a defence. And `GoogleAuthenticator` put the raw session value into a relative `Location`, bypassing
  the `HttpUtils` absolutisation that `form_login` gets for free.
- **Login cannot be a LiveAction without leaving `form_login`.** The firewall intercepts `POST /login`
  before any controller runs. The only alternative, `Security::login()`, is *programmatic* login: it
  skips password verification, `login_throttling` (a `CheckPassportEvent` listener) and the CSRF badge.

## Decision

1. **One carrier:** Symfony's session target path. No hidden form field, no query param on
   `/connect/google`, no second session key.
2. **One writer added:** `GET /login?_target_path=<path>`. Nothing else in this codebase writes the
   target path (the firewall entry point is Symfony's, and writes absolute same-host URLs). **A new
   writer must go through the validator.**
3. **One validator, at the writer:** `Infrastructure/Identity/Http/IntendedActionPath::fromUntrusted()`
   — a same-origin path of 1–2048 bytes, starting with exactly one `/`, no backslash, whitespace or
   control byte, and whose repeatedly percent-decoded, browser-normalised form does not start with
   `//`. Invalid values are ignored silently: never logged, never echoed. It is Infrastructure, not
   Domain — boundary validation with no business vocabulary.
4. **Defence in depth at the readers:** every reader redirects through
   `HttpUtils::createRedirectResponse()`. `form_login` already did; `GoogleAuthenticator` now does too.
   A raw `new RedirectResponse($target)` on a session value is an open redirect.
5. **The overlay is `/login`'s auth panel in a native `<dialog>`**, opened by ~40 lines of plain JS in
   `assets/app.js` with no library: fetch the trigger's `href`, lift `[data-auth-panel]` out with
   `DOMParser`, `showModal()`. Its forms do ordinary full-page submits through the same firewall. The
   no-JS path is the same URL. A trigger is any `a[data-auth-overlay]` whose `href` is
   `/login?_target_path=<intended action>`.
6. **The unverified registrant:** the target survives registration in the session and is honoured at
   the **first successful sign-in in that browser**. No auto-login after registering (I-5) and none on
   clicking the verification link. No navigation value in any mail.

## Alternatives

- **A Live Component overlay (the roadmap's wording):** rejected. With login out of reach (see
  Context), all that is left for it is opening and closing a box — a server round trip to do what
  `<dialog>` does natively. **Rule of thumb: a Live Component is for server-side state that changes as
  the user edits (the Phase 2 listing wizard); authentication is a firewall concern and goes through
  the firewall.**
- **Turbo Frame:** would keep a failed password inside the dialog, at the price of Turbo Drive going
  site-wide and the Google link needing `data-turbo="false"`. Not worth it for the first version.
- **Three carriers** (hidden `_target_path` in every form + a param on `/connect/google`): three
  validators, and `form_login`'s failure redirect drops the field, so the first typo loses the intent.
- **A signed/HMAC'd target token:** protects the integrity of a value we can simply validate.
- **Re-validating at the readers with `IntendedActionPath`:** wrong — the entry point legitimately
  writes absolute same-host URLs, which a relative-only validator rejects. `HttpUtils` is the right
  reader-side check.
- **Auto-login on clicking the verification link:** ADR-0009 makes that link replay-absorbing, so it
  would become a reusable login token sitting in a mailbox.
- **The target in the verification mail:** a navigation value crossing devices inside a security mail,
  with attacker-influenceable text. Cross-device verifiers land on `/account`.

## Consequences

- **Easy:** a future action link ("Sell Gear") becomes an overlay trigger by copying the "Profile"
  link's shape; a future protected route gets the redirect for free from the entry point. No Domain or
  Application code, no dependency, no new session key.
- **Hard / watched:** Symfony's own `_target_path` **POST** parameter is a second door we cannot remove
  without misconfiguring `form_login`; it is safe only because `HttpUtils` absolutises and host-checks,
  and a test proves it. A failed password in the overlay leaves the dialog for the full `/login` page.
  The background fetch consumes flash messages on the fetched page. The target lives only as long as
  the session — PHP's default 1440 s idle lifetime, not overridden — so a slow registrant lands on
  `/account`.
