<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fixture;

use App\Tests\Fixture\FakeGoogleHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The fake is what guarantees AC-24 ("never contacts Google"), so its own contract is pinned: an
 * unscripted request fails loudly instead of falling through to the network.
 */
final class FakeGoogleHttpClientTest extends TestCase
{
    public function testARequestWithNothingScriptedFailsInsteadOfReachingTheNetwork(): void
    {
        $this->expectException(\OutOfBoundsException::class);

        (new FakeGoogleHttpClient())->client()->request('GET', 'https://accounts.google.com/never');
    }

    public function testScriptedResponsesAreServedInOrderAndRequestsAreRecorded(): void
    {
        $http = new FakeGoogleHttpClient();
        $http->succeedWith(['sub' => 'abc']);

        $http->client()->request('POST', 'https://oauth2.googleapis.com/token', ['form_params' => ['code_verifier' => 'v']]);
        $http->client()->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo');

        self::assertCount(2, $http->requests());
        self::assertSame('v', $http->tokenRequestFields()['code_verifier'] ?? null);
    }
}
