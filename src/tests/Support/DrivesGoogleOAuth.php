<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tests\Fixture\FakeGoogleHttpClient;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Drives "Continue with Google" through the REAL stack — router, firewall, `GoogleAuthenticator`,
 * knpu's PKCE client, league's `GoogleProvider` — with only Google's two HTTP endpoints scripted
 * (`FakeGoogleHttpClient`).
 *
 * The client MUST have had `disableReboot()` called: the scripted Google and the log handler are
 * instances inside one booted container, and a reboot would hand the next request a fresh, empty
 * one. For use in a `WebTestCase`.
 */
trait DrivesGoogleOAuth
{
    public const string CODE = 'AUTH-CODE-4/0AbCdEf-do-not-leak';

    /**
     * `GET /connect/google`, returning what Google would have been sent.
     *
     * @return array{location: string, state: string, challenge: string, params: array<string, string>}
     */
    private function startGoogleFlow(KernelBrowser $client): array
    {
        $client->request('GET', '/connect/google');
        self::assertResponseStatusCodeSame(302);

        $location = $client->getResponse()->headers->get('Location');
        self::assertIsString($location);
        $query = parse_url($location, \PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $raw);
        $params = [];
        foreach ($raw as $key => $value) {
            self::assertIsString($value);
            $params[(string) $key] = $value;
        }
        $state = $params['state'] ?? null;
        $challenge = $params['code_challenge'] ?? null;
        self::assertIsString($state);
        self::assertIsString($challenge);

        return ['location' => $location, 'state' => $state, 'challenge' => $challenge, 'params' => $params];
    }

    /**
     * Start the flow, script Google to answer with `$claims`, and hit the callback with the
     * state from the start response — the whole happy-path round trip in one client.
     *
     * @param array<string, mixed> $claims
     *
     * @return array{location: string, state: string, challenge: string, params: array<string, string>}
     */
    private function signInWithGoogle(KernelBrowser $client, array $claims): array
    {
        $flow = $this->startGoogleFlow($client);
        $this->fakeGoogle()->succeedWith($claims);
        $this->hitCallback($client, $flow['state']);

        return $flow;
    }

    private function hitCallback(KernelBrowser $client, ?string $state, ?string $code = self::CODE): void
    {
        $query = array_filter(['state' => $state, 'code' => $code], static fn (?string $v): bool => null !== $v);

        $client->request('GET', '/connect/google/check?'.http_build_query($query));
    }

    /**
     * @return array{sub: string, email: string, email_verified: bool}
     */
    private function claims(string $sub, string $email, bool $emailVerified = true): array
    {
        return ['sub' => $sub, 'email' => $email, 'email_verified' => $emailVerified];
    }

    private function fakeGoogle(): FakeGoogleHttpClient
    {
        // The service exists only under `when@test`; PHPStan's container is the dev one.
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $google = self::getContainer()->get(FakeGoogleHttpClient::class);
        self::assertInstanceOf(FakeGoogleHttpClient::class, $google);

        return $google;
    }

    /**
     * Every record logged on any channel since the kernel booted.
     *
     * @return list<LogRecord>
     */
    private function logRecords(): array
    {
        // Registered by config/packages/test/ only; PHPStan's container is the dev one.
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $handler = self::getContainer()->get(TestHandler::class);
        self::assertInstanceOf(TestHandler::class, $handler);

        return array_values($handler->getRecords());
    }

    /**
     * @return list<LogRecord>
     */
    private function logRecordsAtOrAbove(Level $min): array
    {
        return array_values(array_filter(
            $this->logRecords(),
            static fn (LogRecord $r): bool => $r->level->value >= $min->value,
        ));
    }

    /**
     * Records written by the application (the `app` channel) — i.e. by our own code, as opposed to
     * the framework's `security`, `request`, `doctrine` channels.
     *
     * @return list<LogRecord>
     */
    private function appLogRecords(): array
    {
        return array_values(array_filter($this->logRecords(), static fn (LogRecord $r): bool => 'app' === $r->channel));
    }
}
