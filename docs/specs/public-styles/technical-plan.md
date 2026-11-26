# Technical Plan: public-styles

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order — and two of its three layers are empty on purpose (D1).

## Pre-planning observations (2026-11-26, read from the repo; one live check)

1. **Prod serves no assets.** `docker/php/Dockerfile`'s `production` stage runs `composer install`
   (auto-scripts: `cache:clear`, `assets:install`, `importmap:install`) and **never
   `asset-map:compile`**. With `debug` off, AssetMapper's dev server is off, so every `/assets/…`
   URL falls through nginx to `index.php` → 404. Live: `curl https://muzbar.com/assets/styles/coming-soon-beYNiO1.css`
   → **404 text/html**. The coming-soon page is unstyled in prod, and `importmap('app')` points at a
   404, so the login overlay has never run in prod (its no-JS fallback has). No test can see it.
2. **There is no admin layout and no Tailwind.** All six `catalog/admin/*` templates extend
   `base.html.twig`, the same as every public page. `symfonycasts/tailwind-bundle` is not installed.
   ADR-0006's `admin.css` is unimplemented.
3. **`base.html.twig` is the Symfony skeleton plus nav and flashes**: no viewport meta, the "sf"
   favicon, an empty `stylesheets` block, and a FrankenPHP hot-reload block that loads two scripts
   from `cdn.jsdelivr.net` (dead: the stack is nginx + FPM; `FRANKENPHP_HOT_RELOAD` is never set).
4. **`app.js` imports `./styles/app.css`** (`body { background-color: skyblue; }`), so every page —
   admin included — already links an `app-<digest>.css` through `importmap('app')`.
5. **Only `search/browse.html.twig` has a `<main>`**; `BrowsePageTest`/`BrowseRangePageTest` select
   `main.search-browse`. Other pages render `{% block body %}` straight into `<body>`.
6. **Brand tokens exist** in `coming-soon.css` `:root` (`--ink #0f0e0c`, `--paper #f2ede4`,
   `--accent #ff4a1c`, `--muted`, `--faint`, `--rule`; Unbounded + IBM Plex Mono, self-hosted
   subsets in `assets/fonts/`).
7. **No CSP header anywhere** (nginx conf, Symfony config). `twig.yaml` sets `strict_variables` in
   test, so the two new globals must always be defined (they are — `default::` yields `null`).
8. **The image is Debian** (`php:8.4-fpm`), and CI runs PHP directly on `ubuntu-latest`: both are
   glibc x86-64, so sass-bundle's downloaded Dart Sass binary runs in both.

## Decisions

### D1 — Zero Domain, zero Application code; no bounded context owns this

Presentation only. No aggregates, no invariants, no value objects, no events, no ports, no
handlers, no migrations — and not even PHP in `src/src/`: the slice is config (`twig.yaml`,
`symfonycasts_sass.yaml`, `.env`), Twig, SCSS, Dockerfile, Makefile and two workflows. The pages it
dresses belong to Identity, Listing and Search; none of their models changes. Inventing a
"Theme" or "AnalyticsPort" would be a port nothing inside calls — the same argument as
`identity-password-changed-notification`'s mail without a port. Deptrac and the isolation tests are
untouched by construction.

### D2 — Layouts: `base.html.twig` *is* the public layout; admin opts out

Two shapes were possible: (A) make `base` public and add an admin layout that empties the public
blocks; (B) keep `base` bare and add a public layout every public page extends. **A**, because:

- the diff is 6 one-line `extends` changes (admin) instead of 16 (public), and no public page
  template changes at all — the roadmap's "purely additive" promise holds literally;
- the safe default for a *new* page is the one most pages want; a new admin page that forgets the
  admin layout gets styled and counted, which is visible and harmless, and AC-3's scan catches it;
- the admin layout is the file ADR-0006's future `admin.css` (Tailwind) goes into.

`templates/admin/layout.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block stylesheets %}{% endblock %}
{% block analytics %}{% endblock %}
```

It keeps nav, flashes, skip link and `importmap('app')` (the overlay triggers are in the nav).

`base.html.twig` changes, all additive:

- `<meta name="viewport" content="width=device-width, initial-scale=1">`;
- brand favicon (the coming-soon "M." SVG data URI) replaces the skeleton "sf";
- `{% block stylesheets %}` → `<link rel="preload" … unbounded-latin.woff2 as="font" crossorigin>` +
  `<link rel="stylesheet" href="{{ asset('styles/public.scss') }}">` (sass-bundle maps the `.scss`
  logical path to the compiled CSS);
