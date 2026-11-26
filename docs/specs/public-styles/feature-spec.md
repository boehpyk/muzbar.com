# Feature Spec: public-styles

> The *what & why*. Disposable — this dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `public-styles` — Phase 2 item **5 of 15** ([roadmap](../../roadmap.md) §Phase 2),
  before `listing-wizard` (6) and `search-ui` (7) (`listing-publish` plan Q7).
- **Bounded context(s):** **none of the seven owns this slice.** It is presentation only: the layout
  and stylesheet that the existing **Identity**, **Listing** and **Search** pages render inside.
  **No Domain and no Application code in any context** — no aggregates, no invariants, no value
  objects, no events, no ports. There is no business rule here that could be wrong in a way a
  Domain test could catch; what *can* be wrong (a stylesheet that 404s in prod, analytics on an admin
  page or a token URL) is pipeline and template wiring, tested where it lives.
- **Related PRD items:** §5 UX ("high-contrast, mobile-responsive"), §2 non-goal (no native apps —
  responsive web only), §8 metrics *Form Completion Rate* / *Time-to-Publish* (Umami is their
  source). Constitution §3 (styling + analytics rows), §8 (privacy, GDPR).
- **Governing ADRs:** [ADR-0006](../../adr/0006-dual-styling-admin-public.md) (SCSS via
  `symfonycasts/sass-bundle`, `public.css` on the public layout only, shared `:root` tokens),
  [ADR-0003](../../adr/0003-infra-single-vds-compose-traefik.md) (Umami: the shared VDS instance, snippet
  in the public layout), [ADR-0015](../../adr/0015-intended-action-redirect-and-login-overlay.md)
  (the overlay must keep working without JS; the `<dialog>` gets styled, not changed),
  [ADR-0011](../../adr/0011-password-reset-challenges-modelled-in-the-domain.md) /
  [ADR-0009](../../adr/0009-email-verification-tokens-modelled-in-the-domain.md) (token-bearing URLs).
  **Amendments, no new ADR** (plan D7): ADR-0006 and ADR-0003.
- **Status:** Draft
- **Date:** 2026-11-26

