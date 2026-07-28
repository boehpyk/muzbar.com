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

        $this->client->request('GET', '/verify-email/'.$expiredToken->reveal());
        $expiredResponse = $this->client->getResponse();
        $expiredStatus = $expiredResponse->getStatusCode();
        $expiredLocation = $expiredResponse->headers->get('Location');
        $expiredFlash = trim($this->client->followRedirect()->filter('.flash-error')->text());

        $neverIssuedToken = $this->tokens->generate();
        $this->client->request('GET', '/verify-email/'.$neverIssuedToken->reveal());
        $unknownResponse = $this->client->getResponse();
        $unknownStatus = $unknownResponse->getStatusCode();
        $unknownLocation = $unknownResponse->headers->get('Location');
        $unknownFlash = trim($this->client->followRedirect()->filter('.flash-error')->text());

        self::assertSame($expiredStatus, $unknownStatus);
        self::assertSame($expiredLocation, $unknownLocation);
        self::assertSame($expiredFlash, $unknownFlash);
        self::assertSame('That link is no longer valid. Enter your address and we will send a new one.', $unknownFlash);

        self::assertSame('/verify-email/resend', $expiredLocation);
    }

    /**
     * AC-12, reinterpreted per the QA brief against the running stack: the spec's literal claim that
     * a malformed token produces "the same response" as the unknown case is internally inconsistent
     * with the technical plan, and the plan is right. `{token}` carries a route `requirements` regex
     * of exactly 43 base64url characters (`VerificationToken::LENGTH`), so a malformed token —
     * wrong length, wrong charset — never reaches the controller or the value object at all: it is a
     * 404 from the Symfony router. A functional test runs in-process with no nginx in front of it,
     * so the 10 kB case (which the technical plan says would be a 414 from nginx in production)
     * cannot be observed as a 414 here — it is likewise a 404, rejected by the same route
     * requirement before it reaches PHP logic. What both share, and what actually matters, is that
     * neither is ever a 500: the interface boundary table's real guarantee ("never a 500") is what
     * this test asserts, not byte-identity with the unknown-token case, which the route regex makes
     * structurally unreachable for anything malformed.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedTokenProvider(): iterable
    {
        yield 'one character short (42)' => [str_repeat('a', 42)];
        yield 'one character long (44)' => [str_repeat('a', 44)];
        yield 'contains a standard-base64 plus' => [str_repeat('a', 21).'+'.str_repeat('a', 21)];
        yield 'contains a standard-base64 slash' => [str_repeat('a', 21).'/'.str_repeat('a', 21)];
        yield 'ten kilobytes of junk' => [str_repeat('a', 10 * 1024)];
    }

    /**
     * @param non-empty-string $malformed
     */
    #[DataProvider('malformedTokenProvider')]
    public function testAMalformedTokenIsRejectedByTheRouteAndNeverProducesA500(string $malformed): void
    {
        // `catchExceptions` is deliberately left at its default (`true`): that is what makes the
        // kernel's own exception listener turn a routing failure into a normal 404 response, which
        // is exactly the mechanism this test wants to observe (and is also what would turn a real
        // uncaught 500 into a 500 *response* rather than a PHP exception escaping the test) —
        // disabling it would test the router's exception type rather than the response the
        // application actually produces.
        $this->client->request('GET', '/verify-email/'.$malformed);

        $status = $this->client->getResponse()->getStatusCode();
        self::assertLessThan(500, $status, 'A malformed token must never produce a 5xx response.');
        self::assertSame(404, $status, 'The route requirement rejects a malformed token before it ever reaches the controller.');
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
}
