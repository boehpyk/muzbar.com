<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\ValueObject\Email;
use App\Infrastructure\Identity\Persistence\Doctrine\DoctrineUserRepository;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\FakeGoogleHttpClient;
use App\Tests\Fixture\StaleEmailLookupUserRepository;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\DrivesGoogleOAuth;
use App\Tests\Support\ReadsSentryEvents;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\Event;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What "Continue with Google" writes to the logs and to Sentry (AC-19, AC-20).
 *
 * Every secret is a distinctive canary string, so a hit can only mean a real leak. The scan covers
 * every record the box can emit, on every channel (the `TestHandler` in `config/packages/test/`),
 * not just the `app` channel — see `emittedLogRecords()` for exactly what that covers.
 */
final class GoogleSignInPrivacyTest extends WebTestCase
{
    use ClearsRateLimiters;
    use DrivesGoogleOAuth;
    use ReadsSentryEvents;
    use RegistersAUserWithKnownCredentials;

    private const string SUB = 'SUB-7731-PRIVATE-CANARY';
    private const string EMAIL = 'Privacy.Canary@Example.com';
    private const string ACCOUNT_EMAIL = 'account.canary@example.com';
    private const string PROVIDER_DETAIL = 'SECRET-DETAIL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
    }

    // ---- AC-19: nothing secret in any log record ---------------------------------------------

    #[DataProvider('scenarios')]
    public function testNoLogRecordContainsTheEmailSubjectCodeStateVerifierOrAccessToken(string $scenario): void
    {
        $secrets = $this->exercise($scenario) + [
            'asserted email' => self::EMAIL,
            'asserted email, lower-cased' => strtolower(self::EMAIL),
            'account email' => self::ACCOUNT_EMAIL,
            'subject' => self::SUB,
            'authorization code' => self::CODE,
            'access token' => FakeGoogleHttpClient::ACCESS_TOKEN,
            'provider error detail' => self::PROVIDER_DETAIL,
            'client secret' => 'test-client-secret',
        ];

        self::assertNotEmpty($this->logRecords(), 'The recording handler captured nothing at all, so it is not wired and a scan would prove nothing.');
        foreach ($this->emittedLogRecords() as $record) {
            $haystack = $this->flatten($record);
            foreach ($secrets as $name => $secret) {
                self::assertStringNotContainsString($secret, $haystack, "The $name leaked into a '{$record->channel}' log record: {$record->message}");
            }
        }
    }

    /**
     * Each scenario drives a different branch of AC-5 to AC-14 and returns the per-run secrets
     * (state, verifier) that only exist once the flow has started.
     *
     * @return iterable<string, array{string}>
     */
    public static function scenarios(): iterable
    {
        foreach ([
            'recognise (AC-5)',
            'provision (AC-6)',
            'link to a verified account (AC-8)',
            'unverified provider email (AC-9)',
            'invalid asserted email (AC-10)',
            'second Google subject (AC-11)',
            'pre-hijack link (AC-12)',
            'lost provision race',
            'bad state (AC-13)',
            'other session (AC-13)',
            'access_denied (AC-13)',
            'missing code',
            'token exchange refused',
            'userinfo down',
            'passwordless form login (AC-14)',
        ] as $name) {
            yield $name => [$name];
        }
    }

    // ---- AC-19 + the failure contract's log levels -------------------------------------------

    public function testAUserCausedRefusalIsLoggedAtWarningOrBelowWithOnlyAReasonInTheContext(): void
    {
        $this->exercise('bad state (AC-13)');

        $app = $this->appLogRecords();
        self::assertCount(1, $app);
        self::assertSame(Level::Warning, $app[0]->level);
        self::assertSame(['reason'], array_keys($app[0]->context));
    }

    public function testACallbackInAnotherSessionIsLoggedAtWarningBecauseTheVisitorCausedIt(): void
    {
        $this->exercise('other session (AC-13)');

        $app = $this->appLogRecords();
        self::assertCount(1, $app);
        self::assertSame(Level::Warning, $app[0]->level);
        self::assertSame('missing_pkce_verifier', $app[0]->context['reason'] ?? null);
    }

    public function testALostProvisionRaceIsLoggedAtWarningNamingTheExceptionClassNotItsMessage(): void
    {
        $this->exercise('lost provision race');

        $app = $this->appLogRecords();
        self::assertCount(1, $app);
        self::assertSame(Level::Warning, $app[0]->level);
        self::assertSame(\App\Domain\Identity\Exception\EmailAlreadyRegistered::class, $app[0]->context['reason'] ?? null);
    }

    public function testASecondGoogleSubjectIsLoggedAtWarningNamingTheAccountByIdOnly(): void
    {
        $account = $this->account(sub: 'SUB-OTHER-CANARY');
        $this->signIn($this->claims(self::SUB, self::ACCOUNT_EMAIL));

        $app = $this->appLogRecords();
        self::assertCount(1, $app);
        self::assertSame(Level::Warning, $app[0]->level);
        self::assertSame([
            'reason' => \App\Domain\Identity\Exception\OAuthProviderAlreadyLinked::class,
            'user_id' => $account->id()->toString(),
        ], $app[0]->context);
    }

    public function testAnUnverifiedProviderEmailIsLoggedAtInfo(): void
    {
        $this->exercise('unverified provider email (AC-9)');

        $app = $this->appLogRecords();
        self::assertCount(1, $app);
        self::assertSame(Level::Info, $app[0]->level);
    }

    public function testAProviderFailureIsLoggedExactlyOnceAtErrorNamingTheExceptionClassOnly(): void
    {
        $this->exercise('token exchange refused');

        $errors = $this->logRecordsAtOrAbove(Level::Error);
        self::assertCount(1, $errors);
        self::assertSame(['reason'], array_keys($errors[0]->context));
        self::assertSame(\League\OAuth2\Client\Provider\Exception\IdentityProviderException::class, $errors[0]->context['reason']);
    }

    // ---- AC-20: Sentry -----------------------------------------------------------------------

    #[DataProvider('providerFailures')]
    public function testAProviderFailureProducesExactlyOneErrorEventThatCarriesNoClaimsAndNoSecrets(string $scenario): void
    {
        $secrets = $this->exercise($scenario);

        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        self::assertSame('error', (string) $events[0]->getLevel());
        $context = $events[0]->getExtra()['monolog.context'] ?? null;
        self::assertIsArray($context);
        self::assertSame(['reason'], array_keys($context));

        $serialized = serialize($events[0]);
        $secrets += [
            'asserted email' => self::EMAIL,
            'subject' => self::SUB,
            'authorization code' => self::CODE,
            'access token' => FakeGoogleHttpClient::ACCESS_TOKEN,
            'provider error detail' => self::PROVIDER_DETAIL,
        ];
        foreach ($secrets as $name => $secret) {
            self::assertStringNotContainsString($secret, $serialized, "The $name leaked into the Sentry event.");
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providerFailures(): iterable
    {
        yield 'token exchange refused' => ['token exchange refused'];
        yield 'userinfo down' => ['userinfo down'];
    }

    public function testTheSentryEventForTheCallbackRouteCarriesNeitherTheCodeNorTheState(): void
    {
        $secrets = $this->exercise('token exchange refused');

        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        $serialized = serialize($events[0]);
        self::assertStringContainsString('Google sign-in failed during the token exchange', $serialized, 'This must be the callback failure event, or the scan proves nothing.');
        self::assertStringNotContainsString(self::CODE, $serialized);
        self::assertStringNotContainsString($secrets['state'], $serialized);
    }

    #[DataProvider('userCausedScenarios')]
    public function testAUserCausedFailureProducesNoSentryEvent(string $scenario): void
    {
        $this->exercise($scenario);

        self::assertSame([], $this->eventSummaries(), 'A visitor must not be able to page the operator.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function userCausedScenarios(): iterable
    {
        foreach ([
            'unverified provider email (AC-9)',
            'invalid asserted email (AC-10)',
            'second Google subject (AC-11)',
            'lost provision race',
            'bad state (AC-13)',
            'other session (AC-13)',
            'access_denied (AC-13)',
            'missing code',
        ] as $name) {
            yield $name => [$name];
        }
    }

    public function testASuccessfulSignInProducesNoSentryEvent(): void
    {
        $this->exercise('provision (AC-6)');

        self::assertSame([], $this->eventSummaries());
    }

    // ---- scenarios ---------------------------------------------------------------------------

    /**
     * @return array<string, string> the per-run secrets (state, verifier) for the scan
     */
    private function exercise(string $scenario): array
    {
        $secrets = [];
        $google = static fn (array $claims): array => $claims;

        switch ($scenario) {
            case 'recognise (AC-5)':
                $this->account(sub: self::SUB);
                $secrets = $this->signIn($this->claims(self::SUB, self::EMAIL, emailVerified: false));
                break;
            case 'provision (AC-6)':
                $secrets = $this->signIn($this->claims(self::SUB, self::EMAIL));
                break;
            case 'link to a verified account (AC-8)':
                $this->account(verified: true);
                $secrets = $this->signIn($this->claims(self::SUB, self::ACCOUNT_EMAIL));
                break;
            case 'unverified provider email (AC-9)':
                $secrets = $this->signIn($this->claims(self::SUB, self::EMAIL, emailVerified: false));
                break;
            case 'invalid asserted email (AC-10)':
                $secrets = $this->signIn($this->claims(self::SUB, 'not-an-email-'.self::SUB));
                break;
            case 'second Google subject (AC-11)':
                $this->account(sub: 'SUB-OTHER-CANARY');
                $secrets = $this->signIn($this->claims(self::SUB, self::ACCOUNT_EMAIL));
                break;
            case 'pre-hijack link (AC-12)':
                $this->registerUserWithKnownCredentials(self::ACCOUNT_EMAIL, 'the-attackers-password-1');
                $secrets = $this->signIn($this->claims(self::SUB, self::ACCOUNT_EMAIL));
                break;
            case 'lost provision race':
                // Another callback provisioned this address between our lookup and our save: the
                // lookup is made stale, so the unique index (not the handler) refuses the insert,
                // and `EmailAlreadyRegistered`'s message contains the address.
                $this->account(verified: true);
                $entityManager = self::getContainer()->get(EntityManagerInterface::class);
                self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
                self::getContainer()->set(DoctrineUserRepository::class, new StaleEmailLookupUserRepository(new DoctrineUserRepository($entityManager)));
                $secrets = $this->signIn($this->claims(self::SUB, self::ACCOUNT_EMAIL));
                break;
            case 'bad state (AC-13)':
                $secrets = $this->startAndSecrets();
                $this->fakeGoogle()->succeedWith($this->claims(self::SUB, self::EMAIL));
                $this->hitCallback($this->client, 'attacker-state-canary');
                break;
            case 'other session (AC-13)':
                $secrets = $this->startAndSecrets();
                $this->fakeGoogle()->succeedWith($this->claims(self::SUB, self::EMAIL));
                $this->client->getCookieJar()->clear();
                $this->hitCallback($this->client, $secrets['state']);
                break;
            case 'access_denied (AC-13)':
                $secrets = $this->startAndSecrets();
                $this->client->request('GET', '/connect/google/check?'.http_build_query(['error' => 'access_denied', 'state' => $secrets['state']]));
                break;
            case 'missing code':
                $secrets = $this->startAndSecrets();
                $this->hitCallback($this->client, $secrets['state'], null);
                break;
            case 'token exchange refused':
                $secrets = $this->startAndSecrets();
                $this->fakeGoogle()->failTokenExchange();
                $this->hitCallback($this->client, $secrets['state']);
                break;
            case 'userinfo down':
                $secrets = $this->startAndSecrets();
                $this->fakeGoogle()->failUserInfo();
                $this->hitCallback($this->client, $secrets['state']);
                break;
            case 'passwordless form login (AC-14)':
                $this->account(sub: self::SUB);
                $crawler = $this->client->request('GET', '/login');
                $csrf = $crawler->filter('input[name="_csrf_token"]')->attr('value');
                $this->client->request('POST', '/login', ['_username' => self::ACCOUNT_EMAIL, '_password' => 'any-password-canary-1', '_csrf_token' => $csrf]);
                $secrets = ['submitted password' => 'any-password-canary-1'];
                break;
            default:
                self::fail('Unknown scenario '.$scenario);
        }

        return $secrets;
    }

    /**
     * @return array<string, string>
     */
    private function startAndSecrets(): array
    {
        $flow = $this->startGoogleFlow($this->client);
        $verifier = $this->client->getRequest()->getSession()->get('pkce_code_verifier');
        self::assertIsString($verifier);

        return ['state' => $flow['state'], 'PKCE verifier' => $verifier];
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array<string, string>
     */
    private function signIn(array $claims): array
    {
        $secrets = $this->startAndSecrets();
        $this->fakeGoogle()->succeedWith($claims);
        $this->hitCallback($this->client, $secrets['state']);

        return $secrets;
    }

    private function account(?string $sub = null, bool $verified = false): User
    {
        $factory = UserFactory::new(['email' => Email::fromString(self::ACCOUNT_EMAIL)]);
        if (null !== $sub) {
            $factory = $factory->provisionedViaGoogle($sub);
        } elseif ($verified) {
            $factory = $factory->verified();
        }

        return $factory->create();
    }

    /**
     * The records that can actually leave the box: everything the application itself logs (the
     * `app` channel — the code this slice controls), plus anything at warning or above on any
     * channel (what the stream and Sentry handlers keep).
     *
     * DELIBERATELY NOT the framework's debug/info records. Under `prod` the `fingers_crossed`
     * handler discards them unless the same request also logs an error, and they are not ours to
     * change: `request` records carry the full `request_uri` (the callback's `code` and `state`
     * query values) and `doctrine` records carry every bound SQL parameter (the account's email).
     * That residual exposure — a buffer flush on a failed request — is reported, not asserted.
     *
     * @return list<LogRecord>
     */
    private function emittedLogRecords(): array
    {
        return array_values(array_filter(
            $this->logRecords(),
            static fn (LogRecord $r): bool => 'app' === $r->channel || $r->level->value >= Level::Warning->value,
        ));
    }

    /**
     * Every field a handler could write, as one string — Throwables by class and message chain,
     * Stringable objects the way Monolog's own formatter renders them.
     */
    private function flatten(LogRecord $record): string
    {
        $normalise = static function (mixed $value) use (&$normalise): mixed {
            if ($value instanceof \Throwable) {
                $chain = [];
                for ($e = $value; null !== $e; $e = $e->getPrevious()) {
                    $chain[] = $e::class.': '.$e->getMessage();
                }

                return $chain;
            }
            if ($value instanceof \Stringable) {
                return (string) $value;
            }
            if (\is_array($value)) {
                return array_map($normalise, $value);
            }
            if (\is_object($value)) {
                return $value::class;
            }

            return $value;
        };

        return json_encode([
            'message' => $record->message,
            'context' => $normalise($record->context),
            'extra' => $normalise($record->extra),
        ], \JSON_THROW_ON_ERROR | \JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function eventSummaries(): array
    {
        return array_map(
            static fn (Event $e): string => (string) $e->getLevel().': '.$e->getMessage(),
            $this->sentryEvents(),
        );
    }
}
