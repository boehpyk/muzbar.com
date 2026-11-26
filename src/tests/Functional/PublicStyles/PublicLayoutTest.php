<?php

declare(strict_types=1);

namespace App\Tests\Functional\PublicStyles;

use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use App\Tests\Support\VisitsThePublicPages;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * public-styles AC-1, AC-2, AC-6, AC-7a over the five pages that extend the public layout.
 */
final class PublicLayoutTest extends WebTestCase
{
    use ClearsRateLimiters;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;
    use VisitsThePublicPages;

    private const string PASSWORD = 'a-correct-password-1';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
    }

    public function testEveryPublicPageLinksExactlyOnePublicStylesheetThatServesTheCompiledTokens(): void
    {
        $seen = 0;
        foreach ($this->eachPublicPage($this->client, self::PASSWORD) as $path => $page) {
            $hrefs = $page->filter('link[rel="stylesheet"]')->each(static fn ($link): string => (string) $link->attr('href'));
            $public = array_values(array_filter($hrefs, static fn (string $href): bool => 1 === preg_match('#^/assets/styles/public-[A-Za-z0-9_-]+\.css$#', $href)));
            self::assertCount(1, $public, $path.' must link exactly one public stylesheet; links: '.implode(', ', $hrefs));
            self::assertCount(1, $hrefs, $path.' must link no other stylesheet; links: '.implode(', ', $hrefs));

            $this->client->request('GET', $public[0]);
            self::assertResponseStatusCodeSame(200, $path.': '.$public[0]);
            self::assertStringContainsString('text/css', $this->client->getResponse()->headers->get('Content-Type') ?? '');
            $css = $this->client->getInternalResponse()->getContent();
            self::assertStringContainsString('--ink', $css, $path.': tokens.css is compiled in');
            self::assertLessThanOrEqual(30 * 1024, \strlen($css), $path.': AC-6 budget, measured on the served body');
            ++$seen;
        }

        self::assertSame(5, $seen, 'AC-1 names five pages');
    }

    public function testNoPublicPageLinksTheDeletedAppStylesheet(): void
    {
        $seen = 0;
        foreach ($this->eachPublicPage($this->client, self::PASSWORD) as $path => $page) {
            $hrefs = $page->filter('[href]')->each(static fn ($node): string => (string) $node->attr('href'));
            foreach ($hrefs as $href) {
                self::assertStringNotContainsString('/assets/styles/app-', $href, $path);
            }
            // The import is what would pull it in: the page's importmap must not name it either.
            self::assertStringNotContainsString('styles/app.css', (string) $this->client->getResponse()->getContent(), $path);
            ++$seen;
        }

        self::assertSame(5, $seen);
        self::assertFileDoesNotExist(self::getContainer()->getParameter('kernel.project_dir').'/assets/styles/app.css');
    }

    public function testEveryPublicPageHasAViewportMetaAndASkipLinkThatIsTheFirstFocusableElementAndTargetsTheContent(): void
    {
        $seen = 0;
        foreach ($this->eachPublicPage($this->client, self::PASSWORD) as $path => $page) {
            self::assertCount(1, $page->filter('head meta[name="viewport"][content="width=device-width, initial-scale=1"]'), $path);

            $first = $page->filter('body a, body button, body input, body select, body textarea, body summary')->first();
            self::assertSame('#content', $first->attr('href'), $path.': the first focusable element is the skip link');
            self::assertStringContainsString('skip-link', (string) $first->attr('class'), $path);

            $content = $page->filter('#content');
            self::assertCount(1, $content, $path.': the skip link needs its target');
            self::assertGreaterThan(0, $content->children()->count(), $path.': #content wraps the page body');
            ++$seen;
        }

        self::assertSame(5, $seen);
    }
}
