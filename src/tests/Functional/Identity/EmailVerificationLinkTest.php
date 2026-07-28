<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\Port\VerificationTokenGenerator;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\VerificationToken;
use App\Tests\Factory\EmailVerificationRequestFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for `GET /verify-email/{token}` against the real `muzbar_test` database (DAMA
 * rollback).
 *
 * Fixtures are built directly through the `VerificationTokenGenerator` and
 * `EmailVerificationRequestRepository` ports rather than through the HTTP registration flow, which
 * is the only way a test can ever know the plaintext token a link's URL would need to carry — the
 * real flow deliberately never exposes it anywhere the test could read it back from (AC-2).
 */
final class EmailVerificationLinkTest extends WebTestCase
{
    private KernelBrowser $client;
    private UserRepository $users;
    private EmailVerificationRequestRepository $requests;
    private VerificationTokenGenerator $tokens;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $requests = self::getContainer()->get(EmailVerificationRequestRepository::class);
        self::assertInstanceOf(EmailVerificationRequestRepository::class, $requests);
        $this->requests = $requests;

        $tokens = self::getContainer()->get(VerificationTokenGenerator::class);
        self::assertInstanceOf(VerificationTokenGenerator::class, $tokens);
        $this->tokens = $tokens;
    }

    /**
     * AC-7, AC-13: a valid, unexpired, unredeemed token verifies the user, marks the request
     * redeemed, redirects to `/login` with a success flash, and starts no session — the visitor must
     * still authenticate afterwards.
     */
    public function testAValidLinkVerifiesTheUserAndRedirectsToLoginWithASuccessFlashAndNoSession(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('valid-link@example.com')]);
        [$token] = $this->issueRequestFor($user);

        $this->client->request('GET', '/verify-email/'.$token->reveal());

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your email address is verified');

        $foundUser = $this->users->findById($user->id());
        self::assertNotNull($foundUser);
        self::assertTrue($foundUser->isEmailVerified());
        self::assertTrue($foundUser->isUsable());

        // AC-13: no password check, no session. A protected page still bounces to /login.
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    /**
     * AC-8: replaying the exact same URL after a successful redemption does not change
     * `email_verified_at`, does not re-set `redeemed_at`, and answers with the friendly
     * "already verified" flash rather than an error.
     */
    public function testReplayingTheSameLinkAnswersWithTheAlreadyVerifiedFlashAndMutatesNothingFurther(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('replayed-link@example.com')]);
        [$token, $hash] = $this->issueRequestFor($user);

        // Followed immediately, exactly like `LoginLogoutTest`'s convention: a flash set in request
        // N is only readable in request N+1 (Symfony's `AutoExpireFlashBag` rotates on every
        // session load, whether or not a template actually reads it), so deferring the follow would
        // make the *next* request — the replay — the one that displays this message instead.
        $this->client->request('GET', '/verify-email/'.$token->reveal());
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your email address is verified');

        $firstVerifiedAt = $this->users->findById($user->id())?->emailVerifiedAt();
        self::assertNotNull($firstVerifiedAt);
        $firstRedeemedAt = $this->requests->findByTokenHash($hash)?->redeemedAt();
        self::assertNotNull($firstRedeemedAt);

        $this->client->request('GET', '/verify-email/'.$token->reveal());

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'already verified');

        self::assertEquals($firstVerifiedAt, $this->users->findById($user->id())?->emailVerifiedAt());
        self::assertEquals($firstRedeemedAt, $this->requests->findByTokenHash($hash)?->redeemedAt());
    }

    /**
     * AC-10, AC-11: an expired token and a well-formed-but-never-issued token answer with a
     * byte-identical response — same status, same `Location`, same flash text — so an attacker
     * probing tokens learns nothing that distinguishes the two internally-different causes.
     */
    public function testAnExpiredLinkAndAnUnknownLinkProduceByteIdenticalResponses(): void
    {
        $expiredUser = UserFactory::createOne(['email' => Email::fromString('expired-link@example.com')]);
        $expiredToken = $this->tokens->generate();
        $expiredHash = $this->tokens->hash($expiredToken);
        EmailVerificationRequestFactory::createOne([
            'userId' => $expiredUser->id(),
            'tokenHash' => $expiredHash,
            // Real wall-clock time is fine here: no FrozenClock is in play in this Functional test,
            // so "expired relative to real now" is exactly what the controller's real Clock will
            // also see.
            'issuedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(\sprintf('-%d seconds', EmailVerificationRequest::LIFETIME_SECONDS + 3600)),
        ]);

        $expiredResponse = $this->requestVerifyEmailAndCaptureInvalidLinkResponse($expiredToken->reveal());

        $neverIssuedToken = $this->tokens->generate();
        $unknownResponse = $this->requestVerifyEmailAndCaptureInvalidLinkResponse($neverIssuedToken->reveal());

        self::assertSame($expiredResponse['status'], $unknownResponse['status']);
        self::assertSame($expiredResponse['location'], $unknownResponse['location']);
        self::assertSame($expiredResponse['flash'], $unknownResponse['flash']);
        self::assertSame('That link is no longer valid. Enter your address and we will send a new one.', $unknownResponse['flash']);

        self::assertSame('/verify-email/resend', $expiredResponse['location']);
    }

    /**
     * AC-11, AC-12: a malformed token — wrong length, wrong charset, or simply enormous — now
     * answers exactly like a well-formed-but-never-issued one, because `VerificationToken::fromString()`
     * is the **only** gate on token format left in the system.
     *
     * THIS TEST USED TO ASSERT A BARE 404, AND THAT WAS THE BUG, NOT A REINTERPRETATION OF THE SPEC.
     * `{token}`'s route `requirements` used to duplicate `VerificationToken`'s own
     * `[A-Za-z0-9_-]{43}` rule, so a malformed segment was rejected by the *router* before the
     * controller — and therefore the value object, and therefore `EmailVerificationController`'s
     * `InvalidVerificationToken` catch block — ever ran. That produced a bare 404 instead of the
     * resend-form redirect AC-12 and the failure-contract table both actually call for, which is
     * exactly the scenario the catch block's own comment names as the common case: a mail client
     * line-wraps a long link, or someone pastes it badly, and the fix is to send that person to the
     * form that lets them ask for a new one — not a dead end. The route requirement has been
     * loosened to `[^/]+` for precisely this reason; see `EmailVerificationController`'s class
     * docblock ("ROUTE ORDERING...") and `VerificationToken`'s own ("THIS IS THE ONLY PLACE THAT
     * FORMAT IS CHECKED...") for the full argument against re-adding it.
     *
     * RE-ADDING THE ROUTE REGEX WOULD SILENTLY REINTRODUCE THE BUG THIS TEST NOW GUARDS AGAINST.
     * Every other test in this file drives a well-formed 43-character token, so a duplicated route
     * requirement would pass every one of them while quietly turning every malformed link back into
     * a dead 404 — invisible until a real user hits it. That is also why the assertions below
     * compare against a *freshly generated, genuinely unknown* token's response rather than a
     * hardcoded status/location/flash triple: AC-11 and AC-12 both hinge on the two outcomes being
     * indistinguishable, so the test asserts that structurally, via
     * `requestVerifyEmailAndCaptureInvalidLinkResponse()` (shared with the AC-10/AC-11 test above),
     * instead of via two copies of the expected literals that could quietly drift apart from each
     * other exactly as the old 404 assertion drifted from the code.
     *
     * WHY THERE IS NO `/`-CONTAINING CASE HERE. A literal slash can never survive as part of a
     * single `{token}` path segment — `[^/]+` excludes it by construction, the same as the old
     * `{43}` regex did, and a URL-encoded `%2F` decodes back to `/` before the router ever compares
     * it (verified empirically against this stack, not assumed). A token containing one therefore
     * still 404s, but for an entirely different reason than AC-12 is about: the request never
     * matches *any* route, so it never reaches `VerificationToken::fromString()` to be rejected by.
     * `testATokenContainingASlashCanNeverBeRoutedAndStill404sRatherThan500s` below covers that case
     * on its own terms instead of forcing it into this data provider's shared expectation, where it
     * would either fail for the wrong reason or need a special-cased assertion that defeats the
     * point of asserting structurally.
     *
     * Every value here is `rawurlencode()`d before being appended to the request path in
     * `requestVerifyEmailAndCaptureInvalidLinkResponse()`, which is what a real browser or `curl`
     * does with a character like a space before it ever leaves the client — sending one raw (as an
     * earlier version of this junk case did) makes `Request::create()` throw before Symfony's
     * router, let alone this application, ever sees the request, which is a real HTTP-client
     * behaviour this test has no business asserting against.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedTokenProvider(): iterable
    {
        yield 'one character short (42)' => [str_repeat('a', 42)];
        yield 'one character long (44)' => [str_repeat('a', 44)];
        yield 'contains a standard-base64 plus' => [str_repeat('a', 21).'+'.str_repeat('a', 21)];
        yield 'ten kilobytes of junk' => [str_repeat('a', 10 * 1024)];

        // Both of these are cases the loosened `[^/]+` route requirement *newly admits* to the
        // controller — the old `{43}`-length regex rejected them at the router just like every case
        // above, so nothing ever exercised this path with input shaped like this before.
        yield 'a long junk token (~400 characters, invalid charset)' => [str_repeat('!@#$%^&*() ', 40)];
        yield 'wrong length but valid base64url charset (100 characters)' => [str_repeat('aZ09_-', 17)];
    }

    /**
     * @param non-empty-string $malformed
     */
    #[DataProvider('malformedTokenProvider')]
    public function testAMalformedTokenIsRejectedByTheValueObjectAndAnswersLikeAnUnknownToken(string $malformed): void
    {
        $malformedResponse = $this->requestVerifyEmailAndCaptureInvalidLinkResponse($malformed);

        // The interface-boundary table's real guarantee, kept as its own assertion because it is
        // the one thing that would still matter even if every assertion below it were deleted: no
        // malformed input — however it is shaped — may ever produce a 500, a stack trace, or SQL in
        // the output. `catchExceptions` is deliberately left at its default (`true`), so an
        // uncaught exception here would surface as a 500 *response* rather than escape the test.
        self::assertLessThan(500, $malformedResponse['status'], 'A malformed token must never produce a 5xx response.');

        $neverIssuedToken = $this->tokens->generate();
        $unknownResponse = $this->requestVerifyEmailAndCaptureInvalidLinkResponse($neverIssuedToken->reveal());

        self::assertSame($unknownResponse['status'], $malformedResponse['status']);
        self::assertSame($unknownResponse['location'], $malformedResponse['location']);
        self::assertSame($unknownResponse['referrerPolicy'], $malformedResponse['referrerPolicy']);
        self::assertSame($unknownResponse['flash'], $malformedResponse['flash']);

        // And the concrete shape of that shared outcome, so a future change that breaks both sides
        // identically (and therefore leaves the comparisons above green) still fails this test.
        self::assertSame(302, $malformedResponse['status']);
        self::assertSame('/verify-email/resend', $malformedResponse['location']);
        self::assertSame('no-referrer', $malformedResponse['referrerPolicy']);
        self::assertSame('That link is no longer valid. Enter your address and we will send a new one.', $malformedResponse['flash']);
    }

    /**
     * The one malformed shape that is not covered by `malformedTokenProvider` above, and cannot be:
     * a token containing a literal `/` splits `/verify-email/{token}` into more path segments than
     * the route has, so it 404s at the router — never reaching `VerificationToken::fromString()`,
     * the controller, or a database query — regardless of how permissive `{token}`'s requirement is.
     * This is expected, correct routing behaviour, not a gap in AC-12's coverage; the assertion
     * worth keeping is the same "never a 5xx" guarantee the interface-boundary table asks for, on
     * the one shape of malformed input that takes a structurally different path to get there.
     */
    public function testATokenContainingASlashCanNeverBeRoutedAndStill404sRatherThan500s(): void
    {
        $containsSlash = str_repeat('a', 21).'/'.str_repeat('a', 21);

        $this->client->request('GET', '/verify-email/'.rawurlencode($containsSlash));

        self::assertLessThan(500, $this->client->getResponse()->getStatusCode(), 'A malformed token must never produce a 5xx response.');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'No route can match a path segment containing "/", so this is a routing 404, not the AC-11/AC-12 invalid-link outcome.');
    }

    /**
     * AC-39: `Referrer-Policy: no-referrer` is present on both the success response and the
     * invalid-link failure response — the token travels in the URL path, so both directions of this
     * flow are where it could otherwise leak into a `Referer` header on the page navigated to next.
     */
    public function testBothSuccessAndFailureResponsesCarryTheNoReferrerHeader(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('referrer-policy-success@example.com')]);
        [$token] = $this->issueRequestFor($user);

        $this->client->request('GET', '/verify-email/'.$token->reveal());
        self::assertResponseRedirects('/login');
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));

        $neverIssuedToken = $this->tokens->generate();
        $this->client->request('GET', '/verify-email/'.$neverIssuedToken->reveal());
        self::assertResponseRedirects('/verify-email/resend');
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
    }

    /**
     * @return array{0: VerificationToken, 1: \App\Domain\Identity\ValueObject\HashedVerificationToken}
     */
    private function issueRequestFor(User $user): array
    {
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        EmailVerificationRequestFactory::createOne(['userId' => $user->id(), 'tokenHash' => $hash]);

        return [$token, $hash];
    }

    /**
     * `GET /verify-email/{token}` and captures the single "invalid link" outcome AC-10, AC-11 and
     * AC-12 all collapse into one shared shape: the 302 status, its `Location`, its
     * `Referrer-Policy` header (read *before* following the redirect, because
     * `EmailVerificationController::noReferrer()` sets it on the redirect response itself rather
     * than on the page it points to — reading it after `followRedirect()` would silently pass for
     * the wrong reason, or fail to notice its absence), and the flash text once the resend form has
     * loaded.
     *
     * Centralising the capture here — rather than each test re-deriving its own copy — is what lets
     * AC-11 and AC-12 be asserted as *one* comparison between two live responses instead of two
     * independent copies of the expected literals that can silently drift apart. That drift is
     * exactly what put this file's own AC-12 test out of date: it hardcoded "404" once, the route
     * requirement it depended on was deliberately loosened for a different, well-justified reason
     * (see `testAMalformedTokenIsRejectedByTheValueObjectAndAnswersLikeAnUnknownToken`'s docblock),
     * and nothing about that change touched this literal, so the suite kept the wrong expectation
     * alive until the next full run caught it.
     *
     * `rawurlencode()`s the token before building the path — what a real browser or `curl` does
     * with a character a URL path segment cannot carry raw (a space, most obviously) before the
     * request ever leaves the client. Sending one unencoded, as an earlier version of the ~400
     * character junk case in `malformedTokenProvider()` did, makes `Request::create()` throw before
     * Symfony's router — let alone this application — ever sees the request, which tests an HTTP
     * client's own validation rather than anything this application controls.
     *
     * @return array{status: int, location: ?string, referrerPolicy: ?string, flash: string}
     */
    private function requestVerifyEmailAndCaptureInvalidLinkResponse(string $token): array
    {
        $this->client->request('GET', '/verify-email/'.rawurlencode($token));
        $response = $this->client->getResponse();

        $status = $response->getStatusCode();
        $location = $response->headers->get('Location');
        $referrerPolicy = $response->headers->get('Referrer-Policy');

        // Guarded, not unconditional: `followRedirect()` throws `BadMethodCallException` on a
        // non-redirect response, which would abort this method — and every caller with it — before
        // the "never a 5xx" assertion callers make against `status` ever runs. A regression to a
        // 404 or a 500 has to be reported by that assertion, not hidden behind an exception thrown
        // one line earlier for an unrelated reason.
        // `text('')` rather than a bare `text()` for the same reason the `isRedirect()` guard
        // exists one line up: `Crawler::text()` with no default throws `InvalidArgumentException`
        // on an empty node list, so a redirect whose target rendered no `.flash-error` would abort
        // this helper before the caller's "never a 5xx" assertion could report. Every abort-before-
        // assert path in here has to degrade to a value the caller can assert on instead.
        $flash = $response->isRedirect()
            ? trim($this->client->followRedirect()->filter('.flash-error')->text(''))
            : '';

        return ['status' => $status, 'location' => $location, 'referrerPolicy' => $referrerPolicy, 'flash' => $flash];
    }
}
