<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\Entity;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Event\PasswordResetRequested;
use App\Domain\Identity\Exception\PasswordResetLinkAlreadyUsed;
use App\Domain\Identity\Exception\PasswordResetLinkExpired;
use App\Domain\Identity\Exception\PasswordResetLinkInvalidated;
use App\Domain\Identity\Exception\PasswordResetTokenMismatch;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\ResetToken;
use App\Domain\Identity\ValueObject\UserId;
use PHPUnit\Framework\TestCase;

/**
 * Pure Domain unit tests for the `PasswordResetRequest` aggregate — no kernel boot, no persistence.
 * Every instant is supplied explicitly, never read from the wall clock, which is what makes the
 * expiry-boundary assertions exact rather than "roughly an hour".
 *
 * This suite deliberately does **not** mirror `EmailVerificationRequestTest` wherever the two
 * aggregates' behaviour diverges (replay, reissue, mutation-on-GET) — see `PasswordResetRequest`'s
 * own docblock for the four inversions and why a shared test shape would be as wrong as a shared
 * base class.
 */
final class PasswordResetRequestTest extends TestCase
{
    private const string ID = '018f5b2a-0000-7000-8000-000000000000';
    private const string USER_ID = '018f5b2a-0000-7000-8000-000000000001';

    /**
     * AC-2: `expiresAt` is derived from `issuedAt`, never taken as an independent parameter, and is
     * exactly +3600 seconds (`PasswordResetRequest::LIFETIME_SECONDS`) — invariant I-15.
     */
    public function testIssueDerivesExpiresAtAtExactlyThirtySixHundredSecondsAfterIssuedAt(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');

        $request = $this->issueRequest(issuedAt: $issuedAt);

        self::assertSame(PasswordResetRequest::LIFETIME_SECONDS, $request->expiresAt()->getTimestamp() - $issuedAt->getTimestamp());
        self::assertSame(3600, $request->expiresAt()->getTimestamp() - $issuedAt->getTimestamp());
        self::assertEquals($issuedAt, $request->issuedAt());
    }

    public function testIssueSetsTheIdUserIdAndTokenHashFromTheGivenArguments(): void
    {
        $id = PasswordResetRequestId::fromString(self::ID);
        $userId = UserId::fromString(self::USER_ID);
        $hash = $this->aHash();

        $request = PasswordResetRequest::issue($id, $userId, $hash, new \DateTimeImmutable('2026-07-28T10:00:00+00:00'));

        self::assertTrue($request->id()->equals($id));
        self::assertTrue($request->userId()->equals($userId));
        self::assertTrue($request->tokenHash()->equals($hash));
        self::assertNull($request->redeemedAt());
        self::assertNull($request->invalidatedAt());
        self::assertFalse($request->isRedeemed());
        self::assertFalse($request->isInvalidated());
    }

    /**
     * AC-32: issuing dispatches exactly one `PasswordResetRequested`, and its payload carries no
     * token — asserted by exhaustively reading the event's public surface (its declared properties)
     * rather than merely checking the fields we expect exist, since the thing worth catching is a
     * *future* field quietly reintroducing the secret, not the absence of a field nobody added yet.
     */
    public function testIssueRecordsExactlyOnePasswordResetRequestedEventWhosePayloadCarriesNoToken(): void
    {
        $id = PasswordResetRequestId::fromString(self::ID);
        $userId = UserId::fromString(self::USER_ID);
        $issuedAt = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');

        $request = PasswordResetRequest::issue($id, $userId, $this->aHash(), $issuedAt);
        $events = $request->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(PasswordResetRequested::class, $events[0]);
        self::assertTrue($events[0]->requestId()->equals($id));
        self::assertTrue($events[0]->userId()->equals($userId));
        self::assertEquals($issuedAt, $events[0]->issuedAt());
        self::assertEquals($request->expiresAt(), $events[0]->expiresAt());

        // Exhaustive by construction: `PasswordResetRequested` declares exactly four
        // constructor-promoted properties (`requestId`, `userId`, `issuedAt`, `expiresAt` —
        // `occurredAt()` is a method returning `issuedAt`, not a fifth property), and none of their
        // *exact* types is `ResetToken` or `HashedResetToken`. Asserted by reflection, comparing
        // full type names rather than substrings, so a future field added to the event is caught
        // here rather than trusted to a docblock.
        $properties = (new \ReflectionClass(PasswordResetRequested::class))->getProperties();
        self::assertCount(4, $properties);

        $propertyTypeNames = array_map(
            static fn (\ReflectionProperty $p): ?string => $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : null,
            $properties,
        );

        self::assertNotContains(ResetToken::class, $propertyTypeNames);
        self::assertNotContains(HashedResetToken::class, $propertyTypeNames);

        // There is also no *accessor* returning a token or a hash — the property check above rules
        // out a field, this rules out a computed reader that only wraps one.
        $eventReflection = new \ReflectionClass(PasswordResetRequested::class);
        foreach ($eventReflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();
            $returnTypeName = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : null;

            self::assertNotSame(ResetToken::class, $returnTypeName);
            self::assertNotSame(HashedResetToken::class, $returnTypeName);
        }
    }

