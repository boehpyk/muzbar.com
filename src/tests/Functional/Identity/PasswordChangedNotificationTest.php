<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\ResetToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Port\Clock;
use App\Tests\Factory\PasswordResetRequestFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\RecordingLogger;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * `NotifyUserOnPasswordChanged` driven through the real reset flow (AC-1 to AC-9). Encodes the spec,
 * not observed output. Each scenario stays inside ONE `KernelBrowser` (the reset flow stashes its
 * token in the session); reads that must reflect a finished request use a freshly fetched
 * repository (identity-map footgun).
 */
final class PasswordChangedNotificationTest extends WebTestCase
{
    use ClearsRateLimiters;
    use RegistersAUserWithKnownCredentials;

    private const SUBJECT = 'Your muzbar password was changed';

    private KernelBrowser $client;
    private ResetTokenGenerator $tokens;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiterPool();

        $tokens = self::getContainer()->get(ResetTokenGenerator::class);
        self::assertInstanceOf(ResetTokenGenerator::class, $tokens);
        $this->tokens = $tokens;
    }

    /** AC-1, AC-2, AC-3, AC-4, AC-9 (template content). */
    public function testASuccessfulResetSendsOneNoticeWithTheInstantTheRecoveryUrlAndNoSecret(): void
    {
        $user = $this->registerUserWithKnownCredentials('notice-happy@example.com', 'the-old-correct-password-1');
        $newPassword = 'a-brand-new-strong-password';
        $plainToken = $this->tokens->generate();
        $this->issueRequest($user->id(), $plainToken);

        $this->submitReset($plainToken->reveal(), $newPassword);

        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $email);

        self::assertCount(1, $email->getTo());
        self::assertSame('notice-happy@example.com', $email->getTo()[0]->getAddress());
        self::assertSame(self::SUBJECT, $email->getSubject());

        $persisted = $this->freshUsers()->findByEmail(Email::fromString('notice-happy@example.com'));
        self::assertNotNull($persisted);
        $changedAt = $persisted->passwordChangedAt();
        self::assertNotNull($changedAt);
        $instant = $changedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC';

        $urls = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);
        $forgotUrl = $urls->generate('app_forgot_password', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $text = $email->getTextBody();
        $html = $email->getHtmlBody();
        self::assertIsString($text);
        self::assertIsString($html);

        foreach (['text' => $text, 'html' => $html] as $part => $body) {
            self::assertStringContainsString('notice-happy@example.com', $body, "$part: recipient address");
            self::assertStringContainsString($instant, $body, "$part: change instant in UTC");
            self::assertStringContainsString($forgotUrl, $body, "$part: absolute forgot-password URL");
            self::assertStringContainsString('If you did not make this change', $body, "$part: recovery sentence");

            foreach ([$plainToken->reveal(), '/reset-password', $newPassword, '$argon2', '$2y$'] as $secret) {
                self::assertStringNotContainsString($secret, $body, "$part must carry no secret ($secret)");
            }
        }

        foreach (['<img', '<script', 'stylesheet'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $html);
        }
    }

    /** AC-5, weak password. */
    public function testAWeakPasswordSendsNoNotice(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('notice-weak@example.com')]);
        $plainToken = $this->tokens->generate();
        $this->issueRequest($user->id(), $plainToken);

        $this->submitReset($plainToken->reveal(), 'too-short');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->noticeCount());
    }

    /**
     * AC-5, replayed link. Only the GET can be replayed: a successful reset destroys the session
     * stash, so a replayed POST is refused by the controller before the handler and could not send
     * a notice whatever the listener did. The handler-level refusal is covered by the next test.
     */
    public function testAReplayedLinkSendsNoSecondNotice(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('notice-replay@example.com')]);
        $plainToken = $this->tokens->generate();
        $this->issueRequest($user->id(), $plainToken);

        $this->submitReset($plainToken->reveal(), 'the-first-successful-password');
        self::assertResponseRedirects('/login');
        self::assertSame(1, $this->noticeCount());

        $this->client->request('GET', '/reset-password/'.$plainToken->reveal());
        self::assertResponseRedirects('/forgot-password');
        self::assertSame(0, $this->noticeCount());
    }

    /**
     * AC-5, a token that is no longer valid when the POST arrives. The link passes `check()`, the
     * form is opened with a real CSRF token, and only then is the request invalidated (as a reissue
     * would) — so the POST reaches `ResetPasswordWithTokenHandler` and the token itself is the only
     * reason for refusal. Fails if the handler changes the password, or anything else sends the
     * notice, on a dead link.
     */
    public function testATokenInvalidatedBeforeThePostSendsNoNotice(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('notice-invalid@example.com')]);
        $plainToken = $this->tokens->generate();
        $hash = $this->issueRequest($user->id(), $plainToken);

        $this->client->request('GET', '/reset-password/'.$plainToken->reveal());
        self::assertResponseRedirects('/reset-password');
        $crawler = $this->client->request('GET', '/reset-password');
        $csrf = $crawler->filter('input[name="new_password_form[_token]"]')->attr('value');
        self::assertNotNull($csrf);

        $requests = $this->freshRequests();
        $request = $requests->findByTokenHash($hash);
        self::assertNotNull($request);
        $clock = self::getContainer()->get(Clock::class);
        self::assertInstanceOf(Clock::class, $clock);
        $request->invalidate($clock->now());
        $requests->save($request);

        $this->client->request('POST', '/reset-password', [
            'new_password_form' => [
                'plainPassword' => ['first' => 'a-perfectly-strong-password', 'second' => 'a-perfectly-strong-password'],
                '_token' => $csrf,
            ],
        ]);

        self::assertResponseRedirects('/forgot-password');
        self::assertSame(0, $this->noticeCount());
        $persisted = $this->freshUsers()->findByEmail(Email::fromString('notice-invalid@example.com'));
        self::assertNotNull($persisted);
        self::assertNull($persisted->passwordChangedAt(), 'A dead link must not change the password.');
    }

    /** AC-6, not deduplicated. */
    public function testTwoResetsOfTheSameAccountSendTwoNotices(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('notice-twice@example.com')]);

        foreach (['the-first-strong-password-1', 'the-second-strong-password-2'] as $password) {
            $plainToken = $this->tokens->generate();
            $this->issueRequest($user->id(), $plainToken);

            $this->submitReset($plainToken->reveal(), $password);

            self::assertResponseRedirects('/login');
            self::assertSame(1, $this->noticeCount(), 'Exactly one notice per reset POST.');
        }
    }

    /** AC-7 and AC-8. */
    public function testAMailFailureLeavesTheResetCommittedAndLogsOneErrorWithoutTheAddress(): void
    {
        $email = 'notice-mail-down@example.com';
        $user = $this->registerUserWithKnownCredentials($email, 'the-old-correct-password-1');
        $newPassword = 'a-brand-new-strong-password';
        $plainToken = $this->tokens->generate();
        $hash = $this->issueRequest($user->id(), $plainToken);

        $this->client->disableReboot();
        self::getContainer()->set('mailer.mailer', new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('transport is down');
            }
        });
        $logger = new RecordingLogger();
        self::getContainer()->set('monolog.logger', $logger);

        $this->submitReset($plainToken->reveal(), $newPassword);

        // AC-7: same 302 as the happy path, change persisted, request redeemed.
        self::assertResponseRedirects('/login');
        $redeemed = $this->freshRequests()->findByTokenHash($hash);
        self::assertNotNull($redeemed);
        self::assertTrue($redeemed->isRedeemed());

        $crawler = $this->client->request('GET', '/login');
        $csrf = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', ['_username' => $email, '_password' => $newPassword, '_csrf_token' => $csrf]);
        self::assertResponseRedirects('/account', null, 'The new password must work after a failed notice.');

        // AC-8: exactly one error record, user_id + exception, no address anywhere.
        $errors = array_values(array_filter($logger->all(), static fn (array $r): bool => 'error' === $r['level']));
        self::assertCount(1, $errors);
        $record = $errors[0];
        self::assertSame($user->id()->toString(), $record['context']['user_id'] ?? null);
        self::assertInstanceOf(TransportException::class, $record['context']['exception'] ?? null);
        self::assertStringNotContainsString($email, $record['message']);
        foreach ($record['context'] as $value) {
            $flat = $value instanceof \Throwable ? $value->getMessage() : json_encode($value);
            self::assertStringNotContainsString($email, (string) $flat);
        }
    }

    private function issueRequest(UserId $userId, ResetToken $plainToken): HashedResetToken
    {
        $hash = $this->tokens->hash($plainToken);
        PasswordResetRequestFactory::new(['userId' => $userId, 'tokenHash' => $hash])->create();

        return $hash;
    }

    /** GET link, GET form, POST new password — all in `$this->client`. */
    private function submitReset(string $plainToken, string $newPassword): void
    {
        $this->client->request('GET', '/reset-password/'.$plainToken);
        $crawler = $this->client->request('GET', '/reset-password');
        $csrf = $crawler->filter('input[name="new_password_form[_token]"]')->attr('value');
        self::assertNotNull($csrf);

        $this->client->request('POST', '/reset-password', [
            'new_password_form' => [
                'plainPassword' => ['first' => $newPassword, 'second' => $newPassword],
                '_token' => $csrf,
            ],
        ]);
    }

    /**
     * Password-changed notices actually sent in the most recent request, by subject. Queued events
     * are skipped: the message logger records a message both when it is queued and when sent, which
     * is also why `assertEmailCount` ignores them.
     */
    private function noticeCount(): int
    {
        $count = 0;
        foreach (self::getMailerEvents() as $event) {
            $message = $event->getMessage();
            if (!$event->isQueued() && $message instanceof MimeEmail && self::SUBJECT === $message->getSubject()) {
                ++$count;
            }
        }

        return $count;
    }

    private function freshUsers(): UserRepository
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return $users;
    }

    private function freshRequests(): PasswordResetRequestRepository
    {
        $requests = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $requests);

        return $requests;
    }
}
