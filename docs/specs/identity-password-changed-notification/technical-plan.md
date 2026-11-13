# Technical Plan: identity-password-changed-notification

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

## What exists today (read before designing — verified 2026-11-13)

- **`UserPasswordChanged`** (`Domain/Identity/Event/`) carries `userId(): UserId` and
  `occurredAt(): \DateTimeImmutable`. Nothing else — no hash, no plaintext (slice 3 AC-33, asserted by
  reflection in `UserTest`). Its docblock already promises: *"The listener will subscribe here and need
  nothing else added to the payload… the notification mail needs the `UserId` and the instant, and
  reads the address through the repository."* This plan keeps that promise.
- **Producers:** recorded by `User::changePassword()`, which has exactly **one** caller —
  `ResetPasswordWithTokenHandler`. No authenticated change-password path exists; the
  `muzbar:identity:verify-email` break-glass command does not touch the password. So "which use cases
  should mail?" has a one-row answer today, and the listener is written against the fact so a future
  producer is covered automatically.
- **Dispatch point:** `ResetPasswordWithTokenHandler` saves request then user, then
  `$this->events->dispatch(...$user->releaseEvents(), ...$request->releaseEvents())`. The user's
  buffer is `[UserPasswordChanged, (UserEmailVerified if newly verified)]`.
  `SymfonyDomainEventDispatcher` loops them through Symfony's PSR-14 dispatcher **synchronously, in the
  HTTP request**, after commit.
- **Listener precedent:** `IssueVerificationOnUserRegistered` — `#[AsEventListener(event: FQCN)]`,
  catches `\Throwable`, logs `error` with `user_id` + `exception`, never the address.
- **Mail precedent:** `TwigVerificationMailer` / `TwigPasswordResetMailer` — `TemplatedEmail`, text +
  HTML templates under `templates/email/`, no `->from()`, absolute URLs via `UrlGeneratorInterface`,
  dates rendered `|date('Y-m-d H:i', 'UTC')`, English-only, no layout inheritance, no remote resources.
  `SendEmailMessage` → `async` (Doctrine) in dev/prod, `sync` under `APP_ENV=test`.

## Decisions needing sign-off

**Decision 1 — No new port; the listener sends the mail itself.** *(recommended)*

The two existing mailers have Domain ports (`VerificationMailer`, `PasswordResetMailer`) because an
**Application handler** calls them mid-use-case and the Application layer may not import Symfony.
Here there is no use case: nothing in Domain or Application decides anything, changes anything, or
needs to send this mail. The only caller is an Infrastructure listener, which may import Symfony
Mailer directly. A `PasswordChangedNotifier` port would be an interface with one implementation and
no inside caller — the shape of a port without its reason.

*Constitution §4.3 reading:* "every external dependency crosses a Domain-defined interface" protects
Domain/Application from vendors so adapters can be swapped. No Domain or Application code gains a
vendor dependency here, and the vendor swap the Constitution cares about (Postmark ↔ SendGrid) is a
`MAILER_DSN` change beneath Symfony Mailer that this listener never sees. Deptrac enforces the rule
mechanically and stays green. **This is a reading, not a deviation — but it is the first mail in the
system without a port, so it is the human's call.**

*Rejected alternative (B):* `NotifyPasswordChanged` command + `NotifyPasswordChangedHandler` in
Application, a `PasswordChangedMailer` port in Domain, `TwigPasswordChangedMailer` adapter, listener
delegating to the handler (the `IssueVerificationOnUserRegistered` shape). Four extra files and a
handler that only reads and forwards. Choose B only if you want every outgoing mail to have a Domain
port as a house rule — in which case it should be written down as one.

**Decision 2 — Failure semantics mirror `IssueVerificationOnUserRegistered`: catch `\Throwable`, log
`error`, rethrow nothing.** *(recommended)*

The password is already committed when the listener runs. An escaping exception would (a) turn a
successful reset into a 500, so the user believes it failed and retries a now-burnt link, landing on
"invalid link" for an account whose password *did* change; and (b) abort the dispatch loop before
`UserEmailVerified` reaches any future listener. Neither undoes the change, and neither sends the
mail. So the catch is the only behaviour that is strictly better.

**Where it deliberately differs from the precedent, and the comment must say so:** the verification
listener's catch is justified by a *compensating action* (the anonymous resend form). **This one has
none** — there is no "resend my security notice". The catch is still right, because throwing
compensates nothing; but a lost notice is a lost security signal, so the `error` log is the only
safety net and is written to be the Sentry alert it will become. The log message names the
consequence ("the account holder was not told their password changed").

**Decision 3 — Still no outbox; record it as a dated amendment to ADR-0010.** *(recommended — ADR
flag)*

ADR-0010 §Alternatives says: *"Revisit [the outbox] when an event has a consequence the user cannot
compensate for."* **This event is the first one.** The plan must answer that rather than let it pass:

