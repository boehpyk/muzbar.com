# Access Setup — least-privilege credentials for muzbar

How to give an automated agent (Claude Code, GitHub Actions) exactly enough access to work on
muzbar and **nothing else** on this GitHub account or this VDS. The principle is **least
privilege**: every credential has one job, so a mistake or a leaked key stops at muzbar's boundary
instead of cascading into your other repos or your other projects on the box.

Two credentials, two jobs:

| Credential | Held by | Can touch | Cannot touch |
|---|---|---|---|
| GitHub **deploy key** (write) | your laptop | only the `muzbar.com` repo | any other repo, account settings, orgs |
| VDS **`muzbar-deploy`** SSH key | GitHub Secrets only | muzbar's containers + `/home/muzbar-deploy/muzbar.com`, lands you in that user | your personal files / home |

> **Threat model.** These defend the realistic solo-dev risk — *"an agent fumbles a command and
> changes something it shouldn't"*. A bad `git push` can't escape the repo; a bad command on the
> box lands in the `muzbar-deploy` user and folder, not your personal files. They do **not** defend
> container-breakout-to-root (see [Residual risk](#residual-risk)); that's accepted, and consistent
> with how the box's other projects already run.

---

## Part 1 — GitHub: access to only `boehpyk/muzbar.com`

A normal account SSH key can touch every repo you have. A **deploy key** is an SSH key registered
to a *single repository*, so it physically cannot reach anything else.

### 1.1 Generate a dedicated key (on the machine the agent runs on)

```bash
ssh-keygen -t ed25519 -f ~/.ssh/muzbar_deploy -C "muzbar-deploy" -N ""
```

### 1.2 Register the public key on that repo only

GitHub → `boehpyk/muzbar.com` → **Settings → Deploy keys → Add deploy key**

- Title: `muzbar agent`
- Key: contents of `~/.ssh/muzbar_deploy.pub`
- **✅ Allow write access** (the agent needs to push).

### 1.3 Point the repo's remote at this key via an SSH host alias

This box runs two GitHub accounts via SSH host aliases (see wiki:
`two-github-accounts-ssh-host-alias`), so an alias is the right tool anyway. Add to `~/.ssh/config`:

```
Host github-muzbar
    HostName github.com
    User git
    IdentityFile ~/.ssh/muzbar_deploy
    IdentitiesOnly yes
```

`IdentitiesOnly yes` stops SSH from silently falling back to your account-wide key. Then:

```bash
cd /home/boehpyk/Work/Sites/muzbar.com
git remote set-url origin git@github-muzbar:boehpyk/muzbar.com.git
git remote -v
```

### 1.4 Verify

```bash
ssh -T git@github-muzbar
# → "Hi boehpyk/muzbar.com! You've successfully authenticated..."
```

It greets you as the **repo**, not your username — that confirms the scope.

---

## Part 2 — VDS: a deploy user scoped to muzbar

The VDS runs a **shared Traefik** on the system Docker daemon that fronts several projects and
handles routing + certs. muzbar deploys onto that same system Docker (Pattern B — build image
elsewhere, pull it here; see [cicd.md](./cicd.md)), so it joins the existing Traefik setup rather
than fighting it.

> **Why not rootless Docker?** It would give stronger container-level isolation, but a rootless
> daemon lives in its own network namespace that the shared system-Docker Traefik can't discover —
> you'd hand-wire cross-namespace routing. Not worth the fragility for a solo box. Decision recorded
> in [Residual risk](#residual-risk).

All commands in this part run **on the VDS** as a sudo user, unless noted.

### 2.1 Create the caged deploy user

```bash
sudo useradd --create-home --shell /bin/bash muzbar-deploy
sudo passwd -l muzbar-deploy                 # key-only login, no password
sudo usermod -aG docker muzbar-deploy        # same shared Docker + Traefik as other projects
sudo -u muzbar-deploy mkdir -p /home/muzbar-deploy/muzbar.com
```

What lives in `/home/muzbar-deploy/muzbar.com`, owned by `muzbar-deploy`:

- `docker-compose.yml` and `docker/nginx/default.conf` — **shipped by CI** on every deploy (scp step
  in `deploy.yml`). Don't hand-edit them on the box; they'd be overwritten. The app *code* never
  lands here — it ships inside the image and nginx serves `public/` from a shared volume the app
  container fills on start (see cicd.md §CD).
- `.env` with prod secrets — **created by hand, once** (next step). CI never *provides* it and never
  writes secrets to it; it only keeps a single non-secret `MUZBAR_IMAGE=<sha>` line in sync (so
  hand-run `docker compose` on the box targets the deployed image instead of trying to build).
  **Secrets stay on the box** — never in the repo, image, or workflow (cicd.md §Secrets).

There is no prod override file: production is the base `docker-compose.yml` alone (the dev override is
never auto-loaded).

#### Bootstrap the prod `.env` (one time, on the box, as `muzbar-deploy`)

`docker compose` reads this from the deploy dir. Generate real secrets — never reuse the dev
placeholders from `.env.example`:

```bash
sudo -u muzbar-deploy tee /home/muzbar-deploy/muzbar.com/.env >/dev/null <<'EOF'
APP_ENV=prod
APP_SECRET=__PASTE_A_32_BYTE_HEX__          # openssl rand -hex 32
DB_DATABASE=muzbar
DB_USERNAME=muzbar
DB_PASSWORD=__STRONG_UNIQUE__
DATABASE_URL=postgresql://muzbar:__STRONG_UNIQUE__@postgres:5432/muzbar?serverVersion=16&charset=utf8
REDIS_PASSWORD=__STRONG_UNIQUE__
REDIS_URL=redis://:__STRONG_UNIQUE__@redis:6379
EOF
sudo -u muzbar-deploy chmod 600 /home/muzbar-deploy/muzbar.com/.env
```

Keep `DB_PASSWORD` and the password inside `DATABASE_URL` identical, likewise `REDIS_PASSWORD` and the
one in `REDIS_URL` — the compose file wires the datastores from the first and the app connects with
the second.

> The effective "scoped folder" is `/home/muzbar-deploy/muzbar.com`, **not** a path under your
> personal home. A separately isolated user shouldn't live inside `/home/boehpyk/` (mode `750`,
> which it can't traverse anyway) — its own home is both cleaner and more isolated.

### 2.2 CI's SSH key (lives in GitHub Secrets, not on your laptop)

On your **laptop**, generate a dedicated keypair for CI:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/muzbar_ci_deploy -C "muzbar-ci-deploy" -N ""
```

On the **VDS**, authorize the public half:

```bash
sudo -u muzbar-deploy mkdir -p /home/muzbar-deploy/.ssh
sudo -u muzbar-deploy chmod 700 /home/muzbar-deploy/.ssh
# paste ~/.ssh/muzbar_ci_deploy.pub into this file:
sudo -u muzbar-deploy tee -a /home/muzbar-deploy/.ssh/authorized_keys
sudo -u muzbar-deploy chmod 600 /home/muzbar-deploy/.ssh/authorized_keys
```

Once the private key is in GitHub Secrets (2.3), **delete it from your laptop** — CI should be the
only holder.

### 2.3 GitHub Actions secrets

Add these as **Environment secrets on the `production` Environment** — *not* repository secrets
(matches cicd.md §Secrets). The gated `deploy` job is the only thing that uses them, so scoping them
to the environment keeps them locked behind the same required-reviewer gate as the deploy: they're
visible only to jobs declaring `environment: production` and aren't decrypted until you approve.

`boehpyk/muzbar.com` → **Settings → Environments → `production` → Environment secrets**. (Create the
`production` Environment first and add yourself as a required reviewer — see cicd.md §Build order —
if you haven't already; that reviewer rule *is* the manual deploy gate.)

| Secret | Value |
|---|---|
| `SSH_DEPLOY_KEY` | contents of `~/.ssh/muzbar_ci_deploy` (the **private** key) |
| `SSH_HOST` | VDS IP / hostname |
| `SSH_USER` | `muzbar-deploy` |
| `GHCR_TOKEN` | classic PAT with **only** `read:packages` scope — pulls the image from GHCR (the box only needs pull; CI pushes with the workflow's built-in `GITHUB_TOKEN`). Fine-grained tokens are avoided here: their GHCR "Packages" permission is inconsistent and needs the package pre-linked to the repo. |

### 2.4 Smoke-test the chain before trusting CI

CI connects non-interactively (`ssh user@host 'command'`). Prove the whole path by hand from your
laptop:

```bash
ssh -i ~/.ssh/muzbar_ci_deploy muzbar-deploy@YOUR_VDS 'docker ps'
```

A clean container list means the key → deploy user → system Docker chain works, and
`deploy.yml`'s commands (`docker compose pull` / `up -d` / migrate / cache:clear) will run as
written.

---

## Residual risk

Membership in the `docker` group is **root-equivalent on the box** — `muzbar-deploy` could, in
principle, escape its folder via Docker. This is **accepted**, because:

- It's already true of however the box's other projects deploy — this adds no new hole, it matches
  the existing posture.
- It fully covers the stated threat: fumbled pushes can't leave the repo, and fumbled commands land
  in muzbar's own user/folder, not your personal files.

If stronger container-breakout isolation is ever wanted, the clean path is a **whole-box** move
(shared rootless setup, or Podman) — not rootless-per-project. That's a separate decision, not a
muzbar one.

## Not set up yet (deferred)

- **Local-agent VDS access.** Deploys run from CI, so Claude Code on the laptop needs no VDS login
  today. If read-only debugging on the box is wanted later, add a *separate, weaker* user (log/tail
  access, no `docker` group) — never reuse `muzbar-deploy`.

---

## Blast-radius recap

| Credential | Held by | Job |
|---|---|---|
| `muzbar_deploy` | laptop `~/.ssh` | push/pull the `muzbar.com` repo, and only that repo |
| `muzbar_ci_deploy` | GitHub Secrets | let CI deploy to the `muzbar-deploy` user, and only muzbar's folder/containers |

Two keys, two jobs. Leak either and you rotate just that one — the damage stops at muzbar.

---

For the full menu of alternatives we weighed (deploy key vs PAT vs GitHub App; docker group vs
rootless vs sudo rules) with pros and cons of each, and *why* we didn't take the others, see
[explanations/access-scoping-options.md](./explanations/access-scoping-options.md).
