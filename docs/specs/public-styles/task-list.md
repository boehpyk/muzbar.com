# Task List: public-styles

> Ordered, small tasks. Each should be reviewable in < 5 minutes and is one commit on
> `feature/public-styles`. Run `make check` before each commit. Check off as you go.
> **Domain and Application are empty on purpose** (plan D1: presentation only — no aggregates,
> invariants, value objects, events or ports). **Owner answers (2026-11-26):** plan approved; Q1–Q7 all take the
> recommended default.

## Domain
- (none — plan D1)

## Application
- (none — plan D1)

## Infrastructure — pipeline first (so every later task can be seen)
- [x] T1: `composer require symfonycasts/sass-bundle` (in `require`); `symfonycasts_sass.yaml` with
      `root_sass: [assets/styles/public.scss]` and the Dart Sass version pinned (check the option via
      `config:dump-reference symfonycasts_sass`; record the version either way). Minimal
      `public.scss` that `@use`s a new `tokens.css` (lifted from `coming-soon.css` `:root` + spacing,
      type scale, focus ring — plan D4). `make sass` / `make sass.watch`; `make test` depends on
      `sass`. Verify `make sass` writes `var/sass/public.output.css`.
- [x] T2: CI "Build Sass" step before "Tests" (`ci.yml`). Dockerfile `production` stage:
      `sass:build && asset-map:compile && ls public/assets/styles/public-*.css` (plan D3). Build the
      image locally once and confirm `public/assets/styles/coming-soon-*.css` and `app-*.js` are now
      in it too.

## Infrastructure — layouts
- [x] T3: `templates/admin/layout.html.twig` (empties `stylesheets` and `analytics`); six
      `catalog/admin/*` templates switch `extends`. No visible change yet.
- [x] T4: `base.html.twig` (plan D2): viewport meta, brand favicon, font preload + `public.scss` link
      in `stylesheets`, `analytics` block with `_analytics.html.twig` (empty partial for now), skip
      link, `<header class="site-header"><nav class="site-nav" aria-label="Main">` around the existing
      links, `<div id="content" class="site-content">` around `body`; delete the FrankenPHP block.
      Delete `assets/styles/app.css` and its import in `app.js`. Existing suite still green, unmodified.

## Infrastructure — styles (each partial one commit; hand-check in Chromium as you go)
- [ ] T5: `_fonts.scss` (existing subsets; `url()` relative to the entry file) + `_base.scss`
      (box-sizing, typography, links, `:focus-visible`, skip link, reduced-motion rule, `dialog` +
      `::backdrop`).
- [ ] T6: `_layout.scss` (header/nav, content width, flashes `flash-success`/`flash-error`, `.error`)
      + `_forms.scss` (labels, inputs, selects, textareas, buttons, fieldsets/legends, error text;
      inputs bordered with `--faint`, never `--rule`).
- [ ] T7: `_components.scss` — `search-*` (one breakpoint, facets beside results ≥ 48rem), `listing-*`,
      `draft-*`, `archived-*`, `account-email`, `[data-auth-panel]` (same look in dialog and on `/login`).
      Check compiled size ≤ 30 KB.

## Infrastructure — analytics
- [ ] T8: `twig.yaml` globals `umami_script_url` / `umami_website_id` via `%env(default::…)%`;
      `UMAMI_SCRIPT_URL=` / `UMAMI_WEBSITE_ID=` in `src/.env` and `.env.example` (commented, empty =
      off); `_analytics.html.twig` (both set → one `defer` script with `data-exclude-search="true"`).
      Q2: include the partial in `coming_soon.html.twig`'s `<head>`.

## Infrastructure — deploy
- [ ] T9: `deploy.yml` smoke check: `/login`'s `public-*.css` href → 200 `text/css` (plan,
      Infrastructure section; capture into variables, no `curl | grep -q`).

## Tests (qa)
- [ ] T10: `tests/Functional/PublicStyles/PublicLayoutTest` (AC-1, AC-2, AC-6, AC-7a) and
      `AdminLayoutTest` (AC-3 incl. template scan with a ≥ 6-files guard).
- [ ] T11: `AnalyticsSnippetTest` (AC-8, AC-9 a–c) — verify the `$_SERVER`/`$_ENV`-before-
      `createClient()` approach reaches the container first; stop and report if not.
- [ ] T12: Bite proof — mutations (a)–(j) from the plan's test plan, each seen red, listed in the
      commit message. (f) is a local `docker build`.

## Docs
- [ ] T13: ADR-0006 amendment and ADR-0003 amendment (plan D7, D6); roadmap: tick item 5, add the
      "admin-styles (Tailwind)" line and "privacy-policy page before launch" note;
      docs/infrastructure.md §Analytics runbook (set the two vars on the box, restart `app`, check
      Umami's real-time view; post-deploy: open the overlay on prod — Q5); CLAUDE.md (status line,
      `make sass`/`sass.watch`, footguns: prod never compiled assets until now, never
      `asset-map:compile` in dev, `url()` relative to the entry file, token URLs must not render the
      layout); FORboehpyk.md chapter (the 404 nobody saw).

## Verify
- [ ] T14: `/verify` → hand-checks AC-7b–f in Chromium (Lighthouse a11y on four pages, JS off, 320 px,
      overlay), reviewer PASS, all ACs checked, docs updated.
