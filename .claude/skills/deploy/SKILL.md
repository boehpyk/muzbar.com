---
name: deploy
description: The muzbar deploy runbook — build an immutable image, push to GHCR, and release to the single VDS behind the manual gate, plus rollback and the pre-deploy footgun checklist. Use when deploying, cutting a release, or debugging the CD pipeline.
---

# Deploying muzbar

Principle: **production never builds — it pulls an immutable, SHA-tagged image** (ADR-0003, cicd.md).
Never `git pull` on the box.

## Normal release (via CI/CD)
1. Merge to `main` → CI is green → the `build` job pushes `ghcr.io/<owner>/muzbar:<sha>` to GHCR.
2. The `deploy` job is bound to the GitHub **`production` Environment** with a required reviewer — it
   **waits for your approval**. Approve in the Actions UI to release.
3. On approval, over SSH to the VDS:
   ```
   docker compose pull app worker scheduler
   docker compose up -d app worker scheduler nginx
   docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction
   docker compose exec -T app php bin/console cache:clear
   ```
4. **Smoke check:** `curl -fsS https://muzbar.com/health/ready` must return 200. Fail/alert otherwise.

Migrations are backward-compatible (expand → migrate → contract) so the brief container overlap never
breaks.

## Pre-deploy footgun checklist
- Traefik pinned to its network (`--providers.docker.network=traefik`).
- `docker-compose.yml` (prod) exposes **no** datastore ports; Redis has a password.
- Named volumes only (no stale anonymous Postgres volume).
- A fresh `make db.dump` exists before any migration that isn't cleanly reversible.

## Rollback
Images are SHA-tagged in GHCR. Re-point the prod image tag to the previous SHA and `up -d`. If a
migration can't be safely reversed, restore from the pre-deploy dump.

## Manual first deploy / bootstrap
On a new box: create the external `traefik` network, put real secrets in the root `.env`, ensure the
deploy user can pull from GHCR, then run the deploy steps above once by hand to verify.
