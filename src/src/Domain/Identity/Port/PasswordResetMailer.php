<?php

declare(strict_types=1);

namespace App\Domain\Identity\Port;

use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\ResetToken;

/**
 * Tells one person how to set a new password on an account they can no longer get into.
 *
 * WHY THIS IS NARROWER THAN A GENERIC `MailerPort`. Constitution §4.3 names a `MailerPort` among the
 * service ports, and the reflex is to build that instead — one `send(Message $m)` reusable by every
 * context, and by now there are two mails in `Identity` alone, which makes the reflex stronger. This
 * port deliberately does not do that, and the reason is the definition of a port: **a port states
 * what the Domain needs, not what the vendor provides.** The Domain's need is the sentence in the
 * first line. "Send a MIME message with these headers, this HTML part and this text part" is Symfony
 * Mailer's vocabulary wearing a domain-shaped hat, and a `MailerPort` would drag subject lines,
 * senders and templates — all presentation, all Infrastructure — across the boundary one optional
 * parameter at a time.
 *
 * Everything this signature omits is owned by `TwigPasswordResetMailer`: the URL and the router that
 * builds it, the two Twig templates, the mandatory plain-text alternative, the `From` address, the
 * transport, and whether delivery is synchronous or queued. The Domain could not name any of those
 * without importing a framework, which is the tell that they were never its business. Note in
 * particular that **the Domain never sees a URL** — it hands over a token and a deadline, and the
 * adapter decides what a link is.
 *
 * What the Domain *does* own, and therefore what appears here: who is being helped (`Email`), what
 * proves they may be (`ResetToken`), and how long they have (`$expiresAt`) — the last **passed
 * rather than recomputed**, so the message and the stored row can never disagree about the deadline.
 * A mail that says "valid for one hour" over a row that expired forty minutes ago is a support
 * ticket the Domain can prevent by never letting the deadline be derived twice.
 *
 * THE DELIBERATE ASYMMETRY: **no listener sends this mail**, and here the reason has teeth.
 * `PasswordResetRequested` is dispatched by the same use case, and hanging the mail off it would be
 * the more elegant-looking wiring — the domain event triggers the side effect, textbook. It is
 * rejected because the mail needs the **plaintext** token, and the event carries none (AC-32) and
 * must not. Reaching a listener would mean putting a live account-takeover credential into every
 * subscriber, every debug dump of the event, and every `messenger_messages` row for as long as the
 * retry policy keeps it. So `RequestPasswordResetHandler` calls this port directly: it is the only
 * place in the system that legitimately holds the plaintext, and keeping it there is worth giving up
 * a satisfying diagram. **Elegance that widens the blast radius of a secret is not elegance.**
 *
 * The handler calls this **after** `save()` and **before** dispatching the event: a link sitting in
 * somebody's inbox must always have a row behind it — the reverse order can produce a valid-looking
 * URL that matches no request, indistinguishable from a forgery when clicked — and the audit fact is
 * published only once the whole use case succeeded.
 */
interface PasswordResetMailer
{
    /**
     * Delivery may be asynchronous, and callers must not assume the message has left the building
     * when this returns — the implementation queues it (ADR-0010). What callers *may* assume is that
     * a return without an exception means the message was accepted for delivery.
     */
    public function sendResetLink(Email $recipient, ResetToken $token, \DateTimeImmutable $expiresAt): void;
}