- `{% block analytics %}{{ include('_analytics.html.twig') }}{% endblock %}` in `<head>`;
- delete the FrankenPHP block (observation 3; "no dead code", and the only third-party script URLs
  in the layout);
- body: `<a class="skip-link" href="#content">Skip to content</a>` first; the existing nav links
  wrapped in `<header class="site-header"><nav class="site-nav" aria-label="Main">…</nav></header>`
  (link markup, `data-auth-overlay` and `auth_nav` block unchanged); flashes unchanged;
  `<div id="content" class="site-content">{% block body %}{% endblock %}</div>`.

**Why a `<div id="content">` and not `<main>`:** browse already has its own `<main>`, and a second
one nested would be invalid; turning browse's into a `div` would edit a page template and three test
selectors. Landmarks for the other pages are a page-template change, left to the slices that rewrite
those pages (`listing-wizard`, `search-ui`). Non-goal, recorded.

### D3 — The Sass pipeline in every environment

`composer require symfonycasts/sass-bundle` (**`require`**, because `composer install --no-dev`
in the image must still have it). Bundle enabled for `all` envs. Config
`config/packages/symfonycasts_sass.yaml`:

```yaml
symfonycasts_sass:
    root_sass: ['%kernel.project_dir%/assets/styles/public.scss']
    # binary_version: pinned to an exact Dart Sass release (T1 checks the option exists in the
    # installed bundle version via `config:dump-reference symfonycasts_sass`; if not, record the
    # downloaded version here and in ADR-0006's amendment).
```

| Where | How it compiles | Who notices if it doesn't |
|---|---|---|
| **dev** | `make sass.watch` (`exec app php bin/console sass:build --watch`, foreground) while editing; `make sass` once. Output `var/sass/public.output.css`; AssetMapper's dev server serves it. **Never run `asset-map:compile` in dev** — a `public/assets/` dir then shadows the dev server and serves stale CSS. | The render throws sass-bundle's "run sass:build" error — loud. |
| **test** | `make test` gains a `sass` prerequisite (`sass:build`, env-independent output dir). | Same exception, in every functional test. |
| **CI** | New step "Build Sass" (`php bin/console sass:build`) after "Install dependencies", before "Tests". Downloads the binary to `var/dart-sass/`. | CI red. |
| **prod image** | `production` stage, after `composer install`: `php bin/console sass:build && php bin/console asset-map:compile && ls public/assets/styles/public-*.css >/dev/null` (runs as the build user, `APP_ENV=prod` from the stage `ENV`; the kernel already boots here for `cache:clear`). Explicit `sass:build` rather than relying on the bundle's compile-event listener: one more second of build, no magic to remember. The entrypoint already copies `public/` into the nginx volume. | `docker build` fails (AC-5); deploy smoke check (AC-10). |

**Side effect, called out:** the same `asset-map:compile` fixes observation 1 for `coming-soon.css`,
the fonts and `app.js` — the overlay goes live in prod for the first time. Owner should click
"Sell Gear" on prod after this deploy (Q5).

Sass specifics the implementer must not trip over:

- `tokens.css` is **plain CSS** and is pulled in with `@use 'tokens';` (Dart Sass loads `.css`
  files by bare name and inlines them). Plain because Tailwind cannot read SCSS (ADR-0006).
- `url()` in any partial is resolved **relative to the entry file** (`assets/styles/public.scss`),
  because Sass does not rewrite URLs; AssetMapper then digests `../fonts/*.woff2` from there.
- Partials: `_fonts.scss`, `_base.scss` (box-sizing, typography, links, focus, skip link, reduced
  motion), `_layout.scss` (header/nav/content/flash), `_forms.scss`, `_components.scss` (`search-*`,
  `listing-*`, `draft-*`, `archived-*`, dialog). Fewer files is fine; more is not needed.
- No Sass `@import` (deprecated in Dart Sass 1.80+; deprecation warnings would spam every build).

### D4 — Tokens and the one theme

One dark theme, the coming-soon palette (brand continuity; and the accent only passes contrast on
dark). Computed WCAG ratios (relative luminance, sRGB):

