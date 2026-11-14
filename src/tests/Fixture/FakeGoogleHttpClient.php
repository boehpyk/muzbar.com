<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Google, as seen from the wire: the HTTP client the REAL `GoogleProvider` is built with under
 * `APP_ENV=test` (see `when@test` in `knpu_oauth2_client.yaml`).
 *
 * DELIBERATELY NOT A FAKE PROVIDER. `GoogleProvider` is `final`, and a subclass overriding
 * `getAccessToken()`/`getResourceOwner()` would also skip the code under test in the bundle and in
 * league: the scope override, the token and userinfo request construction, `checkResponse()`'s
 * error mapping, and the `code_verifier` going out in the POST body. Scripting the responses
 * instead keeps all of that live, and lets a test read the requests that WOULD have gone to
 * Google — which is how PKCE is asserted (the verifier is in the token request body) and how
 * "never contacts Google" (AC-24) is guaranteed: the MockHandler has no network fallback and
 * throws when its queue is empty.
 *
 * Instance state, one per kernel boot; read it from the same booted kernel (`disableReboot()`).
 */
final class FakeGoogleHttpClient
{
    public const string ACCESS_TOKEN = 'ya29.TEST-ACCESS-TOKEN-do-not-leak';

    private MockHandler $queue;

    /** @var \ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> */
    private \ArrayObject $history;

    private Client $client;

    public function __construct()
    {
        $this->history = new \ArrayObject();
        $this->queue = new MockHandler();
        $stack = HandlerStack::create($this->queue);
        // Guzzle's by-reference `$container` is typed `array|ArrayAccess`, so PHPStan widens the
        // property after the call; an ArrayObject is only ever mutated in place.
        // @phpstan-ignore assign.propertyType
        $stack->push(Middleware::history($this->history));

        // `Client` is `@final` upstream, so this wraps one rather than extending it.
        $this->client = new Client(['handler' => $stack]);
    }

    /**
     * What the provider is built with, and what the unit test drives directly.
     */
    public function client(): Client
    {
        return $this->client;
    }

    /**
     * Script a successful exchange: the token endpoint, then userinfo returning `$claims` verbatim
     * (so `'email_verified' => 'true'` really is a JSON string on the wire).
     *
     * @param array<string, mixed> $claims
     */
    public function succeedWith(array $claims, string $accessToken = self::ACCESS_TOKEN): void
    {
        $this->queueJson(200, ['access_token' => $accessToken, 'token_type' => 'Bearer', 'expires_in' => 3599]);
        $this->queueJson(200, $claims);
    }

    /**
     * Google refusing the code (bad secret, reused code, wrong verifier): an OAuth error body.
     */
    public function failTokenExchange(int $status = 400): void
    {
        $this->queueJson($status, ['error' => 'invalid_grant', 'error_description' => 'Bad Request SECRET-DETAIL']);
    }

    /**
     * The token endpoint works and the userinfo endpoint answers with a server error.
     */
    public function failUserInfo(): void
    {
        $this->queueJson(200, ['access_token' => self::ACCESS_TOKEN, 'token_type' => 'Bearer', 'expires_in' => 3599]);
        $this->queueJson(500, ['error' => ['message' => 'backend error']]);
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return array_values(array_map(static fn (array $entry): RequestInterface => $entry['request'], $this->history->getArrayCopy()));
    }

    /**
     * The form fields of the one token-endpoint POST, or `null` if none was made.
     *
     * @return array<int|string, mixed>|null
     */
    public function tokenRequestFields(): ?array
    {
        foreach ($this->requests() as $request) {
            if ('POST' === $request->getMethod() && str_contains((string) $request->getUri(), '/token')) {
                parse_str((string) $request->getBody(), $fields);

                return $fields;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function queueJson(int $status, array $body): void
    {
        $this->queue->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR)));
    }
}
