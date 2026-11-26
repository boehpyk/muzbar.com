<?php

declare(strict_types=1);

namespace App\Tests\Functional\PublicStyles;

use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * public-styles AC-3: admin pages carry no public CSS, and every admin template is on the admin layout.
 */
final class AdminLayoutTest extends WebTestCase
{
    use ClearsRateLimiters;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;

    private const string PASSWORD = 'a-correct-password-1';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
    }

    public function testAdminPagesLinkNoPublicStylesheetAndNoAppStylesheet(): void
    {
        // Admin is granted before sign-in: a role change would log the session out (CLAUDE.md).
        $this->registerUserWithKnownCredentials('admin@example.com', self::PASSWORD, verified: true, admin: true);
        $category = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($category)->create();
        $this->signInThroughTheLoginForm($this->client, 'admin@example.com', self::PASSWORD);

        foreach (['/admin/categories', '/admin/categories/'.$category->id()->toString().'/attributes'] as $path) {
            $page = $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(200, $path);
            self::assertGreaterThan(0, $page->filter('body')->count(), $path);
            $hrefs = $page->filter('[href]')->each(static fn ($node): string => (string) $node->attr('href'));
            foreach ($hrefs as $href) {
                self::assertStringNotContainsString('/assets/styles/public-', $href, $path);
                self::assertStringNotContainsString('/assets/styles/app-', $href, $path);
            }
        }
    }

    public function testEveryAdminTemplateExtendsTheAdminLayout(): void
    {
        $dir = self::getContainer()->getParameter('kernel.project_dir').'/templates/catalog/admin';
        $files = glob($dir.'/*.twig');
        self::assertIsArray($files);
        self::assertGreaterThanOrEqual(6, \count($files), 'The scan must have seen the admin templates.');

        foreach ($files as $file) {
            self::assertStringStartsWith("{% extends 'admin/layout.html.twig' %}", (string) file_get_contents($file), $file);
        }
    }
}