| Pair | Ratio | Use |
|---|---|---|
| `--paper #f2ede4` on `--ink #0f0e0c` | ≈ 16.4:1 | body text |
| `--muted #c9c2b6` on `--ink` | ≈ 11:1 | secondary text |
| `--faint #8a8479` on `--ink` | ≈ 5.1:1 | metadata — the floor; never darker |
| `--accent #ff4a1c` on `--ink` | ≈ 5.7:1 | links, focus ring, primary button text/border |
| `--accent` on `--paper` | ≈ **2.9:1 — fails** | **forbidden**; no light surfaces carry accent text |
| `--rule #2a2722` on `--ink` | < 3:1 | decorative separators only, **never** an input border (inputs use `--faint`) |

`tokens.css` adds spacing (`--space-1…6`), type scale (`--step--1…3`), `--radius`, `--focus-ring`,
and `--content-width`. Font stacks reuse the existing subsets; `font-display: swap`. Body text in
IBM Plex Mono at ≥ 1rem was the coming-soon choice — fine for headings/metadata, heavy for long
descriptions: Q1.

Accessibility rules baked into `_base.scss`: `:focus-visible { outline: 2px solid var(--accent);
outline-offset: 2px; }` on all interactive elements; no `outline: none` without a replacement;
every `transition`/`animation` inside `@media (prefers-reduced-motion: no-preference)`; the skip
link is visually hidden until focused (not `display: none`); inputs ≥ 44 px tall on touch; `dialog`
and `::backdrop` styled, `[data-auth-panel]` styled the same in the dialog and on `/login`.
Mobile-first, one breakpoint (`min-width: 48rem`) for the browse two-column facets/results layout.

### D5 — Umami: config, snippet, where it renders

- **Config** (`config/packages/twig.yaml`):
  ```yaml
  twig:
      globals:
          umami_script_url: '%env(default::UMAMI_SCRIPT_URL)%'
          umami_website_id: '%env(default::UMAMI_WEBSITE_ID)%'
  ```
  `default::` — never bare `%env()%`: Twig globals are boot-path for every kernel (worker, console,
  CI, `docker build`). Committed empty in `src/.env`; documented empty in `.env.example` with a
  comment; set on the box by hand (runbook). Twig globals rather than a controller/extension:
  zero PHP, and config stays in `config/` (CLAUDE.md "never read env vars outside config").
- **Partial** `templates/_analytics.html.twig`:
  ```twig
  {% if umami_script_url and umami_website_id %}
      <script defer src="{{ umami_script_url }}" data-website-id="{{ umami_website_id }}" data-exclude-search="true"></script>
  {% endif %}
  ```
  `and`, not `or` — half-configured is off (mutation c). Autoescape covers both attributes. No
  inline JS, so a future CSP needs only `script-src` + `connect-src` for the Umami origin (D6).
- **Renders on:** every page on `base.html.twig` (public). **Not on:** admin (block emptied), email
  templates (own skeleton), Twig's error pages (TwigBundle's standalone template — no override
  exists; a future custom error page that extends `base` would start tracking error URLs: watch
  item), token URLs (they only redirect — AC-9b pins it), `/` (unless Q2).
- **`data-exclude-search="true"`** (Q3): Umami then records `pathname` only. `/browse?q=` is free
  text a visitor may type personal data into, and `/login?_target_path=` is noise. Cost: facet usage
  is invisible in Umami — revisit with `search-ui` if wanted (then exclude only `q`, which Umami
  cannot do: it would need a custom `data-before-send`, i.e. inline JS — that is why the default is
  all-or-nothing).
- **Login overlay:** `app.js` parses `/login`'s HTML with `DOMParser` and imports only
  `[data-auth-panel]`; scripts in the fetched `<head>` never execute, so an overlay open is not a
  second page view. No change needed.

### D6 — Privacy: no consent banner, recorded

Umami is self-hosted on muzbar's own VDS (no third party receives data), sets **no cookies** and
no local storage, and stores no IP address or personal identifier (visitor hashes use a rotating
salt). Under GDPR/ePrivacy that is the standard basis for running it without a consent banner.
muzbar's own additions keep it that way: no custom events with user data, search strings excluded,
token URLs never tracked, admin never tracked. Recorded as an **ADR-0003 amendment** (who decided,
what a future change — custom events, a second tracker, cookies — must re-open). The owner should
confirm this is acceptable for his jurisdiction (Q4); a privacy-policy page mentioning Umami is
owed before public launch (roadmap note, not this slice).

### D7 — ADRs: two amendments, no new ADR

