<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedPassword;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\ResetToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Tests\Factory\PasswordResetRequestFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ClearsRateLimiters;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for `GET /reset-password/{token}` (the link-check hop) against the real
 * `muzbar_test` database (DAMA rollback). T39 of `identity-password-reset` (AC-12 … AC-17, plus
 * AC-19 and the 429 half of AC-15, added after `/verify`'s NEEDS CHANGES review flagged both as
 * unasserted).
 *
 * Fixtures are built directly through `ResetTokenGenerator` and `PasswordResetRequestRepository` —
 * the only way a test can ever know the plaintext token a link's URL would need to carry, mirroring
 * `EmailVerificationLinkTest`'s own convention (the real flow never exposes the plaintext anywhere a
 * test could read it back from).
 *
 * `ClearsRateLimiters` protects against a stale `password_reset_submit` counter (this route's IP
 * limiter, AC-19) left behind by an earlier test — and is now load-bearing rather than merely
 * defensive, since this file deliberately drives that limiter to its bound more than once.
 */
final class ResetPasswordLinkTest extends WebTestCase
{
    use ClearsRateLimiters;

    private KernelBrowser $client;
    private UserRepository $users;
    private PasswordResetRequestRepository $requests;
    private ResetTokenGenerator $tokens;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiterPool();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $requests = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $requests);
        $this->requests = $requests;

        $tokens = self::getContainer()->get(ResetTokenGenerator::class);
        self::assertInstanceOf(ResetTokenGenerator::class, $tokens);
        $this->tokens = $tokens;
    }

    /**
     * AC-12 — the single most important test in this slice. A GET on a live link must mutate
     * *nothing*, and the only way to prove that honestly is to show the token still works afterwards:
     * do the GET, then complete a full reset with the *same* token in the *same* client. This is the
     * mail-scanner-prefetch guarantee — if a scanner's prefetch burnt the challenge, this second half
     * would fail exactly the way a real locked-out user's second click would.
     */
    public function testAGetBurnsNothingAndTheSameTokenStillCompletesAResetAfterwards(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('link-prefetch-safe@example.com')]);
        [$token, $hash] = $this->issueRequestFor($user->id());

        $this->client->request('GET', '/reset-password/'.$token->reveal());
        self::assertResponseRedirects('/reset-password');

        $untouched = $this->requests->findByTokenHash($hash);
        self::assertNotNull($untouched);
        self::assertNull($untouched->redeemedAt(), 'A GET must not redeem the request.');
        self::assertNull($untouched->invalidatedAt(), 'A GET must not invalidate the request.');

        // Complete the reset with the SAME token, in the SAME client (session-stashed already).
        $crawler = $this->client->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();
        $csrfToken = $crawler->filter('input[name="new_password_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        $this->client->request('POST', '/reset-password', [
            'new_password_form' => [
                'plainPassword' => ['first' => 'a-perfectly-strong-new-password', 'second' => 'a-perfectly-strong-new-password'],
                '_token' => $csrfToken,
            ],
        ]);

        self::assertResponseRedirects('/login');

        // Fetched fresh from the container rather than reused from `$this->requests`: Doctrine's
        // identity map would otherwise happily hand back the very `$untouched` object read above —
        // same PK, already managed — without re-querying, which would make this assertion pass or
        // fail on a stale in-memory copy rather than on what the POST actually committed.
        $redeemed = $this->freshRequests()->findByTokenHash($hash);
        self::assertNotNull($redeemed);
        self::assertTrue($redeemed->isRedeemed(), 'The token that survived the GET must still be able to redeem.');
    }

    /**
     * AC-13: the GET responds 302 to the token-less `/reset-password` path — never carrying the
     * token in the `Location` header.
     */
    public function testALiveLinkRedirectsToTheTokenLessPathWithNoTokenInTheLocationHeader(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('link-token-less-redirect@example.com')]);
        [$token] = $this->issueRequestFor($user->id());

        $this->client->request('GET', '/reset-password/'.$token->reveal());

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertSame('/reset-password', $location);
        self::assertStringNotContainsString($token->reveal(), (string) $location);
    }

    /**
     * AC-14: the stashed-token form renders 200 with a repeated password field and a CSRF token, and
     * the plaintext token appears **nowhere** in the rendered body — searched as a raw substring
     * across the whole response, not merely absent from a hidden input.
     */
    public function testTheFormPageContainsTheTokenNowhereInItsBody(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('link-token-not-in-body@example.com')]);
        [$token] = $this->issueRequestFor($user->id());

        $this->client->request('GET', '/reset-password/'.$token->reveal());
        self::assertResponseRedirects('/reset-password');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="new_password_form[plainPassword][first]"]'));
        self::assertCount(1, $crawler->filter('input[name="new_password_form[plainPassword][second]"]'));
        self::assertCount(1, $crawler->filter('input[name="new_password_form[_token]"]'));

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($token->reveal(), $body, 'The plaintext reset token must never appear in the rendered page.');
    }

    /**
     * AC-15: `Referrer-Policy: no-referrer` is present on both the success response (a live link) and
     * the failure response (an unknown token) — if it were present only on success, its presence
     * would itself distinguish the two.
     */
    public function testBothSuccessAndFailureResponsesCarryTheNoReferrerHeader(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('link-no-referrer@example.com')]);
        [$token] = $this->issueRequestFor($user->id());

        $this->client->request('GET', '/reset-password/'.$token->reveal());
        self::assertResponseRedirects('/reset-password');
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));

        $neverIssued = $this->tokens->generate();
        $this->client->request('GET', '/reset-password/'.$neverIssued->reveal());
        self::assertResponseRedirects('/forgot-password');
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
    }

    /**
     * AC-15's sharpest case, and one that regressed silently once already (see
     * `PasswordResetReferrerPolicy`'s own docblock): `POST /reset-password/{token}` — the route is
     * declared `methods: ['GET']` — never reaches `PasswordResetController`, so `RouterListener`
     * throws a `MethodNotAllowedException` before `_route` is ever written onto the request. A
     * listener that matched only by route name would silently skip this 405, which is the one
     * response served on the URL **whose path is the plaintext token itself**. The path-based
     * fallback in `PasswordResetReferrerPolicy::matches()` exists precisely for this response.
     */
    public function testAPostToTheCheckRouteTokenUrlReturns405AndStillCarriesTheNoReferrerHeader(): void
    {
        $neverIssued = $this->tokens->generate();

        $this->client->request('POST', '/reset-password/'.$neverIssued->reveal());

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
    }

    /**
     * The negative side of the same scoping, without which the path fallback above could quietly
     * widen to cover requests it has no business touching: `/forgot-password` carries no token (AC-15
     * deliberately excludes it — see the class docblock on `PasswordResetReferrerPolicy`) and an
     * unrelated 404 shares no path prefix with `/reset-password` at all, so neither may carry the
     * header.
     */
    public function testForgotPasswordAndAnUnrelatedFourOhFourDoNotCarryTheNoReferrerHeader(): void
    {
        $this->client->request('GET', '/forgot-password');
        self::assertResponseIsSuccessful();
        self::assertNull($this->client->getResponse()->headers->get('Referrer-Policy'));

        $this->client->request('GET', '/this-route-does-not-exist-anywhere-in-the-app');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertNull($this->client->getResponse()->headers->get('Referrer-Policy'));
    }

    /**
     * AC-19 (the boundary rate limit on the token routes) and the missing half of AC-15
     * (`ResetPasswordLinkTest::testBothSuccessAndFailureResponsesCarryTheNoReferrerHeader` above
     * only ever exercises a 200/302, never a 429). `password_reset_submit` caps `check()` at 10
     * requests per hour per client IP; the 11th is refused with 429 — and unlike the other
     * responses this route can produce, this one is served on the URL **whose path is the plaintext
     * token itself** (live or dead — the limiter is consumed before the token is even looked at), so
     * it is the response AC-15 cares about most. It is also the response that proves
     * `PasswordResetReferrerPolicy` had to become a `kernel.response` listener: a thrown
     * `TooManyRequestsHttpException` unwinds past every line of the controller, so a helper wrapped
     * around a `return` could never have reached it.
     *
     * Ten never-issued tokens are enough to drive the limiter to its bound — `check()` consumes one
     * unit of budget on every GET regardless of whether the token turns out to be live, unknown,
     * malformed or anything else, so this test needs no real fixtures.
     */
    public function testTheEleventhCheckRouteRequestFromOneIpIsRefusedWith429AndCarriesRetryAfterAndNoReferrerHeaders(): void
    {
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->client->request('GET', '/reset-password/'.$this->tokens->generate()->reveal());
            self::assertResponseRedirects('/forgot-password', message: \sprintf('Attempt %d should not yet be throttled.', $attempt));
        }

        $this->client->request('GET', '/reset-password/'.$this->tokens->generate()->reveal());

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
    }

    /**
     * The part of AC-19 a docblock cannot establish on its own: `password_reset_submit` is ONE
     * bucket shared by BOTH reset-link routes, keyed on client IP —
     * `PasswordResetController::check()` and `::reset()` each inject the very same
     * `RateLimiterFactoryInterface $passwordResetSubmitLimiter` argument, which Symfony resolves to
     * one limiter service either way. This test is what actually proves that rather than asserting
     * it by reading the controller: nine throwaway `check()` GETs plus one legitimate `check()` GET
     * that stashes a real token (ten units of budget, spent entirely on the *check* route) are
     * followed by a single POST to `reset()` — the FIRST request this test ever makes to that seam.
     * If the two routes had independent buckets, that POST would be well within its own untouched
     * budget and would succeed. It does not.
     */
    public function testThePasswordResetSubmitLimiterBucketIsSharedBetweenTheCheckRouteAndTheResetPostSeam(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('link-shared-bucket@example.com')]);
        [$token] = $this->issueRequestFor($user->id());

        for ($attempt = 1; $attempt <= 9; ++$attempt) {
            $this->client->request('GET', '/reset-password/'.$this->tokens->generate()->reveal());
            self::assertResponseRedirects('/forgot-password', message: \sprintf('Attempt %d should not yet be throttled.', $attempt));
        }

        // The 10th unit of budget: a REAL, live token — accepted, and it stashes the token for the
        // `reset()` route below.
        $this->client->request('GET', '/reset-password/'.$token->reveal());
        self::assertResponseRedirects('/reset-password');

        // Fetching the form is a GET and spends nothing (see the dedicated test below), so this does
        // not quietly burn an 11th unit before the assertion that matters.
        $crawler = $this->client->request('GET', '/reset-password');
        $csrfToken = $crawler->filter('input[name="new_password_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        // The 11th unit of budget — on the OTHER route's seam.
        $this->client->request('POST', '/reset-password', [
            'new_password_form' => [
                'plainPassword' => ['first' => 'a-perfectly-strong-new-password', 'second' => 'a-perfectly-strong-new-password'],
                '_token' => $csrfToken,
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
    }

    /**
     * The other documented subtlety `password_reset_submit` relies on: `GET /reset-password`
     * deliberately does **not** consume budget — only the POST does. The controller's own comment
     * argues it directly: "there is nothing here to protect... charging it would spend a
     * locked-out person's ten-per-hour budget on a page that did no work." Fifteen GETs — more than
     * the limiter's bound of 10 — must all produce the ordinary no-stashed-token response and never
     * a 429, or this test would fail on the eleventh attempt rather than complete all fifteen.
     */
    public function testGetResetPasswordNeverConsumesTheSubmitLimiterRegardlessOfHowManyTimesItIsCalled(): void
    {
        for ($attempt = 1; $attempt <= 15; ++$attempt) {
            $this->client->request('GET', '/reset-password');
            self::assertResponseRedirects('/forgot-password', message: \sprintf('Attempt %d should never be throttled — GET must not consume budget.', $attempt));
        }
    }

    /**
     * AC-16 (one answer for nine causes) — captured in one run, compared pairwise against each
     * other, never against a remembered literal. None of the nine may ever be a 404: the route
     * requirement is deliberately `[^/]+` so a mangled token still reaches the controller rather than
     * dead-ending at the router (AC-17).
     *
     * (a) expired, (b) unknown (well-formed, never issued), (c) malformed — driven through THREE of
     * AC-16's four named shapes (wrong length, non-base64url characters, 10 kB of junk; the fourth,
     * empty, cannot join this comparison — see
     * `testAnEmptyTokenPathHitsTheRouterTrailingSlashRedirectRatherThanTheInvalidLinkResponse()`
     * below for why and what it asserts instead), (d) invalidated (superseded), (e) already-redeemed,
     * (f) stale (issued before the user's `password_changed_at`), (g) dangling user (`user_id`
     * matches no row), (h) `GET /reset-password` with no stashed token, (i) `POST /reset-password`
     * with no stashed token.
     */
    public function testAllNineInvalidLinkCausesProduceByteIdenticalResponses(): void
    {
        $results = [];

        // (a) expired
        $expiredUser = UserFactory::createOne(['email' => Email::fromString('link-cause-expired@example.com')]);
        [$expiredToken] = $this->issueRequestFor($expiredUser->id(), expired: true);
        $results['expired'] = $this->captureCheckRouteResponse($expiredToken->reveal());

        // (b) unknown — well-formed, never issued
        $neverIssuedToken = $this->tokens->generate();
        $results['unknown'] = $this->captureCheckRouteResponse($neverIssuedToken->reveal());

        // (c) malformed — wrong length, still a single path segment
        $malformed = str_repeat('a', 42);
        $malformedResponse = $this->captureCheckRouteResponse($malformed);
        self::assertLessThan(500, $malformedResponse['status'], 'A malformed token must never produce a 5xx response (AC-17).');
        $results['malformed'] = $malformedResponse;

        // (c) malformed — non-base64url characters (right length, one character outside the
        // alphabet `ResetToken::fromString()` accepts). `+` rather than `/`, because a literal `/`
        // would split the path into two segments before this ever reaches route matching, which
        // would test the router's segmentation rather than `ResetToken`'s charset check.
        $malformedCharset = str_repeat('a', 42).'+';
        $malformedCharsetResponse = $this->captureCheckRouteResponse($malformedCharset);
        self::assertLessThan(500, $malformedCharsetResponse['status'], 'A non-base64url token must never produce a 5xx response (AC-17).');
        $results['malformed-non-base64url'] = $malformedCharsetResponse;

        // (c) malformed — 10 kB of junk, still a single path segment. The route's `[^/]+`
        // requirement does no length checking either (AC-17), so this is `ResetToken` alone
        // standing between an oversized payload and a database query.
        $malformedHuge = str_repeat('a', 10 * 1024);
        $malformedHugeResponse = $this->captureCheckRouteResponse($malformedHuge);
        self::assertLessThan(500, $malformedHugeResponse['status'], 'A 10 kB junk token must never produce a 5xx response (AC-17).');
        $results['malformed-10kb'] = $malformedHugeResponse;

        // (d) invalidated (superseded)
        $invalidatedUser = UserFactory::createOne(['email' => Email::fromString('link-cause-invalidated@example.com')]);
        $invalidatedToken = $this->tokens->generate();
        $invalidatedHash = $this->tokens->hash($invalidatedToken);
        PasswordResetRequestFactory::new(['userId' => $invalidatedUser->id(), 'tokenHash' => $invalidatedHash])->invalidated()->create();
        $results['invalidated'] = $this->captureCheckRouteResponse($invalidatedToken->reveal());

        // (e) already-redeemed (replay)
        $redeemedUser = UserFactory::createOne(['email' => Email::fromString('link-cause-redeemed@example.com')]);
        $redeemedToken = $this->tokens->generate();
        $redeemedHash = $this->tokens->hash($redeemedToken);
        PasswordResetRequestFactory::new(['userId' => $redeemedUser->id(), 'tokenHash' => $redeemedHash])->redeemed()->create();
        $results['already-redeemed'] = $this->captureCheckRouteResponse($redeemedToken->reveal());

        // (f) stale — issued strictly before the user's own `password_changed_at`
        $staleUser = UserFactory::createOne(['email' => Email::fromString('link-cause-stale@example.com')]);
        $foundStaleUser = $this->users->findById($staleUser->id());
        self::assertNotNull($foundStaleUser);
        $passwordChangedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $foundStaleUser->changePassword(HashedPassword::fromString('$2y$04$'.bin2hex(random_bytes(26))), $passwordChangedAt);
        $this->users->save($foundStaleUser);
        $staleToken = $this->tokens->generate();
        $staleHash = $this->tokens->hash($staleToken);
        PasswordResetRequestFactory::new([
            'userId' => $staleUser->id(),
            'tokenHash' => $staleHash,
        ])->issuedAt($passwordChangedAt->modify('-1 second'))->create();
        $results['stale'] = $this->captureCheckRouteResponse($staleToken->reveal());

        // (g) dangling user — `userId` deliberately left at the factory's default, a syntactically
        // valid id backed by no real `identity_user` row (legitimate: there is no FK, ADR-0009
        // decision 4).
        $danglingToken = $this->tokens->generate();
        $danglingHash = $this->tokens->hash($danglingToken);
        PasswordResetRequestFactory::new(['tokenHash' => $danglingHash])->create();
        $results['dangling-user'] = $this->captureCheckRouteResponse($danglingToken->reveal());

        // (h) GET /reset-password with no stashed token at all
        $this->client->request('GET', '/reset-password');
        $results['no-session-get'] = $this->captureCurrentResponse();

        // (i) POST /reset-password with no stashed token at all — rejected before the form is even
        // built, so no CSRF token is needed to reach it.
        $this->client->request('POST', '/reset-password', []);
        $results['no-session-post'] = $this->captureCurrentResponse();

        $reference = $results['unknown'];
        foreach ($results as $label => $result) {
            self::assertSame($reference['status'], $result['status'], "Status differed for case '{$label}'.");
            self::assertSame($reference['location'], $result['location'], "Location differed for case '{$label}'.");
            self::assertSame($reference['referrerPolicy'], $result['referrerPolicy'], "Referrer-Policy differed for case '{$label}'.");
            self::assertSame($reference['flash'], $result['flash'], "Flash differed for case '{$label}'.");
        }

        // The concrete shared shape, so a regression that broke all nine identically still fails.
        self::assertSame(Response::HTTP_FOUND, $reference['status']);
        self::assertSame('/forgot-password', $reference['location']);
        self::assertSame('no-referrer', $reference['referrerPolicy']);
        self::assertSame(
            'That link is no longer valid. Enter your address and we will send a new one.',
            $reference['flash'],
        );
    }

    /**
     * AC-16 case (c)'s fourth shape — empty — deliberately does **not** join the nine-way (now
     * eleven-way) comparison above, and forcing it in would misreport what actually happens: an
     * empty segment does not satisfy `{token}` = `[^/]+`, which requires at least one character, so
     * `/reset-password/` never matches `app_reset_password_check` at all and `PasswordResetController`
     * is never entered. Symfony's `RedirectableUrlMatcher` instead notices that trimming the
     * trailing slash would match the token-less `app_reset_password` route and issues a 301 there —
     * one response, distinguishable from the other eight causes by exactly one extra hop.
     *
     * Security impact is nil: an empty token confirms nothing about any account, and the 301 is
     * answered by the **router**, a layer earlier than any `catch` in this codebase could ever
     * reach — this is not a gap in `PasswordResetController`'s handling, it is a request that never
     * reaches it. Recorded explicitly, per the QA brief, rather than either forced into the pairwise
     * comparison (which would misreport this as identical to the other eight) or silently dropped
     * (which would leave this shape unasserted).
     */
    public function testAnEmptyTokenPathHitsTheRouterTrailingSlashRedirectRatherThanTheInvalidLinkResponse(): void
    {
        $this->client->request('GET', '/reset-password/');

        self::assertResponseRedirects('/reset-password', Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * A freshly fetched `PasswordResetRequestRepository`, deliberately not `$this->requests`. Doctrine's
     * identity map is keyed by primary key regardless of how the entity got there (a prior `find*()`
     * call or a Foundry factory's `persist()`), so once `$this->requests` has loaded a given row it
     * will keep handing back that same in-memory object on every later call — never re-querying —
     * unless asked for a repository that has never seen that row. A brand-new instance from the
     * container has an empty identity map, so its next query is a genuine round trip against
     * whatever the last HTTP request actually committed.
     */
    private function freshRequests(): PasswordResetRequestRepository
    {
        $requests = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $requests);

        return $requests;
    }

    /**
     * @return array{0: ResetToken, 1: HashedResetToken}
     */
    private function issueRequestFor(UserId $userId, bool $expired = false): array
    {
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);

        $factory = PasswordResetRequestFactory::new(['userId' => $userId, 'tokenHash' => $hash]);
        if ($expired) {
            $factory = $factory->expired();
        }
        $factory->create();

        return [$token, $hash];
    }

    /**
     * `GET /reset-password/{token}` and captures the single invalid-link outcome AC-16 collapses
     * nine causes into: status, `Location`, `Referrer-Policy` (read before following the redirect,
     * since it is `PasswordResetReferrerPolicy`, a `kernel.response` listener, that sets it on the
     * redirect response — a header `followRedirect()`'s subsequent request would not carry) and the
     * flash text once the forgot-password form has loaded.
     *
     * @return array{status: int, location: ?string, referrerPolicy: ?string, flash: string}
     */
    private function captureCheckRouteResponse(string $token): array
    {
        $this->client->request('GET', '/reset-password/'.rawurlencode($token));

        return $this->captureCurrentResponse();
    }

    /**
     * @return array{status: int, location: ?string, referrerPolicy: ?string, flash: string}
     */
    private function captureCurrentResponse(): array
    {
        $response = $this->client->getResponse();
        $status = $response->getStatusCode();
        $location = $response->headers->get('Location');
        $referrerPolicy = $response->headers->get('Referrer-Policy');

        $flash = $response->isRedirect()
            ? trim($this->client->followRedirect()->filter('.flash-error')->text(''))
            : '';

        return ['status' => $status, 'location' => $location, 'referrerPolicy' => $referrerPolicy, 'flash' => $flash];
    }
}