- The loss windows are (i) a process death between `users->save()` and dispatch — microseconds,
  in-process, no I/O between them — and (ii) `send()` failing to write `messenger_messages`, which
  means Postgres is unavailable seconds after it accepted the commit. Both are rare and both are
  logged (ii) or coincide with a visible outage (i).
- The notice is **best-effort by nature**: its threat model already concedes that a mailbox-owning
  attacker deletes it. An outbox (table + relay process + its own monitoring, on a one-box budget)
  would buy delivery guarantees for a signal whose value is already probabilistic.
- The ADR's own examples of the trigger (a payment, a search alert) carry money or a user-visible
  promise. A security notice is informational.

Recommendation: **keep ADR-0010 decisions 1–2, add a dated amendment** recording that the trigger
was met by `UserPasswordChanged`, evaluated, and declined with the reasons above — so the next reader
of "revisit when…" finds the answer. **Not a new ADR**: no decision changes. Also strengthens the
existing Sentry carry-over: this slice adds a second silent async failure class.

## Domain layer (pure PHP)

- **Aggregate / entity changes:** **none.** `User` is untouched; its invariants (I-23 staleness etc.)
  are unaffected.
- **Value objects:** **none.** `Email` and `UserId` are reused as read.
- **Domain events:** **none new, none changed.** `UserPasswordChanged` gets its first listener. Its
  docblock's "Nobody listens to it today" paragraph becomes false and must be updated (T3) — a
  docblock claiming the opposite of the code is a defect (CLAUDE.md).
- **Ports:** **none new** (Decision 1). `UserRepository::findById(UserId): ?User` is reused.

### Why `Identity` and not `Notification`

Constitution §4 gives `Notification` *"saved searches + lifecycle emails"* with aggregate
`SearchAlert` — listing-lifecycle and alert mail, which have their own model (subscriptions, alert
criteria, anti-abuse). This notice has no model of its own: it is a consequence of an `Identity`
fact, addressed by `Identity` data, worded in `Identity`'s language, built exactly like the two
`Identity` mails already living in `Infrastructure/Identity/`. Creating a context to hold one listener
would be a context with no aggregate and no language. If `Notification` later grows a
"user notification preferences" model, moving this listener there is a file move.

## Application layer

- **Command/Query:** none (Decision 1).
- **Handler:** none. The use case that changes the password (`ResetPasswordWithTokenHandler`) is
  **not modified** — not even its comments, except that its dispatch comment may gain one clause
  noting the listener exists (optional).
- **Idempotency:** not applicable — each event is a distinct fact, deliberately not deduplicated
  (AC-6). Replays never reach `changePassword()` (refused upstream by redemption order + I-23).

## Infrastructure layer

- **Persistence:** none. No mapping, no migration.
- **HTTP / UI:** none. No route, no controller change.
- **Listener — `src/src/Infrastructure/Identity/EventListener/NotifyUserOnPasswordChanged.php`:**

  ```
  #[AsEventListener(event: UserPasswordChanged::class)]
  final readonly class NotifyUserOnPasswordChanged
      __construct(UserRepository $users, MailerInterface $mailer,
                  UrlGeneratorInterface $urls, LoggerInterface $logger)
      __invoke(UserPasswordChanged $event): void
          try {
              $user = $users->findById($event->userId()) ?? throw UserNotFound::withId(...)
              send TemplatedEmail:
                  to       $user->email()->toString()
                  subject  'Your muzbar password was changed'
                  text     email/password_changed.txt.twig
                  html     email/password_changed.html.twig
                  context  recipientEmail, changedAt = $event->occurredAt(),
                           forgotPasswordUrl = generate('app_forgot_password', [], ABSOLUTE_URL)
          } catch (\Throwable $e) {
              logger->error('<consequence-naming message>', ['user_id' => ..., 'exception' => $e])
          }
  ```

  Notes the implementer must honour:
  - **`changedAt` comes from the event, not `Clock::now()` and not `$user->passwordChangedAt()`.**
    The event is the fact being reported; the three are equal today, but only the event stays
    correct if a second change lands before a slow listener runs.
  - **No `->from()`** (mailer.yaml sets header + envelope from `MAILER_FROM`).
  - **URL generated in the listener**, which runs inside the HTTP request today, so the host comes
    from the request; `DEFAULT_URI` covers any future console/worker producer. No new config.
  - Reuses the existing `UserNotFound` exception (Domain) only as a carrier into the catch — or the
    implementer may simply `if (null === $user) { log; return; }`; either is fine, pick the shorter.
  - The catch's comment states Decision 2, **including the difference from
    `IssueVerificationOnUserRegistered`** (no compensating action) — CLAUDE.md: when a file
    deliberately contradicts an existing one, the contradiction gets the comment.
  - `user_id` only in the log; never the address (same reasoning as the precedent; Constitution §8).