    public function testRedeemWithTheRightHashSetsRedeemedAt(): void
    {
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash);
        $redeemedAt = new \DateTimeImmutable('2026-07-28T10:30:00+00:00');

        $request->redeem($hash, $redeemedAt);

        self::assertTrue($request->isRedeemed());
        self::assertEquals($redeemedAt, $request->redeemedAt());
    }

    /**
     * `redeem()` records no event: the fact worth publishing is `UserPasswordChanged`, and it
     * belongs to `User`, which raises it from its own `changePassword()`.
     */
    public function testRedeemRecordsNoEvent(): void
    {
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash);
        $request->releaseEvents(); // discard the PasswordResetRequested from issue()

        $request->redeem($hash, new \DateTimeImmutable('2026-07-28T10:30:00+00:00'));

        self::assertSame([], $request->releaseEvents());
    }

    /**
     * AC-34: `invalidate()` records no event — the docblock on `PasswordResetRequest::invalidate()`
     * claims exactly this ("a link nobody will now click was quietly retired" is not a fact any
     * listener needs), and `PasswordResetRequestFactory::invalidated()` cites it as already
     * established, but nothing at the Domain unit level actually asserted it until this test. See
     * `testRedeemRecordsNoEvent()` above for the mirror-image assertion on the other terminal state.
     */
    public function testInvalidateRecordsNoEvent(): void
    {
        $request = $this->issueRequest();
        $request->releaseEvents(); // discard the PasswordResetRequested from issue()

        $request->invalidate(new \DateTimeImmutable('2026-07-28T10:15:00+00:00'));

        self::assertSame([], $request->releaseEvents());
    }

    /**
     * Invariant I-16 / AC-18, and the sharpest of the four inversions from `EmailVerificationRequest`:
     * a replay is **refused**, not absorbed. A second `redeem()` throws, even with the correct hash
     * and a valid instant — there is no un-redeem operation.
     */
    public function testASecondRedeemThrowsPasswordResetLinkAlreadyUsed(): void
    {
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash);
        $request->redeem($hash, new \DateTimeImmutable('2026-07-28T10:30:00+00:00'));

        $this->expectException(PasswordResetLinkAlreadyUsed::class);

        $request->redeem($hash, new \DateTimeImmutable('2026-07-28T10:45:00+00:00'));
    }

    /**
     * Invariant I-17, one direction: a request that has already been redeemed can never also be
     * invalidated — `invalidate()` throws rather than silently superseding a spent link, because a
     * redeemed request must never carry an `invalidatedAt` too (the two columns would stop
     * answering "how many resets completed" on their own).
     *
     * THIS TEST AND `testRedeemAfterInvalidateThrowsPasswordResetLinkInvalidated` BELOW ARE A PAIR,
     * AND BOTH MUST EXIST: neither guard can ever observe a request that is both redeemed and
     * invalidated, so one assertion per direction would leave the other direction unpinned. Together
     * they are what actually pins I-17's mutual exclusion — **not**
     * `testInvalidateTwiceIsANoOp` below, which pins a different property (`invalidate()`'s own
     * idempotency) and previously carried this claim by mistake.
     */
    public function testInvalidateAfterRedeemThrowsPasswordResetLinkAlreadyUsed(): void
    {
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash);
        $request->redeem($hash, new \DateTimeImmutable('2026-07-28T10:30:00+00:00'));

        $this->expectException(PasswordResetLinkAlreadyUsed::class);

        $request->invalidate(new \DateTimeImmutable('2026-07-28T10:31:00+00:00'));
    }

    /**
     * Invariant I-17, the other direction: a request that has been invalidated can never be
     * redeemed, even with the correct hash and before it would otherwise have expired. See
     * `testInvalidateAfterRedeemThrowsPasswordResetLinkAlreadyUsed` above — this test and that one
     * are the pair that together pins "never both".
     */
    public function testRedeemAfterInvalidateThrowsPasswordResetLinkInvalidated(): void
    {
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash);
        $request->invalidate(new \DateTimeImmutable('2026-07-28T10:15:00+00:00'));

        $this->expectException(PasswordResetLinkInvalidated::class);

        $request->redeem($hash, new \DateTimeImmutable('2026-07-28T10:30:00+00:00'));
    }

    /**
     * `invalidate()`'s own idempotency: a second call on an already-invalidated request is a
     * silent no-op rather than a thrown exception — unlike `redeem()`, which refuses a repeat
     * (AC-18). It must tolerate being called twice because `RequestPasswordResetHandler`'s reissue
     * sweep may legitimately run it against a row that a retried call, or a concurrent reissue,
     * already invalidated.
     *
     * This test only pins that the second call does not throw and that `isInvalidated()` stays
     * `true`. It deliberately does **not** also assert that `invalidatedAt` is unchanged by the
     * repeat — that is a different property, already pinned by
     * `testInvalidateTwiceKeepsTheFirstInvalidatedAt` below, and duplicating it here would just be
     * two tests re-proving one fact.
     */
    public function testInvalidateTwiceIsANoOp(): void
    {
        $request = $this->issueRequest();

        $request->invalidate(new \DateTimeImmutable('2026-07-28T10:15:00+00:00'));
        $request->invalidate(new \DateTimeImmutable('2026-07-28T11:00:00+00:00'));

        self::assertTrue($request->isInvalidated());
    }

    /**
     * The sweep in `RequestPasswordResetHandler` is a loop that a retry or a concurrent request may
     * legitimately run twice, so a second `invalidate()` must be harmless *and* must keep the
     * *first* `invalidatedAt` — the recorded instant is when the request actually stopped being
     * live, and a second call silently moving it forward would misreport that.
     */
    public function testInvalidateTwiceKeepsTheFirstInvalidatedAt(): void
    {
        $request = $this->issueRequest();
        $firstInvalidatedAt = new \DateTimeImmutable('2026-07-28T10:15:00+00:00');

        $request->invalidate($firstInvalidatedAt);
        $request->invalidate(new \DateTimeImmutable('2026-07-28T11:00:00+00:00'));

        self::assertEquals($firstInvalidatedAt, $request->invalidatedAt());
    }

    /**
     * Invariant I-18, pinned at the boundary instant itself: redeeming exactly *at* `expiresAt`
     * succeeds. Timestamps are whole-second, so the boundary instant is still within the stated
     * one-hour lifetime.
     *
     * This test and the next one are a pair by design, and both must exist: asserting only the
     * boundary succeeds says nothing about which comparison operator is in the code (a `>=` would
     * pass this test too, while silently shortening every link by up to a second), and asserting
     * only the one-second-past failure says nothing about whether the boundary itself was
     * wrongly rejected. Only both together pin `isExpiredAt()`'s strict `>`.
     */
    public function testRedeemingExactlyAtExpiresAtSucceeds(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash, issuedAt: $issuedAt);

        $request->redeem($hash, $request->expiresAt());

        self::assertTrue($request->isRedeemed());
    }

    /**
     * The other half of the same boundary — see the previous test's docblock for why both halves
     * are required together.
     */
    public function testRedeemingOneSecondAfterExpiresAtThrowsPasswordResetLinkExpired(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash, issuedAt: $issuedAt);
        $oneSecondPastExpiry = $request->expiresAt()->modify('+1 second');

        $this->expectException(PasswordResetLinkExpired::class);

        $request->redeem($hash, $oneSecondPastExpiry);
    }

    public function testIsExpiredAtAgreesWithTheSameBoundary(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');
        $request = $this->issueRequest(issuedAt: $issuedAt);

        self::assertFalse($request->isExpiredAt($request->expiresAt()));
        self::assertTrue($request->isExpiredAt($request->expiresAt()->modify('+1 second')));
    }

    /**
     * Invariant I-19: only the token whose digest matches this request's own may redeem it — a
     * near-miss (a single character off, same length) throws rather than silently failing to
     * match. This is the assertion that actually exercises `hash_equals` inside
     * `HashedResetToken::equals()` on the case where it matters — a completely unrelated string
     * would fail any string comparison, correct or not.
     */
    public function testRedeemingWithAOneCharacterOffHashThrowsPasswordResetTokenMismatch(): void
    {
        $request = $this->issueRequest(hash: HashedResetToken::fromString('the-correct-digest-value'));
        $nearMiss = HashedResetToken::fromString('the-correct-digest-valuE');

        $this->expectException(PasswordResetTokenMismatch::class);

        $request->redeem($nearMiss, new \DateTimeImmutable('2026-07-28T10:30:00+00:00'));
    }

    public function testAMismatchedRedeemLeavesTheRequestUnredeemed(): void
    {
        $request = $this->issueRequest(hash: HashedResetToken::fromString('the-correct-digest'));
        $wrongHash = HashedResetToken::fromString('a-completely-different-digest');

        try {
            $request->redeem($wrongHash, new \DateTimeImmutable('2026-07-28T10:30:00+00:00'));
        } catch (PasswordResetTokenMismatch) {
            // expected — the assertion of interest is below
        }

        self::assertFalse($request->isRedeemed());
        self::assertNull($request->redeemedAt());
    }

    /**
     * `assertRedeemableWith()` is documented to mutate nothing — this is what makes the GET on the
     * link safe against mail-scanner prefetch (AC-12). This test calls it directly on a request that
     * *would* successfully redeem, then asserts every reader still reports the pristine state: not
     * just `redeemedAt`, but `invalidatedAt` too, since a method that is supposed to touch nothing
     * has no business moving either.
     */
    public function testAssertRedeemableWithMutatesNothing(): void
    {
        $hash = $this->aHash();
        $issuedAt = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');
        $request = $this->issueRequest(hash: $hash, issuedAt: $issuedAt);

        $request->assertRedeemableWith($hash, new \DateTimeImmutable('2026-07-28T10:30:00+00:00'));

        self::assertNull($request->redeemedAt());
        self::assertNull($request->invalidatedAt());
        self::assertFalse($request->isRedeemed());
        self::assertFalse($request->isInvalidated());
        self::assertTrue($request->isLiveAt(new \DateTimeImmutable('2026-07-28T10:30:00+00:00')));
    }

    /**
     * The check order is part of the contract, not an accident of writing (see the aggregate's own
     * docblock): invalidated is checked *first*, so a request that is both invalidated and expired
     * reports "invalidated" — the more recent, more actionable fact — rather than "expired". A test
     * that only ever constructs a request in one bad state at a time could never distinguish "the
     * order is right" from "only one branch exists"; this one is invalidated well before it also
     * expires, so both conditions are simultaneously true when `redeem()` is called.
     */
    public function testAnInvalidatedAndExpiredRequestReportsInvalidatedNotExpired(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');
        $request = $this->issueRequest(issuedAt: $issuedAt);
        $request->invalidate(new \DateTimeImmutable('2026-07-28T10:05:00+00:00'));

        $this->expectException(PasswordResetLinkInvalidated::class);

        // Well past expiresAt too — both conditions hold, and invalidated must win.
        $request->redeem($this->aHash(), $issuedAt->modify('+2 hours'));
    }

    /**
     * The other documented ordering: the token comparison is checked *last*, because it is the only
     * check touching a secret, so a wrong hash presented against an already-expired request reports
     * "expired" rather than paying for (and revealing, via exception type, the outcome of) a
     * cryptographic comparison that could not have succeeded anyway.
     */
    public function testAWrongHashOnAnExpiredRequestReportsExpiredNotMismatch(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash, issuedAt: $issuedAt);
        $wrongHash = HashedResetToken::fromString('definitely-not-the-right-digest');

        $this->expectException(PasswordResetLinkExpired::class);

        $request->redeem($wrongHash, $request->expiresAt()->modify('+1 second'));
    }

    public function testReleaseEventsEmptiesTheBufferSoASecondCallReturnsAnEmptyList(): void
    {
        $request = $this->issueRequest();

        $request->releaseEvents();
        $second = $request->releaseEvents();

        self::assertSame([], $second);
    }

    private function issueRequest(
        ?HashedResetToken $hash = null,
        ?\DateTimeImmutable $issuedAt = null,
    ): PasswordResetRequest {
        return PasswordResetRequest::issue(
            PasswordResetRequestId::fromString(self::ID),
            UserId::fromString(self::USER_ID),
            $hash ?? $this->aHash(),
            $issuedAt ?? new \DateTimeImmutable('2026-07-28T10:00:00+00:00'),
        );
    }

    private function aHash(): HashedResetToken
    {
        return HashedResetToken::fromString('opaque-test-digest-value');
    }
}
