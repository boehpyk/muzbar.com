# FOR boehpyk — The Muzbar Story (So Far)

A plain-language companion to muzbar.com: what we're building, how the pieces fit, why we chose what we
chose, and the lessons hiding in those choices. Written to be read, not filed. It grows with the
project — right now it's the story of **day one: the setup**, because that's where the most consequential
decisions get made and the least code exists to distract from them.

---

## What muzbar actually is

Imagine a musician in Gothenburg hunting for one absurdly specific thing: a *left-handed, 5-string
Fender Precision V bass*. On a normal marketplace he'd type words into a box and drown in a thousand
right-handed 4-strings. Muzbar flips that: instead of a keyword box, it hands him **dropdowns** — brand,
model, string count, orientation — and returns only exact matches. Then it shows him rehearsal studios
on a map within 5 km. That's the whole product in one sentence: **structured search over structured
data, plus a local map, on a single small server, run by one person.**

The business model is charmingly modest and therefore honest: it just needs to make enough from premium
listings and featured-shop subscriptions to **pay for its own hosting** (plus a little). No VC fantasy —
a self-funding machine. That constraint quietly shapes every technical decision toward *cheap and
boring*.

## The shape of the thing (architecture)

We're using **Domain-Driven Design** with a **hexagonal (Ports & Adapters)** architecture. If that
sounds fancy, here's the kitchen analogy that makes it click:

- The **Domain** is the *recipe* — pure ideas about food, written so a cook in any kitchen could follow
  them. It knows nothing about your specific oven. In code: pure PHP describing listings, categories,
  and their rules, with **zero** mention of Symfony or Doctrine.
- The **Application** layer is the *cook* — it follows a recipe step by step ("create a listing"), but
  still doesn't care whether the oven is gas or electric.
- The **Infrastructure** layer is the *actual kitchen* — the specific oven, the Postgres database, the
  Stripe card reader, the Google login. Swappable appliances.

The trick that keeps this honest is a **port**: an interface the Domain defines ("I need *something* that
can search," `SearchPort`) without knowing what fulfills it. Today a Postgres adapter plugs into that
port. Tomorrow, if we ever need it, a Meilisearch adapter plugs into the *same* port and nothing in the
recipe changes. Appliances change; recipes don't.

Why go to this trouble on a solo project? Two reasons. First, it's genuinely the right fit for a domain
this rule-heavy (a category's attributes, a listing's 30-day life, an option you can't delete while
listings use it — these are *invariants*, and DDD is the discipline for protecting invariants). Second,
and just as important: **learning DDD and Symfony deeply is an explicit goal of this project.** When two
paths are equally good for the product but one teaches DDD more honestly, we take the teacher. This is
tuition, and we're choosing to pay it on purpose.

The boundary between layers isn't enforced by willpower — willpower fails at 1 a.m. It's enforced by
**Deptrac**, a tool that fails the build if the Domain ever imports Doctrine. Good engineers don't trust
themselves to remember rules; they make the rules mechanical.

## Two decisions worth remembering (because you almost went the other way)

This is the part to reread in six months, because it's where the *thinking* lives.

**1. We deleted Meilisearch before we ever installed it.** The PRD assumed a dedicated search engine
(Meilisearch or Elasticsearch). It sounds right — "search" → "search engine." But stop and look at what
muzbar's search actually *is*: filtering by brand, model, string count, orientation. That's not fuzzy
prose-hunting; it's **structured filtering over typed columns** — Postgres's home turf, easily under the
200 ms budget with the right indexes. A search engine would have bought us a second container, a
constant *sync* job copying data from Postgres into the index, and a nasty failure mode where the two
drift apart and search **silently returns nothing** (a bug you've hit before — it's in your wiki). You
initially picked Meilisearch anyway; then, weighing the trade-off out loud, you reversed. The lesson,
and it's a deep one: **"search" is a word, not an architecture.** Match the tool to the *shape of the
data*, not to the noun in the requirement. And when you do add complexity, add it because a measurement
forced you to — which is why Phase 2 has an actual latency benchmark as its gate.

**2. We chose the harder stack on purpose.** Your other live project, samolit.com, is a polished Laravel
+ Livewire setup on the very same server. Muzbar is the same *shape* of app. The rational, velocity-
maximizing move was to clone it. We didn't — because the goal here isn't only to ship muzbar, it's to
learn Symfony and DDD. So we picked **Symfony 7 + Doctrine + Twig**, knowing it means rebuilding the
Makefile, the Docker wiring, the hooks, and moving slower at first. The lesson: **be explicit about
which goal you're optimizing.** "Fastest to ship" and "most learned" are different objectives, and the
mistake is optimizing one while pretending you're optimizing the other. We wrote the reason into an ADR
so future-you doesn't "correct" this decision without knowing it was deliberate.

Notice the symmetry, and why it's *not* a contradiction: on search we chose the *simpler* tool (Postgres
over Meilisearch); on framework we chose the *harder* one (Symfony over Laravel). Different objectives.
Search was optimizing "cheap and correct for the workload." Framework was optimizing "learn deeply."
Consistency isn't using the same tool everywhere — it's applying the same *honest reasoning* everywhere.

