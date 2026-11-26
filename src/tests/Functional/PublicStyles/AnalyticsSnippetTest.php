<?php

declare(strict_types=1);

namespace App\Tests\Functional\PublicStyles;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\Port\VerificationTokenGenerator;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\EmailVerificationRequestFactory;
use App\Tests\Factory\PasswordResetRequestFactory;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use App\Tests\Support\VisitsThePublicPages;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * public-styles AC-8 and AC-9: the Umami snippet is rendered when, and only when, both vars are set,
 * and never on an admin page or a token URL.
 *
 * The vars reach the container through `$_ENV`/`$_SERVER`, set before `createClient()` boots the
 * kernel; `configured()` first proves they arrive (a control case, so a silent miss cannot make the
 * "unconfigured" cases pass for the wrong reason).
 */
final class AnalyticsSnippetTest extends WebTestCase
{
    use ClearsRateLimiters;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;
    use VisitsThePublicPages;

    private const string PASSWORD = 'a-correct-password-1';
    private const string URL = 'https://umami.example.test/script.js';
    private const string ID = '7b6c2f0e-0000-4000-8000-000000000001';
    private const string SNIPPET = 'head script[data-website-id]';

    /** @var array<string, array{0: bool, 1: mixed, 2: bool, 3: mixed}> what the two vars were before the test */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['UMAMI_SCRIPT_URL', 'UMAMI_WEBSITE_ID'] as $name) {
            $this->saved[$name] = [\array_key_exists($name, $_ENV), $_ENV[$name] ?? null, \array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$inEnv, $env, $inServer, $server]) {
            $inEnv ? $_ENV[$name] = $env : $_ENV = array_diff_key($_ENV, [$name => 1]);
            $inServer ? $_SERVER[$name] = $server : $_SERVER = array_diff_key($_SERVER, [$name => 1]);
        }
        parent::tearDown();
    }

    private function boot(?string $url, ?string $id): KernelBrowser
    {
        foreach (['UMAMI_SCRIPT_URL' => $url, 'UMAMI_WEBSITE_ID' => $id] as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value ?? '';
        }
        $client = static::createClient();
        $client->disableReboot();
        $this->clearRateLimiterPool();

        return $client;
    }

    private function configured(): KernelBrowser
    {
        return $this->boot(self::URL, self::ID);
    }

    // ---------------------------------------------------------------- AC-8

    public function testNoPublicPageRendersTheSnippetWhenUnconfigured(): void
    {
        $seen = 0;
        foreach ($this->eachPublicPage($this->boot('', ''), self::PASSWORD) as $path => $page) {
            self::assertCount(0, $page->filter('script[data-website-id]'), $path);
            ++$seen;
        }
        self::assertSame(5, $seen);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function halfConfigured(): iterable
    {
        yield 'only the url' => [self::URL, ''];
        yield 'only the website id' => ['', self::ID];
    }

    #[DataProvider('halfConfigured')]
    public function testNoPublicPageRendersTheSnippetWhenOnlyOneVarIsSet(string $url, string $id): void
    {
        $seen = 0;
        foreach ($this->eachPublicPage($this->boot($url, $id), self::PASSWORD) as $path => $page) {
            self::assertCount(0, $page->filter('script[data-website-id]'), $path);
            ++$seen;
        }
        self::assertSame(5, $seen);
    }

    // ---------------------------------------------------------------- AC-9

    public function testEveryPublicPageRendersExactlyOneSnippetInTheHeadWhenConfigured(): void
    {
        $seen = 0;
        foreach ($this->eachPublicPage($this->configured(), self::PASSWORD) as $path => $page) {
            self::assertCount(1, $page->filter('script[data-website-id]'), $path.': one snippet in the whole page');
            $script = $page->filter(self::SNIPPET);
            self::assertCount(1, $script, $path.': and it is in <head>');
            self::assertSame(self::URL, $script->attr('src'), $path);
            self::assertSame(self::ID, $script->attr('data-website-id'), $path);
            self::assertSame('true', $script->attr('data-exclude-search'), $path);
            self::assertTrue($script->getNode(0)?->attributes?->getNamedItem('defer') instanceof \DOMAttr, $path.': defer');
            ++$seen;
        }
        self::assertSame(5, $seen);
    }

    public function testAdminPagesRenderNoSnippetWhenConfigured(): void
    {
        $client = $this->configured();
        // Control: the same configuration does put the snippet on a public page.
        self::assertCount(1, $client->request('GET', '/browse')->filter(self::SNIPPET));

        $this->registerUserWithKnownCredentials('admin@example.com', self::PASSWORD, verified: true, admin: true);
        $category = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($category)->create();
        $this->signInThroughTheLoginForm($client, 'admin@example.com', self::PASSWORD);

        foreach (['/admin/categories', '/admin/categories/'.$category->id()->toString().'/attributes'] as $path) {
            $page = $client->request('GET', $path);
            self::assertResponseStatusCodeSame(200, $path);
            self::assertCount(0, $page->filter('script[data-website-id]'), $path);
        }
    }

    public function testTheComingSoonPageRendersExactlyOneSnippetWhenConfigured(): void
    {
        $client = $this->configured();
        $page = $client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::URL, $page->filter(self::SNIPPET)->attr('src'));
        self::assertCount(1, $page->filter('script[data-website-id]'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function tokenKinds(): iterable
    {
        yield 'valid' => ['valid'];
        yield 'unknown' => ['unknown'];
        yield 'malformed' => ['not-a-token!'];
    }

    #[DataProvider('tokenKinds')]
    public function testAVerifyEmailTokenUrlRendersNoSnippet(string $kind): void
    {
        $client = $this->configured();
        $token = $kind;
        if ('valid' === $kind) {
            $user = $this->registerUserWithKnownCredentials('verify@example.com', self::PASSWORD);
            $token = $this->verificationTokenFor($user);
        } elseif ('unknown' === $kind) {
            $token = $this->unknownVerificationToken();
        }

        $client->request('GET', '/verify-email/'.$token);

        self::assertTrue($client->getResponse()->isRedirection(), 'Today the route only redirects.');
        self::assertStringNotContainsString('data-website-id', (string) $client->getResponse()->getContent());
    }

    #[DataProvider('tokenKinds')]
    public function testAResetPasswordTokenUrlRendersNoSnippet(string $kind): void
    {
        $client = $this->configured();
        $generator = self::getContainer()->get(ResetTokenGenerator::class);
        self::assertInstanceOf(ResetTokenGenerator::class, $generator);
        $token = $kind;
        if ('valid' === $kind || 'unknown' === $kind) {
            $plain = $generator->generate();
            if ('valid' === $kind) {
                $user = $this->registerUserWithKnownCredentials('reset@example.com', self::PASSWORD, verified: true);
                PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $generator->hash($plain)])->create();
            }
            $token = $plain->reveal();
        }

        $client->request('GET', '/reset-password/'.$token);

        self::assertTrue($client->getResponse()->isRedirection(), 'Today the route only redirects.');
        self::assertStringNotContainsString('data-website-id', (string) $client->getResponse()->getContent());
    }

    private function verificationTokenFor(User $user): string
    {
        $generator = self::getContainer()->get(VerificationTokenGenerator::class);
        self::assertInstanceOf(VerificationTokenGenerator::class, $generator);
        $token = $generator->generate();
        EmailVerificationRequestFactory::createOne(['userId' => $user->id(), 'tokenHash' => $generator->hash($token)]);

        return $token->reveal();
    }

    private function unknownVerificationToken(): string
    {
        $generator = self::getContainer()->get(VerificationTokenGenerator::class);
        self::assertInstanceOf(VerificationTokenGenerator::class, $generator);

        return $generator->generate()->reveal();
    }
}
