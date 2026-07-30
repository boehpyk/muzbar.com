<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Identity\Presentation;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Infrastructure\Identity\Presentation\LifetimePhrase;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for `LifetimePhrase::forSeconds()` — no kernel boot, no persistence. The class has
 * a private constructor (it is a total function, deliberately not a service, per its own docblock),
 * so every test here goes through the static entry point rather than around it.
 *
 * Nothing in this suite existed before this round: the class shipped with no test at all, and the
 * defect it was written to fix — a stale "one hour" literal on `forgot_password_sent.html.twig` — has
 * no assertion here catching its recurrence at the mail/page surfaces; see
 * `ForgotPasswordTest::testTheResetMailStatesTheLifetimeDerivedFromTheDomainConstant` and
 * `ForgotPasswordTest::testTheSentPageStatesTheLifetimeDerivedFromTheDomainConstant` for that half.
 * This file pins the helper's own arithmetic in isolation.
 */
final class LifetimePhraseTest extends TestCase
{
    /**
     * The real value in production: `PasswordResetRequest::LIFETIME_SECONDS` is 3600, which divides
     * evenly into whole hours, so this is the phrase every reset mail and the "check your inbox" page
     * actually render today. Read from the constant, not hardcoded as `3600`, so this test tracks the
     * same source of truth the helper's callers do rather than agreeing with it by coincidence.
     */
    public function testTheCurrentPasswordResetLifetimeRendersAsOneHour(): void
    {
        self::assertSame('1 hour', LifetimePhrase::forSeconds(PasswordResetRequest::LIFETIME_SECONDS));
    }

    public function testSevenThousandTwoHundredSecondsRendersAsPluralTwoHours(): void
    {
        self::assertSame('2 hours', LifetimePhrase::forSeconds(7200));
    }

    /**
     * The exact instant the class's own docblock argues about: at 1800 seconds, rendering hours via
     * `intdiv(1800, 3600)` would produce "0 hours" — ungrammatical and, worse, false, in the one
     * sentence a locked-out user acts on. This is what proves the class actually falls back to
     * minutes instead of reproducing that bug, rather than merely asserting it in prose.
     */
    public function testEighteenHundredSecondsRendersAsThirtyMinutesNotZeroHours(): void
    {
        self::assertSame('30 minutes', LifetimePhrase::forSeconds(1800));
    }

    public function testSixtySecondsRendersAsSingularOneMinute(): void
    {
        self::assertSame('1 minute', LifetimePhrase::forSeconds(60));
    }

    /**
     * The rounding direction is part of the contract: a remainder rounds **up**, because
     * understating how long a link lives is the lie that makes a user abandon a link that still
     * works. 61 seconds is one second past a whole minute, so a `ceil` reports "2 minutes" where a
     * naive integer division (`intdiv`) would silently truncate to "1 minute".
     */
    public function testSixtyOneSecondsRoundsUpToTwoMinutes(): void
    {
        self::assertSame('2 minutes', LifetimePhrase::forSeconds(61));
    }
}