- **ADR-0006 amendment** — (1) tokens are a **plain-CSS** file `@use`d by Sass so the later
  Tailwind config can read it; (2) the admin layout exists and empties the public blocks; `admin.css`
  and Tailwind are deferred to the first slice that styles admin (add a roadmap line); (3) the prod
  image runs `sass:build` + `asset-map:compile` and asserts the output — and *why* (observation 1);
  (4) the pinned Dart Sass version.
- **ADR-0003 amendment** — the Umami snippet's config shape (two env vars, empty = off), where it
  renders and where it never does, `data-exclude-search`, and D6's consent reasoning.

Neither changes a decision those ADRs made; both record how it was carried out. A new ADR would be
warranted only if Q1 (body font) or Q3 turned into a lasting design-system decision — they don't.

## Domain layer (pure PHP)

- (none — D1)

## Application layer

- (none — D1)

## Infrastructure layer

- **Persistence:** none. No migration.
- **HTTP / UI:** `base.html.twig` (D2), new `admin/layout.html.twig`, new `_analytics.html.twig`, six
  admin `extends` lines; `assets/styles/{tokens.css, public.scss, _*.scss}`; delete
  `assets/styles/app.css` and the first line of `assets/app.js`.
- **Config:** `symfonycasts_sass.yaml`, `twig.yaml` globals, `bundles.php` (Flex), `src/.env` +
  `.env.example` (`UMAMI_SCRIPT_URL=`, `UMAMI_WEBSITE_ID=`).
