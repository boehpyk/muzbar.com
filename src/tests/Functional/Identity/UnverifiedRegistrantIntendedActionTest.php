<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Support\ClearsRateLimiters;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * The intended action of a visitor who registers with a password (identity-login-overlay AC-19,
 * AC-20): the target survives registration and the email round trip in the same session, nobody is
 * signed in along the way, and the first successful sign-in honours it.
 *
 * Every probe of "am I signed in?" reads the session's security token rather than visiting a
 * protected page: an anonymous visit to `/account` makes the firewall's entry point stash `/account`
 * as the target, which would overwrite the very value these scenarios exist to follow.
 */
final class UnverifiedRegistrantIntendedActionTest extends WebTestCase
{
    use ClearsRateLimiters;
    use MailerAssertionsTrait;

    private const string EMAIL = 'registrant@example.com';
    private const string PASSWORD = 'a-strong-password-1';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiterPool();
    }

    public function testTheTargetSurvivesRegistrationAndTheVerificationRoundTripWithoutSigningAnyoneIn(): void
    {
        $this->client->request('GET', '/login?_target_path=/profile');

        $this->register();
        self::assertResponseRedirects('/verify-email/sent');
        self::assertFalse($this->isSignedIn(), 'Registration must not start a session.');
        $link = $this->verificationLinkPath();

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/login');
        self::assertFalse($this->isSignedIn(), 'Following the verification link must not start a session.');

        $this->signIn();

        self::assertResponseStatusCodeSame(302);
        self::assertSame('http://localhost/profile', $this->client->getResponse()->headers->get('Location'));
        self::assertTrue($this->isSignedIn(), 'The probe must be able to see a real session, or the assertions above prove nothing.');
    }

    public function testAnUnverifiedSignInIsRefusedAndKeepsTheTarget(): void
    {
        $this->client->request('GET', '/login?_target_path=/profile');
        $this->register();
        $link = $this->verificationLinkPath();

        $this->signIn();
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSame('Please verify your email address before signing in.', trim($crawler->filter('p.error')->text()));
        self::assertFalse($this->isSignedIn());

        $this->client->request('GET', $link);
        $this->signIn();

        self::assertSame('http://localhost/profile', $this->client->getResponse()->headers->get('Location'));
    }

    private function register(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form();
        $form['registration_form[email]'] = self::EMAIL;
        $form['registration_form[plainPassword][first]'] = self::PASSWORD;
        $form['registration_form[plainPassword][second]'] = self::PASSWORD;
        $this->client->submit($form);
    }

    private function verificationLinkPath(): string
    {
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        $body = $message->getTextBody();
        self::assertIsString($body);
        $found = preg_match('#https?://[^/\s]+(/verify-email/[A-Za-z0-9_-]{43})#', $body, $match);
        self::assertSame(1, $found);
        self::assertArrayHasKey(1, $match);

        return $match[1];
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/login', [
            '_username' => self::EMAIL,
            '_password' => self::PASSWORD,
            '_csrf_token' => $token,
        ]);
    }

    /**
     * Whether the last request left an authenticated token in the session (the `main` firewall's
     * `_security_main` attribute), without visiting anything that would touch the target path.
     */
    private function isSignedIn(): bool
    {
        return $this->client->getRequest()->getSession()->has('_security_main');
    }
}
