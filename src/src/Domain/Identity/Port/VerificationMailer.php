<?php

declare(strict_types=1);

namespace App\Domain\Identity\Port;

use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\VerificationToken;

/**
 * Tells one person how to prove that an address is theirs.
 *
 * WHY THIS IS NARROWER THAN A GENERIC `MailerPort`. Constitution §4.3 names a `MailerPort` among the
 * service ports, and the reflex is to build that instead — one `send(Message $m)` reusable by every
 * context. This port deliberately does not do that, and the reason is the definition of a port: **a
 * port states what the Domain needs, not what the vendor provides.** The Domain's need is the
 * sentence in the first line. "Send a MIME message with these headers, this HTML part and this text
 * part" is Symfony Mailer's vocabulary wearing a domain-shaped hat, and a `MailerPort` would drag
 * subject lines, senders and templates — all presentation, all Infrastructure — across the boundary
 * one optional parameter at a time.
 *
 * Everything this signature omits is owned by `TwigVerificationMailer`: the URL and the router that
 * builds it, the Twig templates, the mandatory plain-text alternative, the `From` address, the
 * transport, and whether delivery is synchronous or queued. The Domain could not name any of those
 * without importing a framework, which is the tell that they were never its business. Note in
 * particular that **the Domain never sees a URL** — it hands over a token and a deadline, and the
 * adapter decides what a link is.
 *
 * What the Domain *does* own, and therefore what does appear here: who is being asked
 * (`Email`), what proves it (`VerificationToken`), and how long they have (`$expiresAt`) — the last
 * one passed rather than recomputed so the message and the stored row can never disagree about the
 * deadline.
 *
 * THE DELIBERATE ASYMMETRY: **no listener sends this mail.** `EmailVerificationRequested` is
 * dispatched by the same use case, and hanging the mail off it would be the more elegant-looking
 * wiring — the domain event triggers the side effect, textbook. It is rejected because the mail
 * needs the *plaintext* token, and the event carries none (AC-26) and must not. Reaching a listener
 * would mean putting the secret in the event, i.e. in every subscriber, every debug dump of the
 * event, and every queue row once the transport goes asynchronous. So `RequestEmailVerificationHandler`
 * calls this port directly: it is the only place in the system that legitimately holds the plaintext,
 * and keeping it there is worth giving up a satisfying diagram. Elegance that widens the blast radius
 * of a secret is not elegance.
 *
 * The handler calls this **after** `save()` and **before** dispatching the event: a link in
 * somebody's inbox must always have a row behind it, and the audit fact is only published once the
 * whole use case succeeded.
 */
interface VerificationMailer
{
    /**
     * Delivery may be asynchronous, and callers must not assume the message has left the building
     * when this returns — the implementation queues it (ADR-0010). What callers *may* assume is that
     * a return without an exception means the message was accepted for delivery.
     */
    public function sendVerificationLink(Email $recipient, VerificationToken $token, \DateTimeImmutable $expiresAt): void;
}