## The technologies, briefly, and why each earns its slot

- **PHP 8.4 / Symfony 7** — explicit, component-based, great for modeling a domain out loud. The
  morphing listing wizard (the form that reshapes when you pick a category, without a page reload) is
  built with **Symfony UX Live Components** — Symfony's answer to Livewire, and exactly the right tool.
- **PostgreSQL 16** — the one datastore. Holds the business data *and* does the search. One source of
  truth, no sync path.
- **Redis 7** — cache, sessions, and the queue transport for **Symfony Messenger** (async email) and the
  **Scheduler** (the 30-day ad-expiry clock).
- **Docker Compose + Traefik + Nginx** — the whole app is a handful of containers on one VDS, behind a
  shared Traefik proxy that already terminates TLS for your other site. Near-zero marginal hosting cost,
  which is the entire business model.
- **Stripe / Postmark (or SendGrid) / Leaflet** — payments, transactional email, and maps — each behind
  a port, each swappable.
- **Umami** — self-hosted analytics, which happens to measure the exact things the PRD cares about
  (did people finish the form? how fast did they publish?) without a third-party tracker.

## The traps we've already mapped (so we don't fall in)

Your own war-stories became a **footgun checklist** baked into the infra plan and the git hooks — because
the best time to defuse a landmine is before you're standing on it:

- **Traefik's silent 30-second 504.** A container on two networks; Traefik picks an IP it can't reach.
  TLS succeeds, then… nothing, then a 504 at *exactly* 30 seconds, with no logs. Fix baked in: pin
  Traefik to its network. The meta-lesson: **infrastructure lies** — the failure often looks like one
  thing and is caused by another, and "no logs" is itself a clue.
- **The firewall that isn't.** Docker writes iptables rules *directly* and cheerfully bypasses UFW. A
  `ports:` mapping on Redis is a hole to the open internet even if UFW says "deny." So: datastores
  expose **no** ports in production; admin tools bind to `127.0.0.1` and you reach them over SSH. A
  pre-commit hook now *refuses* a commit that opens a datastore port.
- **The volume that remembers the old password.** Postgres only sets its password on *first* init; a
  stale anonymous volume keeps the old one forever, and `down -v` won't remove it. So: **named volumes
  only.**
- **Health checks that lie.** A check returning `200` just because PHP is running would have hidden every
  bug above. Ours *probes* Postgres and Redis and separates "am I alive" from "am I ready."

The through-line: **good engineers turn scars into guard rails.** A bug you fix twice is a process
failure; a bug you *encode* into a hook is a lesson that compounds.

## How work actually happens here (the SDLC)

Lean **Spec-Driven Development**. Not the 16-agent industrial pipeline — the SDD literature literally
has an adoption test ("regulated / multi-person / brownfield / audited → go heavy; else stay light"),
and a solo greenfield project is a clear *stay light*. So the loop is small and human-gated:

**`/plan`** (write a short, executable spec and *approve it before coding* — the cheapest place to catch
a wrong idea) → **`/implement`** (build Domain → Application → Infrastructure, in that order) →
**`/verify`** (linters, PHPStan at max, Deptrac, tests, and a read-only reviewer agent must all say
green).

There's a durable/disposable split that's easy to miss and important to hold: the **Constitution** and
**ADRs** live for years and change only on purpose; the **per-feature specs die** once the feature ships
and its behavior lives in tests. Don't lovingly maintain a document you're about to delete — make it
executable, use it, let it go.

## Where we are, and what's next

Right now the repo is **all docs, no code** — and that's the point of day one. There's a Constitution,
five ADRs, an SDLC, an infra plan, a phased roadmap, a tooling spec, and a CI/CD plan. Phase 0 turns the
plan into a skeleton: the Symfony app, the Docker stack, the quality gates, the `.claude/` agents, and
GitHub CI/CD. Then Phase 1 builds the beating heart — the dynamic category/attribute schema, the piece
that's both the product's cleverest feature and its biggest DDD lesson.

If future-you reads only one line: **we wrote the reasons down before we wrote the code, so that changing
our minds later is a decision, not an accident.**
