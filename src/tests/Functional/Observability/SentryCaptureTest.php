<?php

declare(strict_types=1);

namespace App\Tests\Functional\Observability;

use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Infrastructure\Identity\Persistence\Doctrine\DoctrineUserRepository;
use App\Tests\Factory\PasswordResetRequestFactory;
use App\Tests\Fixture\ThrowingUserRepository;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\ReadsSentryEvents;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Sentry\Event;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * What reaches Sentry from real requests (AC-1, AC-2, AC-3, AC-11). One `KernelBrowser` per
 * scenario, `disableReboot()` so the recording transport read afterwards is the one the request
 * wrote to, and doubles swapped in by CONCRETE service id.
 */
final class SentryCaptureTest extends WebTestCase
{
    use ClearsRateLimiters;
    use ReadsSentryEvents;
    use RegistersAUserWithKnownCredentials;

    public const string ADDRESS = 'sentry-capture@example.invalid';
    private const string NEW_PASSWORD = 'CANARY-new-strong-password-9';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
    }

    /** AC-1: a real `error` call site, with the user's id (not address) as context. */
    public function testAFailedPasswordChangedNoticeProducesExactlyOneErrorEventCarryingTheUserId(): void
    {
        [$userId] = $this->resetPasswordWithMailerDown();

        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        self::assertSame('error', (string) $events[0]->getLevel());
        self::assertStringStartsWith('Could not send the password-changed notice', (string) $events[0]->getMessage());

        // The Monolog handler nests the log context under `monolog.context` (sentry-symfony 5.16),
        // so AC-1's `extra.user_id` is, as shipped, `extra['monolog.context']['user_id']`.
        $context = $events[0]->getExtra()['monolog.context'] ?? null;
        self::assertIsArray($context);
        self::assertSame($userId, $context['user_id'] ?? null);

        $types = array_map(static fn ($bag): string => $bag->getType(), $events[0]->getExceptions());
        self::assertContains(TransportException::class, $types);
    }

    /** AC-11: address, reset token, session cookie value and submitted password never leave. */
    public function testNoSecretFromAFailedResetRequestReachesTheRecordedEvent(): void
    {
        [, $plainToken] = $this->resetPasswordWithMailerDown();

        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        $event = serialize($events[0]);

        $cookies = $this->client->getCookieJar()->all();
        self::assertNotEmpty($cookies, 'No cookie was set, so the session-cookie check below would prove nothing.');
        $secrets = ['address' => self::ADDRESS, 'reset token' => $plainToken, 'password' => self::NEW_PASSWORD];
        foreach ($cookies as $cookie) {
            $secrets['cookie '.$cookie->getName()] = $cookie->getValue();
        }
        foreach ($secrets as $name => $secret) {
            self::assertStringNotContainsString($secret, $event, "The $name leaked into the Sentry event.");
        }
        self::assertStringContainsString('[email]', $event, 'The SMTP-style message must be scrubbed in place, not dropped.');
    }

    /**
     * AC-2: `POST /register` has no catch for a dependency failure, so the exception reaches
     * Symfony's ErrorListener, which logs it once at `critical`. The bundle's own error listener is
     * off, so that log is the only path: exactly one event, not two.
     */
    public function testAnUncaughtExceptionInARequestProducesExactlyOneEvent(): void
    {
        self::getContainer()->set(DoctrineUserRepository::class, new ThrowingUserRepository());

        $crawler = $this->client->request('GET', '/register');
        $token = $crawler->filter('input[name="registration_form[_token]"]')->attr('value');
        self::assertNotNull($token);
        self::assertSame([], $this->sentryEvents(), 'Rendering the form must not log an error.');

        $this->client->request('POST', '/register', [
            'registration_form' => [
                'email' => 'boom@example.invalid',
                'plainPassword' => ['first' => 'a-strong-password-1', 'second' => 'a-strong-password-1'],
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(500);
        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        $types = array_map(static fn ($bag): string => $bag->getType(), $events[0]->getExceptions());
        self::assertContains(\RuntimeException::class, $types);
    }

    /** AC-3 */
    public function testAnUnknownRouteProducesNoEvent(): void
    {
        $this->client->request('GET', '/definitely-not-a-route');

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->sentryEvents());
    }

    /** AC-3 */
    public function testAWrongMethodProducesNoEvent(): void
    {
        $this->client->request('POST', '/health/live');

        self::assertResponseStatusCodeSame(405);
        self::assertSame([], $this->sentryEvents());
    }

    /**
     * The reset flow with a mailer that fails in-request, all inside this scenario's one client
     * (the flow stashes its token in the session). The address is in the exception message, the
     * way an SMTP refusal puts it.
     *
     * @return array{string, string} the user's id and the plaintext reset token
     */
    private function resetPasswordWithMailerDown(): array
    {
        $user = $this->registerUserWithKnownCredentials(self::ADDRESS, 'the-old-correct-password-1');
        $tokens = self::getContainer()->get(ResetTokenGenerator::class);
        self::assertInstanceOf(ResetTokenGenerator::class, $tokens);
        $plainToken = $tokens->generate();
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $tokens->hash($plainToken)])->create();

        self::getContainer()->set('mailer.mailer', new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException(\sprintf('Expected response code "250" but got code "550", with message "550 <%s>: Recipient address rejected".', SentryCaptureTest::ADDRESS));
            }
        });

        $this->client->request('GET', '/reset-password/'.$plainToken->reveal());
        $crawler = $this->client->request('GET', '/reset-password');
        $csrf = $crawler->filter('input[name="new_password_form[_token]"]')->attr('value');
        self::assertNotNull($csrf);
        $this->client->request('POST', '/reset-password', [
            'new_password_form' => [
                'plainPassword' => ['first' => self::NEW_PASSWORD, 'second' => self::NEW_PASSWORD],
                '_token' => $csrf,
            ],
        ]);
        self::assertResponseRedirects('/login');

        return [$user->id()->toString(), $plainToken->reveal()];
    }
}
