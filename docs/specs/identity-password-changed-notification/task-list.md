# Task List: identity-password-changed-notification

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/identity-password-changed-notification`. Run `make check` before each commit.
> Check off as you go.

**BLOCKED on sign-off** of the three decisions in the [technical plan](./technical-plan.md)
§*Decisions needing sign-off*. If Decision 1 flips to option B, T2 splits into Domain port →
Application command + handler → Twig adapter → thin listener, in that order, and this list is
rewritten before starting.

**Rule for this slice:** no file under `src/src/Domain/` or `src/src/Application/` is added or
changed, except the one docblock edit in T3 (AC-10).

## Decisions & prerequisites

- [x] **T0:** If Decision 3 is approved: dated **amendment to ADR-0010** (no new ADR) — *"the 'revisit
      when an event has a consequence the user cannot compensate for' trigger was met by
      `UserPasswordChanged` on 2026-11-xx; outbox still declined"* with the three reasons from the
      plan. **No code in this commit.**
- [x] **T1 (checkpoint, not a change):** confirm no new env var, package, Compose service, transport
      or migration is needed; record it in the T2 commit message. **(AC-11)**

## Domain

- [x] — *(nothing: no aggregate, VO, event or port changes — Decision 1)*

## Application

- [x] — *(nothing: no command or handler — Decision 1)*

## Infrastructure

- [x] **T2:** `templates/email/password_changed.txt.twig` then `password_changed.html.twig` — text
      first, same content, UTC instant, absolute forgot-password URL as visible link text, no
      layout inheritance, no images/scripts/remote CSS, no secret of any kind, no statement of the
      cause. **(AC-2, AC-3, AC-4, AC-9)**
- [x] **T3:** `Infrastructure/Identity/EventListener/NotifyUserOnPasswordChanged.php` —
      `#[AsEventListener(event: UserPasswordChanged::class)]`; load user via `UserRepository`;
      `TemplatedEmail` with `changedAt` from the **event**; no `->from()`; catch `\Throwable`, log
      `error` with `user_id` + `exception` only. Comment states Decision 2 **and how it differs from
      `IssueVerificationOnUserRegistered`** (no compensating action). Same commit: update the
      *"Nobody listens to it today"* paragraph in `UserPasswordChanged`'s docblock — the only
      Domain-file touch, comment-only. **(AC-1, AC-7, AC-8, AC-9, AC-10)**

## Tests (qa)

- [x] **T4:** `tests/Functional/Identity/PasswordChangedNotificationTest.php` — happy path: one
      notice, recipient, subject, both parts contain address + UTC instant (vs freshly fetched
      `passwordChangedAt`) + generated absolute forgot-password URL; neither contains token,
      `/reset-password`, new password, hash prefix, `<img`, `<script`. One `KernelBrowser` per
      scenario; `ClearsRateLimiters` in `setUp()`. **(AC-1, AC-2, AC-3, AC-4, AC-9)**
- [x] **T5:** same file — no notice on 422 weak password, replayed link, invalid token; two resets →
      two notices. **(AC-5, AC-6)**
- [x] **T6:** same file — failure path: `disableReboot()`, throwing double on the **concrete**
      mailer service id (confirm via `debug:container`), `RecordingLogger` on `monolog.logger`;
      assert 302 → login, new password persisted, request redeemed, exactly one `error` record with
      `user_id` and `exception`, address absent from message and context. Prove it is not vacuous:
      temporarily remove the listener's catch / the listener and watch it fail. **(AC-7, AC-8)**
- [x] **T7:** regression sweep — grep existing reset-flow tests for `assertEmailCount` and fix any
      that now (correctly) see the extra notice, saying so in the commit. `make test` **twice**.
      **(AC-10)**

## Verify

- [x] **T8 (manual):** `make up.dev`; `docker compose ps` shows `messenger-worker` running, not
      restarting; do a full reset in the browser; the notice appears in Mailpit
      (`http://localhost:8025`) and its link opens `http://localhost:8080/forgot-password`.
      **(AC-12)**
- [x] **T9 — docs (AC-13):**
      - `CLAUDE.md` status block: slice 5 shipped; next is `identity-google-oauth`; one line on the
        lesson (*a complete event payload made a new consequence Infrastructure-only; no port without
        an inside caller*).
      - `docs/roadmap.md`: tick the slice; soften its value claim per the spec (*"…finds out, unless
        the attacker also owns the mailbox; decisive once an authenticated change-password path
        exists"*); note the second silent async failure class under the Sentry carry-over.
      - ADR-0011 §Consequences: mark *"`UserPasswordChanged` ships with no listener"* discharged
        (dated amendment line).
      - `FORboehpyk.md`: the slice story — why zero Domain code was the right answer, why the catch
        differs from the verification listener's, the ADR-0010 trigger that was met and declined, and
        the honest threat model of a security notice.
- [ ] **T10:** `/verify` → reviewer PASS, all ACs checked, docs updated.