- **Templates — `src/templates/email/password_changed.{txt,html}.twig`:** same conventions as
  `reset_password.*` (text part written first and saying the same thing; HTML standalone, inline
  styles, no images/scripts/remote CSS; dates `|date('Y-m-d H:i', 'UTC')` + ` UTC`). Content:
  1. Heading: *Your muzbar password was changed*.
  2. *The password for the muzbar account with this address was changed on `{changedAt}` UTC:
     `{recipientEmail}`.*
  3. *If this was you, you don't need to do anything.*
  4. *If you did not make this change, reset your password straight away:* `{forgotPasswordUrl}`
     (full URL as link text, as in the reset mail) *— and check the security of this email account,
     since anyone who can read it can request a reset.*
  5. Footer: automated message, replies not read.
  Deliberately **not** stating the cause ("via a reset link"): the event doesn't carry it, and a
  sentence true only for today's sole producer would become a lie the day a second one lands.
- **Async / schedule:** none new. `SendEmailMessage` routing unchanged (async in dev/prod, sync in
  test).
- **DI wiring:** none — autowiring + `#[AsEventListener]` attribute, exactly as the precedent.

## Interface boundary & input contract

- **Input:** one `UserPasswordChanged` object from the in-process dispatcher. No untrusted input
  crosses this boundary — the event is built by the aggregate; the address was validated by `Email`
  at registration; Twig autoescapes it in the HTML part.
- **Output:** at most one queued `SendEmailMessage` to the account's current address; on failure,
  one `error` log record. Never an exception.

## Data & migrations

- None.

## Test plan

Tests encode the ACs, written from the spec, not from observed output. All functional tests drive
the real flow — forgot-password → token from the reset mail or from a factory-issued request →
`GET /reset-password/{token}` → `POST /reset-password` — **inside one `KernelBrowser`** (the session
carries the stashed token), with `ClearsRateLimiters` in `setUp()` (`password_reset_submit` and
friends live in Redis and survive DAMA). Run `make test` twice.

- **Domain unit:** none new. Slice 3's `UserTest` AC-33 assertion stays as the guard that the event
  never gains a secret (AC-10).
- **Functional — new `tests/Functional/Identity/PasswordChangedNotificationTest.php`:**
  - AC-1/2/3/4 in one happy-path test: `assertEmailCount(1)` on the reset POST; `getMailerMessage()`;
    recipient; subject; text + HTML bodies contain the address, the UTC instant (compared to a
    **freshly fetched** repository's `passwordChangedAt()` — identity-map footgun), the generated
    absolute forgot-password URL; neither body contains the token, `/reset-password`, the new password
    or a hash prefix. Also AC-9's `<img`/`<script` absence on the HTML part.
  - AC-5: weak password (422) → `assertEmailCount(0)`; replayed link after success → 0 notices;
    invalid token → 0.
  - AC-6: two complete resets → one notice each.
  - AC-7/8: `$client->disableReboot()`, then swap the **concrete** mailer service the listener's
    `MailerInterface` resolves to (`mailer.mailer` — confirm with `debug:container`; never the alias)
    with a throwing double, and the concrete `monolog.logger` with `RecordingLogger`. Assert 302 →
    `app_login`, new password works (hash verifies via fresh repository), request redeemed, exactly one
    `error` record with `user_id` == the account's UUID and an `exception`, and the address appears in
    no record's message or context (`json_encode` the context and search).
    *Verify the double actually fires* — the test must fail if the listener is removed (assert the
    error record exists), otherwise it passes vacuously.
- **Regression sweep:** grep existing functional tests for `assertEmailCount` on a reset POST (none
  expected — today a reset POST sends nothing) and adjust any that now see one extra mail **on
  purpose**, noting it in the commit.
- **Performance:** not applicable (no search; one extra row in `messenger_messages` per reset).

## Risks / open questions

1. **Decision 1** (no port) — first mail in the system without a Domain port. Sign off or choose B.
2. **Decision 3** — confirm "amend ADR-0010, no outbox". If declined, this slice grows an outbox and
   is no longer small; stop and replan.
3. **Mail copy** — the five lines above are a proposal. The "check the security of this email
   account" line is deliberate (a completed reset proves the link was readable by someone) but is the
   most opinionated sentence; confirm.
4. **Value honesty** — with reset as the only producer, a mailbox-owning attacker can delete the
   notice. The spec states this rather than overselling; the roadmap line ("the mechanism by which an
   account-takeover victim finds out") should be softened to match.
5. **Session-revocation claim** — the reset invalidates other sessions (`PasswordResetSessionInvalidationTest`),
   so the mail *could* say "anyone signed in with the old password has been signed out". Left out:
   it would bind the template to a property of the reset path that a future producer may not share.
6. **Sentry** — this slice adds a second silent async failure class (failed render → `failed`
   transport). Not in scope; reinforces the outstanding carry-over.
