<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Infrastructure\Identity\Security\GoogleAuthenticator;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\DrivesGoogleOAuth;
use App\Tests\Support\StashesTargetPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The intended action across the Google round trip (identity-login-overlay AC-14 Google half,
 * AC-16, AC-17, AC-18). A sibling of `GoogleSignInFlowTest`, which stays unmodified. One client per
 * scenario, `disableReboot()` so the scripted Google is the instance the requests used.
 */
final class GoogleIntendedActionTest extends WebTestCase
{
    use ClearsRateLimiters;
    use DrivesGoogleOAuth;
    use StashesTargetPath;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
    }

    /**
     * Both of `HttpUtils`' jobs: an absolute foreign URL has its host reset, and a relative value a
     * browser would read as scheme-relative (`/\t/evil.example` → `//evil.example`) is absolutised
     * onto our host. A hand-written host check instead of `HttpUtils` passes the first and fails the
     * second; a raw `RedirectResponse` fails both.
     */
    #[DataProvider('foreignTargets')]
    public function testAForeignTargetInTheSessionNeverLeavesTheOriginOnGoogleSignIn(string $target): void
    {
        UserFactory::new(['email' => Email::fromString('known@example.com')])->provisionedViaGoogle('sub-known')->create();
        $this->stashTargetPathDirectly($this->client, $target);

        $this->signInWithGoogle($this->client, $this->claims('sub-known', 'known@example.com'));

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        // A prefix, not `parse_url`: a browser resolves the header, and only an absolute URL already
        // anchored on our origin is safe whatever the browser does with what follows.
        self::assertStringStartsWith('http://localhost/', $location);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignTargets(): iterable
    {
        yield 'absolute foreign URL' => ['https://evil.example/x'];
        yield 'TAB-smuggled scheme-relative' => ["/\t/evil.example"];
        yield 'scheme-relative' => ['//evil.example'];
    }

    public function testAKnownGoogleUserLandsOnTheIntendedAction(): void
    {
        UserFactory::new(['email' => Email::fromString('known@example.com')])->provisionedViaGoogle('sub-known')->create();
        $this->client->request('GET', '/login?_target_path=/profile');

        $this->signInWithGoogle($this->client, $this->claims('sub-known', 'known@example.com'));

        self::assertResponseStatusCodeSame(302);
        self::assertSame('http://localhost/profile', $this->client->getResponse()->headers->get('Location'));
    }

    public function testWithNoIntendedActionGoogleSignInStillLandsOnAccount(): void
    {
        UserFactory::new(['email' => Email::fromString('known@example.com')])->provisionedViaGoogle('sub-known')->create();

        $this->signInWithGoogle($this->client, $this->claims('sub-known', 'known@example.com'));

        self::assertSame('http://localhost/account', $this->client->getResponse()->headers->get('Location'));
    }

    public function testACancelledFirstAttemptKeepsTheTargetForTheRetry(): void
    {
        UserFactory::new(['email' => Email::fromString('known@example.com')])->provisionedViaGoogle('sub-known')->create();
        $this->client->request('GET', '/login?_target_path=/profile');

        $first = $this->startGoogleFlow($this->client);
        $this->client->request('GET', '/connect/google/check?'.http_build_query(['error' => 'access_denied', 'state' => $first['state']]));
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSame(GoogleAuthenticator::NEUTRAL_FAILURE, trim($crawler->filter('p.error')->text()));

        $this->signInWithGoogle($this->client, $this->claims('sub-known', 'known@example.com'));

        self::assertSame('http://localhost/profile', $this->client->getResponse()->headers->get('Location'));
    }

    public function testAGoogleSignInThatProvisionsAnAccountLandsOnTheIntendedAction(): void
    {
        $this->client->request('GET', '/login?_target_path=/profile');

        $this->signInWithGoogle($this->client, $this->claims('sub-brand-new', 'brand.new@example.com'));

        self::assertResponseStatusCodeSame(302);
        self::assertSame('http://localhost/profile', $this->client->getResponse()->headers->get('Location'));
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertNotNull($users->findByEmail(Email::fromString('brand.new@example.com')), 'The sign-in must have provisioned the account.');
    }
}