> **What this slice teaches, beyond the feature.**
>
> 1. **Production has never served a single asset.** Read from the repo and confirmed against the
>    live site on 2026-11-26: the prod image never runs `asset-map:compile`, so `public/assets/` does
>    not exist in it, and `https://muzbar.com/assets/styles/coming-soon-beYNiO1.css` is a **404**.
>    The coming-soon page has been unstyled in prod since #16, and `app.js` (the login overlay) has
>    never loaded there either — ADR-0015's "without JS it is an ordinary link" fallback is what has
>    been live. Every test was green, because in `dev`/`test` AssetMapper serves sources on the fly.
>    Same family as the `APP_ENV=production` and `trusted_proxies` stories: **an environment
>    difference no in-process test can see.** This slice fixes it and adds the deploy check that would
>    have caught it (AC-10).
> 2. **"Empty = off" for optional config, again.** Umami follows Google sign-in's pattern:
>    `%env(default::…)%`, never a bare `%env()%` (CLAUDE.md boot-path footgun), and a half-configured
>    snippet is *off*, not a script tag pointing nowhere.
> 3. **Analytics is a data flow, so it is a privacy decision.** A tracker records URLs. muzbar has
>    URLs that carry secrets (`/verify-email/{token}`, `/reset-password/{token}`); they must never
>    render a page that runs the tracker. They don't today — they only redirect — and AC-8 pins that.

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Public layout** | `templates/base.html.twig` after this slice: the skeleton every non-admin page extends; links the compiled `public.css` and renders the analytics partial. | `coming_soon.html.twig` (standalone, untouched except Q2) |
| **Admin layout** | New `templates/admin/layout.html.twig`, extends the public layout and **empties** its `stylesheets` and `analytics` blocks. Unstyled until a Tailwind slice fills it (ADR-0006's `admin.css`). | an admin *stylesheet* (none exists; Tailwind is not installed) |
| **`public.css`** | The compiled output of `assets/styles/public.scss` (Dart Sass via sass-bundle), served by AssetMapper as `/assets/styles/public-<digest>.css`. | `app.css` (the skeleton's sky-blue stub — deleted), `coming-soon.css` |
| **Tokens** | `assets/styles/tokens.css`: plain-CSS custom properties on `:root` (colour, type, spacing). Plain CSS so the future Tailwind config can read the same file (ADR-0006 "one source of truth"). | Sass variables (not used for tokens) |
| **Analytics snippet** | `<script defer src="{UMAMI_SCRIPT_URL}" data-website-id="{UMAMI_WEBSITE_ID}" …>` in `<head>`, rendered **only** when both vars are non-empty. | a consent banner (none — plan D6) |
| **Token URL** | Any URL whose path carries a single-use secret: `/verify-email/{token}`, `/reset-password/{token}`. | `/reset-password` (no token; the token lives in the session) |

## User story

As a **visitor or seller**, I want the browse, listing, sell and sign-in pages to look like
muzbar — readable on a phone, usable by keyboard — so that the marketplace is something I trust
enough to use. As the **owner**, I want page-view analytics from the existing Umami instance so the
PRD's funnel metrics have a source, without leaking anything a visitor would not expect.

## In scope

- `symfonycasts/sass-bundle` installed (in `require`, not `require-dev` — the prod image compiles),
  root file `assets/styles/public.scss`, Dart Sass binary version pinned.
- `assets/styles/tokens.css` (brand tokens lifted from `coming-soon.css`), self-hosted font faces
  (the existing `assets/fonts/` subsets), base/typography/forms/layout styles, and component styles
  for the **existing** class names (`search-*`, `listing-*`, `draft-*`, `flash`, `error`,
  `account-email`, the overlay `dialog` / `[data-auth-panel]`).
- Public layout changes in `base.html.twig`: viewport meta, brand favicon, a skip link, a
  `<header>` around the existing nav links, the `public.css` link, the analytics partial; delete the
  dead FrankenPHP hot-reload block (third-party CDN scripts, never active behind nginx/FPM).
- New admin layout; the six `catalog/admin/*` templates switch their `extends` to it.
- Delete `assets/styles/app.css` and its `import` in `app.js`.
- Build wiring: `make sass` / `make sass.watch`; `make test` and CI build Sass before PHPUnit; the
  prod image runs `sass:build` + `asset-map:compile` and **fails the build** if `public-*.css` is
  missing; `deploy.yml` smoke check fetches the stylesheet `/login` links.
- Umami config: `UMAMI_SCRIPT_URL`, `UMAMI_WEBSITE_ID` (committed empty in `src/.env` and
  `.env.example`), Twig globals via `%env(default::…)%`; runbook in docs/infrastructure.md.

## Non-goals (explicit — hold the line)

- **No Domain/Application/PHP production code.** Config, Twig, SCSS, Dockerfile, Makefile, workflows.
- **No Tailwind, no `admin.css`.** The admin layout exists so that public CSS and analytics stay off
  admin pages; styling it is a later slice (roadmap note, plan D7).
- **No markup restructuring of page templates.** No class renamed or removed, no `data-*` attribute
  changed, no element replaced (`main.search-browse` stays a `<main>`: `BrowsePageTest` selects it).
  Additions only, and only in the two layouts.
- **No redesign of `coming_soon.html.twig` / `coming-soon.css`** — it is deleted when `/` becomes the
  real home page, not merged (its own header comment). Q2 covers only the analytics partial.
- No dark/light theme switch (one theme, plan D4), no custom error pages, no CSP (none exists
  today — plan D6 records what a future one must allow), no nginx cache headers for `/assets/`
  (nginx already sends `ETag`/`Last-Modified`; long-lived `immutable` caching is a later perf call),
  no JS changes beyond deleting the `app.css` import, no Umami custom events (funnel events are
  `listing-wizard`'s), no consent banner (plan D6).

## Acceptance criteria (the Definition of Done checklist)

**Pipeline**

- [ ] AC-1: Every page extending the public layout (checked: `/browse`, `/login`, `/register`,
      `/listings/{published id}`, `/sell` signed in) has **exactly one**
      `<link rel="stylesheet">` whose `href` matches `^/assets/styles/public-[A-Za-z0-9_-]+\.css$`,
      and a `GET` of that href in the same test client returns **200**, `text/css`, with a body
      containing `--ink` (a token from `tokens.css` — proves tokens are compiled in).
- [ ] AC-2: **No page links `app.css`**: no `href` containing `/assets/styles/app-` on any page in
      AC-1 or AC-3, and `assets/styles/app.css` does not exist.
- [ ] AC-3: **Admin pages carry no public CSS**: `GET /admin/categories` (and one attribute page) as
      `ROLE_ADMIN` has **zero** `href`s matching `/assets/styles/public-`; and a scan test asserts
      every template under `templates/catalog/admin/` begins with
      `{% extends 'admin/layout.html.twig' %}` and the scan saw ≥ 6 files.
- [ ] AC-4: `make test` and CI build the Sass before PHPUnit: deleting `var/sass/` then running
      `make test` is green (the target rebuilds it). CI has a `sass:build` step before "Tests".
- [ ] AC-5: The **prod image build fails** if `public/assets/styles/public-*.css` is absent after
      `asset-map:compile` (a guard in the same `RUN`). Proved by hand once (mutation f).
- [ ] AC-6: Compiled `public.css` ≤ **30 KB** uncompressed (fonts excluded) — checked in the AC-1
      test on the served body.

**Accessibility & no-JS** (hand-checked in Chromium at `/verify`, plus the automatable parts)

- [ ] AC-7a: Public layout has `<meta name="viewport" content="width=device-width, initial-scale=1">`
      and a skip link as the **first** focusable element in `<body>`, `href="#content"`, targeting
      an element with `id="content"` that wraps `{% block body %}` (functional test).
- [ ] AC-7b: Every interactive element (`a`, `button`, `input`, `select`, `textarea`, `summary`) has a
      visible `:focus-visible` style; the stylesheet contains no `outline: none` / `outline: 0`
      without a replacement in the same rule (hand-check + grep).
- [ ] AC-7c: Text colour pairs meet **WCAG AA**: body text ≥ 4.5:1, large text and UI borders
      ≥ 3:1. The plan's token table (D4) lists each pair's computed ratio; a Lighthouse accessibility
      audit on `/browse`, `/login`, a listing page and `/sell` reports **no contrast failures**.
- [ ] AC-7d: All `transition`/`animation` declarations sit inside
      `@media (prefers-reduced-motion: no-preference)` (grep the SCSS sources).
- [ ] AC-7e: At **320 px** viewport width, `/browse`, a listing page and `/sell` have no horizontal
      scroll (hand-check, `document.documentElement.scrollWidth <= 320`).
- [ ] AC-7f: **JS disabled**: browse facets submit, "Sell Gear"/"Profile" navigate to `/login`,
      sign-in works, no content is hidden (hand-check). JS enabled: the overlay `<dialog>` opens
      styled, with a visible `::backdrop`, and Esc closes it (ADR-0015 AC-25 behaviour unchanged).

**Analytics (Umami)**

- [ ] AC-8: **Unconfigured** (the test default — both vars empty): no page renders a
      `script[data-website-id]`. **Half-configured** (only one of the two set): still none.
- [ ] AC-9: **Configured** (both set, in the test): every AC-1 page renders exactly **one**
      `<script defer src="{UMAMI_SCRIPT_URL}" data-website-id="{UMAMI_WEBSITE_ID}"
      data-exclude-search="true">` in `<head>` (Q3 default), and:
      - (a) the AC-3 admin pages render **none**;
      - (b) `GET /verify-email/{token}` and `GET /reset-password/{token}` — each with a valid, an
        unknown and a malformed token — return responses with **no** snippet (they redirect; this
        pins that no token URL ever renders the layout);
      - (c) if Q2 is accepted: `/` renders exactly one.
- [ ] AC-10: **Deploy smoke check**: `deploy.yml` fetches `/login`, extracts the `public-*.css` href,
      and fails the deploy unless that URL returns 200 with `content-type: text/css`. (This is the
      check that would have caught the coming-soon 404.)

**Additivity**

- [ ] AC-11: The full pre-existing suite passes **unmodified** (no test file edited except new ones),
      and `git diff main -- src/templates` touches only `base.html.twig`, the new
      `admin/layout.html.twig`, the six admin `extends` lines, the new `_analytics.html.twig`, and
      (Q2) one include line in `coming_soon.html.twig`.

## Failure contract

| Condition | Expected behaviour |
|---|---|
| `var/sass/public.output.css` missing in dev/test (watch not running, fresh clone) | Loud: the render throws sass-bundle's "run `sass:build`" error. Never a silently unstyled page. `make test` rebuilds first (AC-4). |
| Sass syntax error | `sass:build` exits non-zero → `make test`, CI and the image build fail. |
| Dart Sass binary download fails (GitHub unreachable) in CI or `docker build` | Build fails loudly; retry. No fallback to a committed CSS file. |
| `asset-map:compile` produced no `public-*.css` in the image | `docker build` fails (AC-5). |
| Assets missing on the box after deploy (volume/nginx path) | Deploy smoke check fails (AC-10). |
| `UMAMI_*` unset or either empty (dev, test, CI, `docker build`, worker, console) | Kernel boots (`default::`); no snippet; nothing logged. |
| Umami instance down or blocked by the visitor | `defer` script fails to load; page is unaffected (no inline code depends on it). |
| JS disabled | Everything works as today (AC-7f); styling never hides content that only JS reveals. |
| Old browser without `:focus-visible` / custom properties | Falls back to the UA focus ring / unstyled but readable markup; no feature query polyfills. |
