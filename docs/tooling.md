# Muzbar SDLC Tooling — Agents, Commands, Hooks, Skills

The concrete `.claude/` inventory that operationalizes the [SDLC](./sdlc.md). Style follows the
owner's samolit conventions (frontmatter, model tiering, read-only reviewer, precise rule tables) but
the **roster is deliberately lean** — this is solo SDD, not a 16-agent pipeline. This doc is the
**spec**; the files are built in Phase 0.

## Design principles

- **Few, sharp agents.** Each has one job and an explicit "what you do NOT do" list.
- **Model tiering.** Cheap models explore/route; strong models design and build; reviewer reads.
- **Read-only reviewers.** Review/audit agents never Edit/Write — an auditable, drift-free trail.
- **DDD-coaching, not just DDD-policing.** Because learning DDD is a ranked goal, the reviewer and a
  dedicated DDD lens *explain the pattern* when they flag a violation, not just reject it.

## Agents (`.claude/agents/`)

| Agent | Model | Role | Writes code? |
|---|---|---|---|
| `planner` | opus | From a request + Constitution/ADRs, writes the three spec files (`feature-spec`, `technical-plan`, `task-list`). Identifies bounded context(s), aggregates, ports. Stops for human approval. | No |
| `domain-modeler` | opus | Implements the **Domain + Application** layers: aggregates, value objects, domain events, ports, command/query handlers. Pure PHP; zero framework imports. The DDD heart. | Domain/App only |
| `symfony-dev` | opus | Implements the **Infrastructure** layer: Doctrine adapters + mappings, controllers/Live Components, Messenger, Scheduler, security, DI wiring, migrations. | Infra only |
| `qa` | sonnet | Writes tests **after** implementation: Domain unit tests (no kernel), Application/Feature tests (real DB, DAMA rollback). Independent of the implementer. | Tests only |
| `reviewer` | opus | **Read-only.** Checks changed files against Constitution §4/§6/§8, DDD tactical patterns, and Symfony/Doctrine conventions. Returns PASS / NEEDS CHANGES with CRITICAL/MAJOR/MINOR/STYLE findings, each with a one-line *why this pattern matters* (teaching mode). | No |
| `devops` | sonnet | Docker, Compose, nginx, PHP-FPM, CI, deploy, the footgun guards. | Infra config only |

> The `planner` doubles as the lightweight orchestration point: for a full feature the human runs
> `/plan` → approves → `/implement` (domain-modeler → symfony-dev → qa) → `/verify` (reviewer). No
> separate always-on orchestrator agent; the commands sequence the work.

## Commands (`.claude/commands/`)

| Command | Does |
|---|---|
| `/new-feature <name>` | Scaffolds `docs/specs/<name>/` from `_template/` and a `feature/<name>` branch. |
| `/plan <name>` | Invokes `planner`; fills the three spec files; presents for human approval. |
| `/implement` | Works the approved `task-list.md` in DDD order via `domain-modeler` → `symfony-dev` → `qa`, `make check` per task. |
| `/verify` | Runs all gates (`make check`) + `reviewer`; reports PASS/NEEDS CHANGES; enforces the 3-iteration escalation rule. |
| `/adr <title>` | Creates the next-numbered ADR from the ADR-0000 format. |

## Hooks (`.claude/hooks/` + git hooks in `scripts/git-hooks/`)

**Claude Code hooks** (harness-executed, configured in `.claude/settings.json`):

| Hook | Trigger | Action |
|---|---|---|
| `pre-write-guard` | before Edit/Write to `Domain/` | warn if the diff introduces `use Symfony\` / `use Doctrine\` (Domain purity) |
| `post-implement-check` | after `/implement` tasks | run `make cs stan deptrac` and surface failures inline |

**Git hooks** (tracked in `scripts/git-hooks/`, wired via `make hooks.install` → `core.hooksPath`):

| Hook | Action |
|---|---|
| `pre-commit` | `make check` (cs + stan + deptrac + test on changed scope); **block** if a compose file publishes `postgres`/`redis` ports or omits the Traefik network pin (infra footgun guard); **block** committed secrets / a non-example `.env`. |
| `commit-msg` | enforce imperative style; require an ADR/Constitution touch when a `[decision]` trailer is present. |

## Skills (`.claude/skills/`)

| Skill | Use |
|---|---|
| `ddd-feature` | The canonical recipe for a new DDD slice in this repo: context choice, aggregate boundary, value objects, events, port, handler, adapter, wiring — with muzbar examples. Reinforces the learning goal. |
| `postgres-facet-search` | How to add/extend a facet: schema-layer vs hot-column promotion (ADR-0004), index choice, `EXPLAIN` check against the 200 ms budget, FTS/pg_trgm mangling + sanitizer. |
| `deploy` | The build→push→SSH→migrate deploy runbook + rollback; the footgun checklist as a pre-deploy gate. |

## Build order (Phase 0)

1. `reviewer` + `domain-modeler` + `symfony-dev` (unblock the core loop).
2. `planner`, `qa`, `devops`.
3. Commands, then git hooks (`pre-commit` first — it guards everything after).
4. Skills last (they encode patterns you'll refine as the first real features land).
