<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthProvider;
use App\Infrastructure\Identity\Security\GoogleAuthenticator;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\DrivesGoogleOAuth;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Doctrine\DBAL\Connection;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * "Continue with Google" through the real stack, Google scripted at the HTTP layer (AC-1, AC-3,
 * AC-5 to AC-12, AC-16, AC-18). One client per scenario, `disableReboot()` so the scripted Google
 * and the log handler are the instances the requests used.
 */
final class GoogleSignInFlowTest extends WebTestCase
{
    use ClearsRateLimiters;
    use DrivesGoogleOAuth;
    use RegistersAUserWithKnownCredentials;

    private ?string $originalClientId = null;
    private ?string $originalEnvClientId = null;
    private bool $hadClientId = false;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
    }

    protected function tearDown(): void
    {
        if ($this->hadClientId) {
            $_SERVER['GOOGLE_CLIENT_ID'] = $this->originalClientId;
            $_ENV['GOOGLE_CLIENT_ID'] = $this->originalEnvClientId;
        }
        parent::tearDown();
    }

    // ---- starting the flow (AC-1, AC-2) -------------------------------------------------------

    public function testStartRedirectsToGoogleWithExactlyTheMandatedParameters(): void
    {
        $flow = $this->startGoogleFlow($this->client);

        $parts = parse_url($flow['location']);
        self::assertIsArray($parts);
        self::assertSame('https', $parts['scheme'] ?? null);
        self::assertSame('accounts.google.com', $parts['host'] ?? null);
        self::assertSame('/o/oauth2/v2/auth', $parts['path'] ?? null);

        $params = $flow['params'];
        self::assertSame('code', $params['response_type'] ?? null);
        self::assertSame('openid email', $params['scope'] ?? null, 'Exactly openid and email, no profile.');
        self::assertSame('test-client-id.apps.googleusercontent.com', $params['client_id'] ?? null);
        self::assertNotSame('', $flow['state']);
        self::assertSame('S256', $params['code_challenge_method'] ?? null);
        self::assertSame(43, \strlen($flow['challenge']));
    }

    public function testTheRedirectUriIsTheAbsoluteUrlOfTheCheckRoute(): void
    {
        $flow = $this->startGoogleFlow($this->client);

        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertSame(
            $router->generate('app_oauth_google_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $flow['params']['redirect_uri'] ?? null,
        );
        self::assertStringEndsWith('/connect/google/check', $flow['params']['redirect_uri']);
    }

    public function testStateAndPkceVerifierNeverAppearInACookieOrInAnyUrlOfOurOwn(): void
    {
        $flow = $this->startGoogleFlow($this->client);

        self::assertNotEmpty($this->client->getCookieJar()->all(), 'The session cookie must exist, or the check below proves nothing.');
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            self::assertStringNotContainsString($flow['state'], $cookie->getValue());
            self::assertStringNotContainsString($flow['challenge'], $cookie->getValue());
        }
        $this->client->request('GET', '/connect/google/check?state=x');
        self::assertStringNotContainsString($flow['state'], (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testThePkceVerifierSentToGoogleHashesToTheChallengeInTheStartRedirect(): void
    {
        $flow = $this->signInWithGoogle($this->client, $this->claims('sub-pkce', 'pkce@example.com'));

        $fields = $this->fakeGoogle()->tokenRequestFields();
        self::assertNotNull($fields, 'The code must be redeemed at the token endpoint.');
        $verifier = $fields['code_verifier'] ?? null;
        self::assertIsString($verifier);
        self::assertNotSame('', $verifier);
        self::assertSame(
            $flow['challenge'],
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        );
    }

    public function testTheCodeIsRedeemedWithTheSecretAndAnAuthorizationCodeGrantAndUserinfoIsCalledWithTheBearerToken(): void
    {
        $this->signInWithGoogle($this->client, $this->claims('sub-wire', 'wire@example.com'));

        $fields = $this->fakeGoogle()->tokenRequestFields();
        self::assertNotNull($fields);
        self::assertSame('authorization_code', $fields['grant_type'] ?? null);
        self::assertSame(self::CODE, $fields['code'] ?? null);
        self::assertSame('test-client-secret', $fields['client_secret'] ?? null);

        $requests = $this->fakeGoogle()->requests();
        self::assertCount(2, $requests);
        self::assertSame('Bearer '.\App\Tests\Fixture\FakeGoogleHttpClient::ACCESS_TOKEN, $requests[1]->getHeaderLine('Authorization'));
    }

    // ---- the empty client id guard (AC-3) -----------------------------------------------------

    /**
     * Runs in a dedicated kernel: the env var is read when the controller is built, so it must be
     * changed before boot. `default::` turns an empty or missing variable into `null`, and the
     * controller treats `null` and `''` alike — both are driven here.
     */
    #[DataProvider('unconfiguredClientIds')]
    public function testWithNoClientIdTheStartRouteDoesNotRedirectToGoogleAndShowsAFlash(?string $value): void
    {
        $this->bootWithClientId($value);

        $this->client->request('GET', '/connect/google');

        self::assertResponseRedirects('/login');
        self::assertStringNotContainsString('google.com', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Google sign-in is temporarily unavailable.');
    }

    #[DataProvider('unconfiguredClientIds')]
    public function testWithNoClientIdExactlyOneErrorRecordNamesTheMissingConfiguration(?string $value): void
    {
        $this->bootWithClientId($value);

        $this->client->request('GET', '/connect/google');

        $errors = $this->logRecordsAtOrAbove(Level::Error);
        self::assertCount(1, $errors);
        self::assertStringContainsString('GOOGLE_CLIENT_ID', $errors[0]->message);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unconfiguredClientIds(): iterable
    {
        yield 'empty string' => [''];
        yield 'unset' => [null];
    }

    // ---- recognise (AC-5) ---------------------------------------------------------------------

    public function testARecognisedSubjectSignsInAndWritesNothingEvenWithADifferentUnverifiedEmail(): void
    {
        $user = UserFactory::new(['email' => Email::fromString('account@example.com')])->provisionedViaGoogle('sub-known')->create();
        $before = $this->row($user->id()->toString());

        $this->signInWithGoogle($this->client, $this->claims('sub-known', 'other@example.com', emailVerified: false));

        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.account-email', 'account@example.com');
        self::assertSame($before, $this->row($user->id()->toString()));
        self::assertSame(1, $this->userCount());
    }

    // ---- provision (AC-6, AC-7, AC-16) --------------------------------------------------------

    public function testAnUnknownSubjectProvisionsAPasswordlessVerifiedUserAndSignsThemIn(): void
    {
        $this->signInWithGoogle($this->client, $this->claims('sub-new', 'New.Person@Example.COM'));

        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.account-email', 'new.person@example.com');

        self::assertSame(1, $this->userCount());
        $row = $this->rowByEmail('new.person@example.com');
        self::assertNull($row['password_hash']);
        self::assertIsString($row['roles']);
        self::assertJsonStringEqualsJsonString('["ROLE_USER"]', $row['roles']);
        self::assertIsString($row['oauth_identities']);
        self::assertJsonStringEqualsJsonString('[{"provider":"google","subject":"sub-new"}]', $row['oauth_identities']);
        self::assertNotNull($row['email_verified_at']);
        self::assertSame($row['registered_at'], $row['email_verified_at']);

        $user = $this->freshUsers()->findByEmail(Email::fromString('new.person@example.com'));
        self::assertNotNull($user);
        self::assertTrue($user->isUsable());
    }

    public function testProvisioningSendsNoMailCreatesNoVerificationRequestAndLogsNoError(): void
    {
        $this->signInWithGoogle($this->client, $this->claims('sub-new', 'quiet@example.com'));

        self::assertResponseRedirects('/account');
        self::assertEmailCount(0);
        self::assertSame(0, $this->rowCount('identity_email_verification_request'));
        self::assertSame([], $this->logRecordsAtOrAbove(Level::Error), 'Without the listener fix every Google sign-up logs an error to Sentry.');
    }

    // ---- link (AC-8, AC-10, AC-16) ------------------------------------------------------------

    public function testAnUnknownSubjectWithAVerifiedAccountsEmailLinksAndSignsInWithoutTouchingThePassword(): void
    {
        $existing = $this->registerUserWithKnownCredentials('verified@example.com', 'the-existing-password-1', verified: true);
        $hash = $this->row($existing->id()->toString())['password_hash'];
        self::assertNotNull($hash);

        $this->signInWithGoogle($this->client, $this->claims('sub-link', 'verified@example.com'));

        self::assertResponseRedirects('/account');
        self::assertSame(1, $this->userCount());
        self::assertSame($hash, $this->row($existing->id()->toString())['password_hash']);
        $linked = $this->freshUsers()->findByOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'sub-link'));
        self::assertNotNull($linked);
        self::assertTrue($linked->id()->equals($existing->id()));
        self::assertTrue($linked->isUsable());
    }

    public function testAMixedCaseGoogleEmailLinksToTheLowerCaseAccount(): void
    {
        $existing = UserFactory::new(['email' => Email::fromString('musician@example.com')])->verified()->create();

        $this->signInWithGoogle($this->client, $this->claims('sub-case', 'Musician@Example.COM'));

        self::assertResponseRedirects('/account');
        self::assertSame(1, $this->userCount());
        $linked = $this->freshUsers()->findByOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'sub-case'));
        self::assertNotNull($linked);
        self::assertTrue($linked->id()->equals($existing->id()));
    }

    public function testAnEmailTheEmailValueObjectRejectsGivesTheNeutralFailureAndWritesNothing(): void
    {
        $this->signInWithGoogle($this->client, $this->claims('sub-bad', 'not-an-email'));

        $this->assertNeutralFailure();
        self::assertSame(0, $this->userCount());
    }

    // ---- pre-hijack (AC-12) -------------------------------------------------------------------

    public function testLinkingToAnUnverifiedPasswordAccountSignsInDiscardsThePasswordAndVerifiesTheEmail(): void
    {
        $existing = $this->registerUserWithKnownCredentials('victim@example.com', 'the-attackers-password-1');
        self::assertNull($this->row($existing->id()->toString())['email_verified_at']);

        $this->signInWithGoogle($this->client, $this->claims('sub-victim', 'victim@example.com'));

        self::assertResponseRedirects('/account');
        $row = $this->row($existing->id()->toString());
        self::assertNull($row['password_hash'], 'The unproven password must not survive the link (I-29).');
        self::assertNotNull($row['email_verified_at']);
        self::assertNull($row['password_changed_at']);
        self::assertSame(1, $this->userCount());
        $user = $this->freshUsers()->findById($existing->id());
        self::assertNotNull($user);
        self::assertTrue($user->isUsable());
    }

    public function testTheDiscardedPasswordNoLongerOpensTheAccount(): void
    {
        $this->registerUserWithKnownCredentials('victim@example.com', 'the-attackers-password-1');
        $this->signInWithGoogle($this->client, $this->claims('sub-victim', 'victim@example.com'));
        $this->client->getCookieJar()->clear(); // a different browser: logout itself is a CSRF-protected POST

        $this->passwordLogin('victim@example.com', 'the-attackers-password-1');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextSame('p.error', 'Invalid credentials.');
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    public function testLinkingToAnUnverifiedAccountSendsNoPasswordChangedMail(): void
    {
        $this->registerUserWithKnownCredentials('victim@example.com', 'the-attackers-password-1');

        $this->signInWithGoogle($this->client, $this->claims('sub-victim', 'victim@example.com'));

        self::assertEmailCount(0);
    }

    // ---- unverified provider email (AC-9) -----------------------------------------------------

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('notVerifiedClaims')]
    public function testAnEmailGoogleHasNotVerifiedIsRefusedWithTheSpecificMessageAndCreatesNothing(array $claims): void
    {
        $this->signInWithGoogle($this->client, $claims);

        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSame(GoogleAuthenticator::EMAIL_NOT_VERIFIED, trim($crawler->filter('p.error')->text()));
        self::assertSame(0, $this->userCount());
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('notVerifiedClaims')]
    public function testAnEmailGoogleHasNotVerifiedLinksNothingToAnExistingAccount(array $claims): void
    {
        $existing = UserFactory::new(['email' => Email::fromString('target@example.com')])->verified()->create();
        $before = $this->row($existing->id()->toString());

        $this->signInWithGoogle($this->client, $claims);

        self::assertSame($before, $this->row($existing->id()->toString()));
        self::assertNull($this->freshUsers()->findByOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'sub-unverified')));
    }

    /**
     * Strictly `=== true`: the string "true" and the number 1 are as unverified as `false`.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function notVerifiedClaims(): iterable
    {
        $base = ['sub' => 'sub-unverified', 'email' => 'target@example.com'];

        yield 'false' => [$base + ['email_verified' => false]];
        yield 'missing' => [$base];
        yield 'the string "true"' => [$base + ['email_verified' => 'true']];
        yield 'the number 1' => [$base + ['email_verified' => 1]];
        yield 'null' => [$base + ['email_verified' => null]];
    }

    // ---- one Google subject per user (AC-11) --------------------------------------------------

    public function testAnAccountThatAlreadyHoldsADifferentGoogleSubjectGetsTheNeutralFailureAndNothingChanges(): void
    {
        $existing = UserFactory::new(['email' => Email::fromString('two@example.com')])->provisionedViaGoogle('sub-first')->create();
        $before = $this->row($existing->id()->toString());

        $this->signInWithGoogle($this->client, $this->claims('sub-second', 'two@example.com'));

        $this->assertNeutralFailure();
        self::assertSame($before, $this->row($existing->id()->toString()));
        self::assertSame(1, $this->userCount());
    }

    // ---- session (AC-18) ----------------------------------------------------------------------

    public function testASuccessfulSignInMigratesTheSessionId(): void
    {
        $flow = $this->startGoogleFlow($this->client);
        $before = $this->client->getCookieJar()->all();
        self::assertCount(1, $before, 'Only the session cookie should exist before the callback.');
        $name = $before[array_key_first($before)]->getName();
        $idBefore = $this->client->getCookieJar()->get($name)?->getValue();

        $this->fakeGoogle()->succeedWith($this->claims('sub-fixation', 'fixation@example.com'));
        $this->hitCallback($this->client, $flow['state']);

        self::assertResponseRedirects('/account');
        $idAfter = $this->client->getCookieJar()->get($name)?->getValue();
        self::assertNotNull($idBefore);
        self::assertNotNull($idAfter);
        self::assertNotSame($idBefore, $idAfter, 'The session id must change on authentication.');
    }

    public function testASuccessfulSignInReturnsToTheSavedTargetPath(): void
    {
        $this->client->request('GET', '/account?marker=saved-target');
        self::assertResponseRedirects('/login');

        $this->signInWithGoogle($this->client, $this->claims('sub-target', 'target-path@example.com'));

        self::assertResponseRedirects('http://localhost/account?marker=saved-target');
    }

    public function testASuccessfulSignInWithNoSavedTargetGoesToAccount(): void
    {
        $this->signInWithGoogle($this->client, $this->claims('sub-default', 'default-path@example.com'));

        self::assertResponseRedirects('/account');
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function bootWithClientId(?string $value): void
    {
        $this->hadClientId = true;
        $server = $_SERVER['GOOGLE_CLIENT_ID'] ?? null;
        $env = $_ENV['GOOGLE_CLIENT_ID'] ?? null;
        $this->originalClientId = \is_string($server) ? $server : null;
        $this->originalEnvClientId = \is_string($env) ? $env : null;

        if (null === $value) {
            unset($_SERVER['GOOGLE_CLIENT_ID'], $_ENV['GOOGLE_CLIENT_ID']);
        } else {
            $_SERVER['GOOGLE_CLIENT_ID'] = $_ENV['GOOGLE_CLIENT_ID'] = $value;
        }

        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
    }

    private function assertNeutralFailure(): void
    {
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSame(GoogleAuthenticator::NEUTRAL_FAILURE, trim($crawler->filter('p.error')->text()));
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    private function passwordLogin(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $csrf = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', ['_username' => $email, '_password' => $password, '_csrf_token' => $csrf]);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function freshUsers(): UserRepository
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return $users;
    }

    private function rowCount(string $table): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function userCount(): int
    {
        return $this->rowCount('identity_user');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id): array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM identity_user WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowByEmail(string $email): array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM identity_user WHERE email = :e', ['e' => $email]);
        self::assertIsArray($row, 'Expected a row for '.$email);

        return $row;
    }
}
