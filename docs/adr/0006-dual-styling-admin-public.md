# ADR-0006: Dual styling — Tailwind for admin, hand-authored SCSS for public

- **Status:** Accepted
- **Date:** 2026-07-23
- **Refines** [ADR-0001](./0001-framework-symfony.md), which named Tailwind as the single styling choice.

## Context

Two UIs with opposite priorities:

- **Admin UI** — internal CRUD and dashboards (category/attribute schema management, moderation,
  analytics). Nobody outside sees it; **velocity and consistency** matter, bespoke design does not.
  Tailwind's utility classes are ideal here.
- **Public UI** — the marketplace and map directory that **is the brand**. A distinctive,
  non-generic look matters; the "templated Tailwind" aesthetic is a liability. Full control over the
  CSS is wanted, and hand-authoring CSS/SCSS also serves the project's DDD/Symfony **learning goal**
  (CSS architecture is worth practising deliberately).

## Decision

- **Admin UI:** **Tailwind CSS**, compiled by the standalone Tailwind CLI via
  `symfonycasts/tailwind-bundle` — **no Node**.
- **Public UI:** hand-authored **SCSS**, compiled by the standalone Dart Sass binary via
  `symfonycasts/sass-bundle` — **no Node**.
- **Asset pipeline:** Symfony **AssetMapper** (Node-free, importmap-based) serves both compiled
  stylesheets. Plain CSS would need no compiler at all; SCSS and Tailwind each add one standalone
  binary — not a Node toolchain.
- **Separation:** two entry stylesheets loaded by their respective layouts — `admin.css` (Tailwind,
  including its Preflight reset) on the admin layout, `public.css` (SCSS) on the public layout. They
  never load together, so Tailwind's reset/utilities cannot leak into public pages, and vice versa.
- **Shared design tokens:** brand colors, spacing, and typography are defined **once** as CSS custom
  properties in a single `:root` tokens file that both consume (Tailwind reads them through its theme
  config). One source of truth for the palette.

## Alternatives

- **Tailwind everywhere (ADR-0001's original):** fastest, one system — but pushes the public UI toward
  a generic utility-first look and yields less CSS-architecture learning. Rejected for the public side.
- **Plain modern CSS instead of SCSS for public:** viable — native nesting, custom properties, and
  `@layer` now cover much of what Sass offered, at **zero compile step**. Kept as a low-cost drop-in
  fallback; SCSS chosen for mixins/functions/partials and the learning value.
- **Webpack Encore / Vite (Node build):** more capable JS/asset handling, but adds a Node toolchain to
  Docker and CI. Deferred until a genuine need for a heavy client-side JS build appears.

## Consequences

- **Easy:** admin ships fast; the public side gets a bespoke, ownable design; the toolchain stays
  **PHP-only** (two small standalone binaries), fitting the one-box, cheap-and-boring philosophy and
  keeping the Docker image and CI free of Node.
- **Hard / watched:** two styling systems = two mental models and a risk of **design-token drift** —
  mitigated by the shared custom-properties tokens file. Keep each build's output scoped to its layout
  so styles don't bleed. If the public UI ever needs a rich JS build, revisit AssetMapper vs Vite.