- **Build/ops:** `Makefile` (`sass`, `sass.watch`, `test: test.db sass`), `ci.yml` (Build Sass step),
  `Dockerfile` (D3 line), `deploy.yml` smoke check (AC-10):
  ```sh
  page="$(curl -fsS https://muzbar.com/login)"
  css="$(printf '%s' "$page" | grep -o '/assets/styles/public-[A-Za-z0-9_-]*\.css' | head -n1)"
  [ -n "$css" ] || { echo "::error::/login links no public stylesheet"; exit 1; }
  type="$(curl -fsS -o /dev/null -w '%{content_type}' "https://muzbar.com$css")"
  case "$type" in text/css*) ;; *) echo "::error::$css is '$type', expected text/css"; exit 1;; esac
  ```
  (Captured into variables first — the existing comment's `pipefail`/SIGPIPE lesson.)
- **DI wiring:** none.

## Interface boundary & input contract

- **Env:** `UMAMI_SCRIPT_URL` (absolute URL of the shared instance's `script.js`), `UMAMI_WEBSITE_ID`
  (the muzbar site's UUID in Umami). Both optional; trusted operator input; empty/unset = off.
- **Twig blocks** a page or layout may override: `stylesheets`, `analytics` (new), plus the existing
  `title`, `head`, `auth_nav`, `flashes`, `body`, `javascripts`/`importmap`.
- **Class names** are the contract between templates and SCSS: the existing ones (feature-spec
  in-scope list) plus the layout's new `skip-link`, `site-header`, `site-nav`, `site-content`.
  No page template changes, so no class is renamed.

## Data & migrations

- None.

## Test plan

All new tests are functional (`WebTestCase`), in `tests/Functional/PublicStyles/` — no Domain or
Integration tests, because there is no Domain (D1).

- **`PublicLayoutTest`** — AC-1 (one `public-*.css` link per page; GET it → 200, `text/css`,
  contains `--ink`, ≤ 30 KB — AC-6), AC-2 (no `/assets/styles/app-`), AC-7a (viewport; skip link is
  the first focusable element in `body` and `#content` exists). Pages: `/browse`, `/login`,
  `/register`, a published listing (`ListingFactory::published()`), `/sell` signed in.
- **`AdminLayoutTest`** — AC-3: `/admin/categories` and one attribute page as `ROLE_ADMIN` (grant
  before login — CLAUDE.md) have no `public-` href; template scan of `templates/catalog/admin/*.twig`
  (≥ 6 files, each starts with the admin `extends`).
- **`AnalyticsSnippetTest`** — AC-8/AC-9. Configures Umami by setting `$_SERVER`/`$_ENV`
  `UMAMI_SCRIPT_URL`/`UMAMI_WEBSITE_ID` **before** `createClient()` (env placeholders resolve when
  the fresh kernel's container builds the `twig` service) and unsetting them in `tearDown()`.
  Cases: unconfigured → 0; each half alone → 0; configured → exactly one `head script[data-website-id]`
  with the exact `src`, id and `data-exclude-search="true"` on each AC-1 page; admin pages → 0;
  `/verify-email/{valid|unknown|malformed}` and `/reset-password/{valid|unknown|malformed}` → no
  snippet in the response body (assert redirect *and* absence — the absence is the contract, the
  redirect is today's reason). Uses `ClearsRateLimiters` (reset-link limiter). **Verify the env
  approach first** (one configured case red→green); if the value does not reach the container,
  stop and report rather than inventing a test-only seam.
- **Hand-checks at `/verify`** (Chromium, logged in the verify notes): AC-7b–f; Lighthouse
  accessibility on four pages; the overlay with JS on; the whole site with JS off; 320 px.
- **Mutations (bite proof — each must turn something red, noted in the commit message):**
  - (a) remove the `<link>` from `stylesheets` in `base` → `PublicLayoutTest`.
  - (b) admin layout stops emptying `stylesheets` → `AdminLayoutTest`.
  - (c) partial `and` → `or` → `AnalyticsSnippetTest` half-configured cases.
  - (d) admin layout stops emptying `analytics` → `AnalyticsSnippetTest` admin case.
  - (e) restore `import './styles/app.css'` in `app.js` → `PublicLayoutTest` AC-2.
  - (f) drop `asset-map:compile` from the Dockerfile → `docker build` fails on the `ls` guard (hand,
    once).
  - (g) drop the `sass` prerequisite from `make test` after `rm -rf var/sass` → suite red.
  - (h) change one admin template back to `extends 'base.html.twig'` → scan in `AdminLayoutTest`.
  - (i) remove `id="content"` → skip-link assertion.
  - (j) make `/verify-email/{token}`'s invalid-link branch `render()` a template extending `base`
    instead of redirecting → AC-9b (proves the token-URL case can fail; revert).

## Risks / open questions

**Risks**

1. **Dart Sass binary is downloaded from GitHub** at CI time and at `docker build` — a GitHub outage
   blocks builds. Accepted (same class as pulling base images). Not cached in CI: ~5 MB, cheap.
2. **`asset-map:compile` in the image changes what prod serves for the first time.** Anything that
   was silently 404ing (overlay JS, coming-soon fonts/CSS) starts working. Upside, but the overlay's
   first real-world run is in prod — Q5.
3. **The nginx asset volume accumulates old digests** (`cp -a` never deletes). Harmless (old pages
   in flight keep their CSS), slow growth; prune later if it matters.
4. **`public/assets/` in a dev checkout shadows the dev server.** Gitignored, but if someone runs
   `asset-map:compile` locally, dev serves stale CSS while tests stay green. CLAUDE.md footgun line.
5. **A future custom error page or token-URL page that renders `base`** would start tracking it.
   AC-9b covers the two token routes as they exist; any new route with a secret in its path must add
   itself there.

**Open questions for the owner (recommended defaults in bold; tasks assume them)**

- **Q1 — Body font.** IBM Plex Mono for everything (coming-soon look), or Plex Mono for
  UI/metadata with a system sans for long text (listing descriptions)? **Default: Unbounded for
  headings, Plex Mono for UI and body, revisit after seeing a real listing page** — no new font files.
- **Q2 — Umami on the coming-soon page `/`?** It is the only page with real traffic today. **Default:
  yes — one `{{ include('_analytics.html.twig') }}` line in its `<head>`;** its CSS stays untouched.
- **Q3 — Exclude query strings from Umami?** **Default: yes (`data-exclude-search="true"`)** — privacy
  over facet analytics until `search-ui` asks for it (D5).
- **Q4 — No consent banner** on the basis in D6. **Default: accept and record in ADR-0003;** owner
  confirms for his jurisdiction. Privacy-policy page owed before public launch (roadmap note).
- **Q5 — The overlay goes live in prod** with this deploy (observation 1). **Default: ship; owner
  clicks "Sell Gear" signed out on prod after deploy and confirms the dialog** (one line in the
  runbook's post-deploy checklist).
- **Q6 — Deploy smoke check for Umami** (like Google's)? **Default: no** — a missing site ID costs
  analytics, not users; owner verifies once in Umami's real-time view after setting the vars.
- **Q7 — Admin styling.** **Default: none in this slice; add a roadmap line "admin-styles (Tailwind,
  ADR-0006)" with no fixed slot.**
