# Muzbar CI/CD

GitHub Actions for continuous integration and deployment to the single VDS. Principle: **the same
gates that pass locally (`make check`) are the gates CI enforces** — CI is not a different, stricter
universe, it's the referee that can't be skipped.

## Branching model — GitHub Flow (trunk-leaning)

One long-lived branch, short-lived feature branches, deploy from `main`. This is deliberately **not**
GitFlow (`develop`/`release/*`/`hotfix/*`) — that model targets versioned, scheduled-release software
with multiple maintainers, and its extra branches would be pure overhead for a solo dev continuously
deploying a web app to one box. More branches is not more advanced.

```
feature/x ──PR──▶ main ──(CI green)──▶ build image ──▶ push GHCR ──▶ [manual gate] ──▶ deploy to VDS
   │                │                                                     │                 │
 push, CI      squash-merge                                        you approve        pull image + migrate
 runs on PR    (main always                                        the release        + cache:clear + smoke
               releasable)                                                             check /health/ready
```

Rules:

- **`main` is always releasable** and protected: a PR may not merge until CI is green. A red `main` is
  therefore impossible by construction.
- **Every change is a branch → PR → squash-merge**, even solo. The PR is where CI runs, where the diff
  is reviewed as one unit, and where the `reviewer` agent runs `/verify`. It is the quality gate, not
  bureaucracy.
- **One path for everything.** Features are `feature/<name>`; fixes are `fix/<name>`; both go through
  the identical flow. No special hotfix branch type.
- **Keep `main` deployable at all times** via backward-compatible (expand→contract) migrations, so a
  half-finished feature never blocks a release. Full feature-flag infrastructure is deferred until real
  traffic justifies it.
- GitHub remote note: the owner runs two GitHub accounts via SSH host aliases — clone with the correct
  alias (wiki: `two-github-accounts-ssh-host-alias`).

## Pipeline

### CI — on every PR and push (`.github/workflows/ci.yml`)

Runs in containers mirroring the dev image so results match local `make check`.

1. **Setup** — checkout, PHP 8.4, Composer install (cached), boot a Postgres 16 + Redis 7 service.
2. **Lint** — `php-cs-fixer --dry-run --diff`.
3. **Static analysis** — `phpstan analyse` at max (+ symfony/doctrine extensions).
4. **Architecture** — `deptrac` — zero layer violations (the DDD boundary gate).
5. **Tests** — `phpunit` (Domain unit + Application/Feature against the service DB, DAMA rollback).
6. **Migrations sanity** — run `doctrine:migrations:migrate` against the fresh service DB so a broken
   migration fails CI, not prod.

A PR is mergeable only when all jobs are green. (Solo, but the PR gate is the point — it's the
enforced `/verify`.)

### Phase-gate jobs (added as phases land)

- **Search latency benchmark** (Phase 2 gate): a job that seeds 10k+ listings and asserts p95 < 200 ms
  @ 50 concurrent, with an `EXPLAIN`-based check that facets hit indexes. Runs on demand / nightly, not
  every PR (it's slow).

### CD — build on merge, deploy behind a manual gate (`.github/workflows/deploy.yml`)

Two jobs. The build runs automatically on merge to `main`; the deploy **waits for a human click**.

**Never `git pull` on the production box.** Prod does not build — it swaps an immutable image. Building
on prod risks a half-built box serving traffic, forgotten migration/cache steps, and no clean rollback.

**Job 1 — `build` (automatic, on push to `main`):**

1. **Build** the production image (multi-stage Dockerfile, `target: production`).
2. **Push** to **GHCR** (GitHub Container Registry), tagged with the commit SHA (and `latest`).

**Job 2 — `deploy` (gated — `environment: production`):**

Bound to a GitHub **Environment** (`production`) with a **required reviewer** protection rule. After
`build` succeeds, the job pauses; GitHub notifies you and the release goes live only when you approve
it in the Actions UI. You get a "hold" moment without any SSH ceremony, and an audit trail of who
released what and when.

Once approved, the job first **scp's the runtime config** (`docker-compose.yml` + `docker/nginx/default.conf`)
into `/home/muzbar-deploy/muzbar.com`, then runs, over SSH to the VDS (deploy key in GitHub Secrets,
restricted deploy user):
```
docker compose pull app worker scheduler
docker compose up -d app worker scheduler nginx
docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T app php bin/console cache:clear
```
Then **smoke check** — curl `/health/ready`; fail the deploy (and alert) on non-200.

**No source tree on prod.** The app code ships *inside* the image. The only files on the box are the
compose file + nginx conf (shipped by CI each deploy) and the hand-created `.env` (access-setup.md §2.1).
nginx can't bind-mount `public/` from a host it doesn't have, so the **app container publishes `public/`
into a shared named volume (`public_assets`) on start**, and nginx serves that volume read-only. The
copy runs every start — Docker only auto-seeds an *empty* volume, so without it a redeploy would leave
nginx serving the previous image's assets.

Migrations run **after** the new image is up but are written to be **backward-compatible** (expand →
migrate → contract) so a brief overlap never breaks. Zero-downtime is not a launch requirement; a few
seconds of restart is acceptable.

> **Trigger policy:** manual gate for the launch phase, while every release deserves eyes. Relaxing to
> **auto-deploy on merge** later is a one-line change — remove the Environment's required-reviewer rule
> (or drop the `environment:` binding). The workflow file is otherwise identical. Revisit once CI +
> smoke-check + one-command rollback have earned your trust.

## Rollback

Images are SHA-tagged in GHCR. Roll back by re-pointing the compose tag to the previous SHA and
`up -d`. Keep migrations reversible or paired with a data-safe down path; when a migration can't be
reversed safely, restore from the pre-deploy `make db.dump`.

## Secrets

- `GHCR_TOKEN`, `SSH_DEPLOY_KEY`, `SSH_HOST`, `SSH_USER` as **Environment secrets on the `production`
  Environment** — not repository secrets. These are used only by the gated `deploy` job, so scoping
  them to the environment means they're exposed only to jobs that declare `environment: production`
  and are decrypted only *after* the required-reviewer approval — the same gate as the deploy itself.
- App/prod secrets live in `.env` on the box — never in the workflow, image, or repo.

## Notifications

CI/CD failures ping the owner (email or the Telegram channel already wired for this environment).
A red `main` is the top priority — `main` is always releasable.

## Build order (Phase 0)

`ci.yml` first (it gates everything), then `deploy.yml` once a first image builds and the VDS deploy
user + GHCR access exist. When wiring `deploy.yml`, create the GitHub **`production` Environment** and
add yourself as a **required reviewer** — that rule *is* the manual gate. Also set branch protection on
`main` (require CI green, squash-merge only). The benchmark job arrives with Phase 2.
