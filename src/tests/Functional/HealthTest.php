<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testLivenessEndpointReportsAlive(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/live');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '"status":"alive"',
            (string) $client->getResponse()->getContent(),
        );
    }
}
