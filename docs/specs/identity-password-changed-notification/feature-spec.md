# Feature Spec: identity-password-changed-notification

> The *what & why*. Disposable — this dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `identity-password-changed-notification` — slice **5 of 7** of the `Identity` context.
- **Bounded context(s):** `Identity` only. No other context is created or touched — in particular
  **not** `Notification` (see technical plan §*Why `Identity` and not `Notification`*).
- **Related PRD items:** none directly. It is the consequence [ADR-0011](../../adr/0011-password-reset-challenges-modelled-in-the-domain.md)
  §Consequences recorded (*"`UserPasswordChanged` ships with no listener. It is the hook for the
  'your password was changed' security notification"*) and the roadmap's Identity line 5.
- **Governing ADRs:** [ADR-0008](../../adr/0008-domain-events-recorded-on-the-aggregate.md) (events
  dispatched after save, FQCN is the event name), [ADR-0010](../../adr/0010-event-delivery-and-transactional-mail.md)
  (sync dispatch, async mail, no outbox — **this slice meets the ADR's own "revisit" trigger; see
  technical plan Decision 3**), [ADR-0011](../../adr/0011-password-reset-challenges-modelled-in-the-domain.md).
- **Status:** Approved 2026-11-13 — as planned: no port (decision 1), outbox declined again (decision 3), roadmap line softened.
- **Date:** 2026-11-13

> **The one thing to know before reading anything else: this slice adds no Domain code and no
> Application code.** The event already carries everything (`UserId`, `occurredAt`), the address is
> one `UserRepository::findById()` away, and nothing *inside* the hexagon ever needs to send this
> mail — so there is no caller for a port to serve. The whole slice is one Infrastructure listener,
> two Twig templates and their tests. That outcome is the lesson: **a port exists so the inside can
> reach the outside; when the caller is already outside, a port is an interface with one
> implementation and no client.**

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Password-changed notice** | The security mail sent to an account's address every time its password changes: *what* happened, *when* (UTC), and *what to do if it wasn't you*. Informational — it carries no link that acts on the account. | The **reset mail** (`reset_password.*.twig`), which carries a live credential. The notice carries **none**, and must never become a second way into the account. |
| **Password change** | One `User::changePassword()` call, observed as one `UserPasswordChanged` event. **Today the only producer is `ResetPasswordWithTokenHandler`.** | A **reset request**. Requesting a reset changes nothing and sends no notice; only *completing* one does. |
| **Change instant** | `UserPasswordChanged::occurredAt()` — the same `Clock` instant the handler wrote to `identity_user.password_changed_at`. The "on X" in the notice. | The time the mail is sent or delivered, which with an async worker can be minutes later. The notice states when the **change** happened. |
| **Recovery path** | The absolute URL of `app_forgot_password` — what the notice tells a person who did not make the change to use. | A one-click "this wasn't me / lock my account" link. That is a new token-bearing challenge and is a non-goal. |

## User story

As a **muzbar account holder**, I want **an email every time my password is changed** so that
**if someone else changed it, I find out immediately and know how to take the account back.**

Secondary (Constitution §2): as the **developer**, I want this slice to show honestly that **a domain
event with a complete payload makes a new consequence an Infrastructure-only change** — and that the
right number of new Domain types for a pure side effect is zero.

### What the notice actually buys, stated without overselling

Today a password can only change through a reset link, and that link was mailed to the same address
the notice goes to. So the notice is **not** a defence against an attacker who fully controls the
mailbox — they can delete it. It *is* the signal in the cases where the link escaped without the
mailbox being owned (a forwarded mail, a shared mailbox, a link scraped from a log, a browser
history, a scanner) — and it becomes the decisive signal the day an authenticated "change password"
screen exists, where a hijacked session can change the password without touching the mailbox at
all. The listener subscribes to the **fact**, not to the reset use case, so that future path is
covered with no change here.

## In scope

- One Infrastructure listener, `NotifyUserOnPasswordChanged`, on `UserPasswordChanged`.
- Two templates: `email/password_changed.txt.twig` and `email/password_changed.html.twig`.
- Functional tests through the real reset flow; documentation duties.

## Non-goals (explicit — hold the line)

- **No new Domain or Application code** — no aggregate, value object, event, exception, port,
  command or handler. `UserPasswordChanged` is **not** widened (it already satisfies AC-33 of slice 3
  and must keep doing so).
- **No `Notification` bounded context.** Not created for one mail.
- **No IP address, device, user agent or location** in the notice. The event does not carry them,
  and adding request metadata to a domain event is a separate decision about personal data.
- **No "this wasn't me" one-click action.** It would be a new token-bearing challenge (a fourth
  aggregate with its own lifetime, replay and pruning rules).
- **No authenticated change-password screen**, and no other new producer of the event.
- **No notice on email-address change** — no such feature exists. When it does, *that* slice owns
  "notify the old address", which this listener cannot do (it reads the current address).
- **No translations / locale handling.** Both existing mails are English-only with no translation
  layer; a `trans()` here would be the only translated string in the system.
- **No transactional outbox, no queue-depth health check, no Sentry.** See technical plan Decision 3.
- **No rate limiter.** Every notice is caused by a completed reset, which is already behind three
  limiters and a single-use token; the notice cannot be triggered more often than a reset can.
- **No deduplication.** Two changes are two facts and produce two notices (AC-6).

## Acceptance criteria (the Definition of Done checklist)

- [ ] **AC-1 — one notice per successful reset.** A successful `POST /reset-password` sends
      **exactly one** email in that request (`assertEmailCount(1)`), addressed **only** to the
      account's stored address, with subject **`Your muzbar password was changed`**.
- [ ] **AC-2 — it states the instant.** Both the text and the HTML part contain the recipient's
      address and the change instant rendered as `Y-m-d H:i` **in UTC** followed by ` UTC`, and that
      instant equals the account's persisted `password_changed_at` (read from a fresh repository).
- [ ] **AC-3 — it states the recovery path.** Both parts contain the **absolute** URL generated for
      route `app_forgot_password` (asserted against `UrlGeneratorInterface::generate(...,
      ABSOLUTE_URL)` in the test, never a typed literal) and the sentence beginning
      `If you did not make this change`.
- [ ] **AC-4 — it carries no secret.** Neither part contains: the plaintext reset token used in the
      test, the substring `/reset-password`, the new password submitted, or the string `$argon2`/
      `$2y$` (a hash prefix). Asserted against the actual values the test used.
- [ ] **AC-5 — no notice without a change.** Zero password-changed notices are sent by: a reset POST
      with a weak password (422), a replayed link after a successful reset, and an unknown/invalid
      token. (Asserted by subject, since the forgot-password request mail is a different message.)
- [ ] **AC-6 — every change is a notice.** Two successful resets of the same account (two separate
      requested links) produce two notices — one per reset POST. Not deduplicated.
- [ ] **AC-7 — a mail failure never undoes or fails a committed change.** With the mailer forced to
      throw, a valid reset POST still answers the **same 302 to `app_login`** as the happy path, the
      new password is persisted (a login with it succeeds, or the stored hash verifies against it),
      and the reset request is redeemed.
- [ ] **AC-8 — the failure is logged for an operator, without personal data.** In AC-7's scenario
      exactly **one** `error`-level record is written whose context holds `user_id` (the account's
      UUID) and `exception`, and **neither its message nor any context value contains the account's
      email address**.
- [ ] **AC-9 — the mail is built like its siblings.** No `->from()` in the listener (sender comes
      from `mailer.yaml`); both a text and an HTML part; the HTML template does not extend
      `base.html.twig` and contains no `<img`, `<script` or remote stylesheet; under `APP_ENV=prod`
      the message is a `SendEmailMessage` on the `async` transport (no routing change in this slice).
- [ ] **AC-10 — the boundaries hold.** `make check` green (cs, stan max, deptrac
      `--fail-on-uncovered`, test). The diff contains **no** file under `src/src/Domain/` or
      `src/src/Application/`, **except** the docblock-only edit to `UserPasswordChanged.php` (task
      T3 — it named this listener as future work; amended at `/verify`, the diff won). Slice 3's `UserTest` AC-33 assertion (event carries no secret) still
      passes unmodified.
- [ ] **AC-11 — no new boot-path inputs.** No new env var, Composer package, Compose service,
      Messenger transport or migration. (ADR-0010's amendment: a new required boot input is a
      four-place change; this slice must need none.)
- [ ] **AC-12 — it reaches a real inbox in dev.** Manual: with `messenger-worker` **running, not
      restarting** (`docker compose ps`), a full dev reset produces the notice in Mailpit, and its
      link opens `http://localhost:8080/forgot-password`.
- [ ] **AC-13 — documentation duties done** (CLAUDE.md status, roadmap, ADR-0011 consequence
      discharged, ADR-0010 amendment if Decision 3 is approved, `FORboehpyk.md`).

## Failure contract

| Condition | Expected behaviour |
|---|---|
| Mailer/transport throws on `send()` (e.g. `messenger_messages` unwritable) | Caught in the listener. Reset response unchanged (302 → login), password stays changed, one `error` log with `user_id` + `exception`, no address logged (AC-7, AC-8). **No retry, no compensating mail** — accepted gap, see Decision 3. |
| Template render error (Twig syntax error, missing variable, missing template in the request's image) | **Caught in-request.** `Mailer::send()` renders a clone via `MessageListener` *before* queueing, so the exception reaches the listener's catch: one `error` log with `user_id`, reset response unchanged. |
| Worker-only failure (SMTP refusal, template missing only on a stale worker image) | Not visible to the request. Messenger retries, then the message lands in the `failed` transport. Surfaced only by `messenger:failed:show` until Sentry lands. The 2026-08-02 deploy fix (worker restarted on the new image) is what keeps a fresh template from hitting a stale worker. |
| `messenger-worker` stopped | Notice queued, not delivered until it restarts. Indistinguishable from healthy (ADR-0010 consequence) — accepted, unchanged. |
| `UserRepository::findById()` returns `null` (user gone between save and dispatch — not reachable today) | Treated as any other failure: caught, one `error` log with `user_id`, no mail, reset response unchanged. |
| Process dies between `users->save()` and dispatch | Password changed, no notice. ADR-0010's accepted commit→dispatch window; for this event there is no compensating action (Decision 3). |
| Reset fails before `changePassword()` (bad/stale/replayed token, weak password, limiter 429) | No event, no notice (AC-5). Existing responses unchanged. |
| The account is unverified at reset time | Not a branch: the reset handler verifies the email in the same use case, and the listener sends regardless of verification state. |
