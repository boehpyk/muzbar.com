<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * AC-1: `/profile` exists, is protected, and renders no account data.
 */
final class ProfilePageTest extends WebTestCase
{
    use ClearsRateLimiters;
    use RegistersAUserWithKnownCredentials;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiterPool();
    }

    public function testAnAnonymousVisitorIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/profile');

        self::assertResponseRedirects('/login');
    }

    public function testASignedInUserSeesTheProfileHeading(): void
    {
        $this->signIn('profile-page@example.com');

        $crawler = $this->client->request('GET', '/profile');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Profile', trim($crawler->filter('h1')->text()));
    }

    public function testThePageRendersNoEmailAndNoRole(): void
    {
        $this->signIn('profile-privacy@example.com');

        $crawler = $this->client->request('GET', '/profile');

        $body = $crawler->filter('body')->outerHtml();
        self::assertStringNotContainsString('profile-privacy@example.com', $body);
        self::assertStringNotContainsString('@', $body);
        self::assertStringNotContainsString('ROLE_', $body);
    }

    public function testTheDefaultLandingPageAfterSignInIsStillAccount(): void
    {
        $this->signIn('profile-default@example.com');

        self::assertResponseRedirects('/account');
    }

    private function signIn(string $email): void
    {
        $this->registerUserWithKnownCredentials($email, 'a-correct-password-1', verified: true);

        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);
        $this->client->request('POST', '/login', [
            '_username' => $email,
            '_password' => 'a-correct-password-1',
            '_csrf_token' => $token,
        ]);
    }
}
