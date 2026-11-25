<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Tests\Support\ClearsRateLimiters;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * search-benchmark T11z (ADR-0026 Q7): `/browse` is rate-limited per client IP, and the limiter
 * answers before any search runs. The bucket is exhausted through the limiter itself — the test env's
 * limit is raised so the rest of the suite (one shared IP) never meets it.
 */
final class BrowseRateLimitTest extends WebTestCase
{
    use ClearsRateLimiters;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiterPool();
    }

    protected function tearDown(): void
    {
        $this->clearRateLimiterPool();
        parent::tearDown();
    }

    private function exhaust(string $ip): void
    {
        $factory = self::getContainer()->get('limiter.browse');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $limiter = $factory->create($ip);
        $limiter->consume($limiter->consume(0)->getRemainingTokens());
        self::assertSame(0, $limiter->consume(0)->getRemainingTokens(), 'the bucket is empty');
    }

    public function testAnExhaustedClientGetsA429WithRetryAfter(): void
    {
        $this->client->request('GET', '/browse');
        self::assertResponseIsSuccessful();

        $this->exhaust('127.0.0.1');
        $this->client->request('GET', '/browse');

        self::assertResponseStatusCodeSame(429);
        $retryAfter = (int) $this->client->getResponse()->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(120, $retryAfter, 'a sliding window frees a slot within two intervals');
    }

    public function testTheLimiterAnswersBeforeTheSearch(): void
    {
        // Accepted, a malformed category is the handler's 404; refused, it never gets that far.
        $this->client->request('GET', '/browse?category=not-a-uuid');
        self::assertResponseStatusCodeSame(404);

        $this->exhaust('127.0.0.1');
        $this->client->request('GET', '/browse?category=not-a-uuid');
        self::assertResponseStatusCodeSame(429);
    }

    public function testTheLimitIsPerClientIp(): void
    {
        $this->exhaust('127.0.0.1');

        $this->client->request('GET', '/browse', server: ['REMOTE_ADDR' => '192.0.2.7']);
        self::assertResponseIsSuccessful();
    }
}
