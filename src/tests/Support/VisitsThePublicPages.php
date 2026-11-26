<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * public-styles AC-1's page list: `/browse`, `/login`, `/register`, a published listing, and `/sell`
 * signed in. For a `WebTestCase` that also uses `ClearsRateLimiters`,
 * `RegistersAUserWithKnownCredentials` and `SignsInThroughTheLoginForm`.
 *
 * A generator, so the caller asserts on each page while the browser still holds its response. The
 * anonymous pages come first: a signed-in visitor is bounced off `/login` and `/register`.
 */
trait VisitsThePublicPages
{
    /**
     * @return \Generator<string, Crawler> path => the page
     */
    private function eachPublicPage(KernelBrowser $client, string $password): \Generator
    {
        $seller = $this->registerUserWithKnownCredentials('seller@example.com', $password, verified: true);
        $leaf = CategoryFactory::createOne();
        $listing = ListingFactory::new()->inCategory($leaf)->soldBy($seller)->published()->create();

        foreach (['/browse', '/login', '/register', '/listings/'.$listing->id()->toString()] as $path) {
            $page = $client->request('GET', $path);
            self::assertResponseStatusCodeSame(200, $path);

            yield $path => $page;
        }

        $this->signInThroughTheLoginForm($client, 'seller@example.com', $password);
        $page = $client->request('GET', '/sell');
        self::assertResponseStatusCodeSame(200, '/sell');

        yield '/sell' => $page;
    }
}
