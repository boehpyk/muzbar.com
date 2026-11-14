<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\DrivesGoogleOAuth;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;

/**
 * The intended-action redirect (ADR-0015) through the real firewall: the one writer
 * (`GET /login?_target_path=`), the one validator, and the form-login reader.
 *
 * One `KernelBrowser` per scenario: the stashed target lives in the session, and the test session
 * persists across requests only within one client. `ClearsRateLimiters` because every scenario
 * POSTs `/login`.
 */
final class IntendedActionRedirectTest extends WebTestCase
{
    use ClearsRateLimiters;
    use DrivesGoogleOAuth;
    use RegistersAUserWithKnownCredentials;

    private const string EMAIL = 'intended@example.com';
    private const string PASSWORD = 'a-correct-password-1';
    private const string THROTTLED_MESSAGE = '/^Too many failed login attempts, please try again in 1[56] minutes\.$/';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiterPool();
        $this->registerUserWithKnownCredentials(self::EMAIL, self::PASSWORD, verified: true);
    }

    // ---- the intended-action redirect (AC-2 ... AC-8) -----------------------------------------

    public function testAProtectedTargetIsHonouredAfterSignIn(): void
    {
        $this->client->request('GET', '/login?_target_path=/profile');
        self::assertResponseStatusCodeSame(200);

        $this->signIn();

        self::assertLocationIs('http://localhost/profile', $this->client);
    }

    public function testAPublicTargetWithAQueryIsHonouredAfterSignIn(): void
    {
        $this->client->request('GET', '/login?_target_path=/?from=overlay');
        self::assertResponseStatusCodeSame(200);

        $this->signIn();

        self::assertLocationIs('http://localhost/?from=overlay', $this->client);
    }

    public function testWithNoTargetSignInLandsOnAccount(): void
    {
        $this->signIn();

        self::assertLocationIs('http://localhost/account', $this->client);
    }

    public function testAProtectedPageVisitedAnonymouslyIsTheTargetAfterSignIn(): void
    {
        $this->client->request('GET', '/profile');
        self::assertResponseRedirects('/login');

        $this->signIn();

        self::assertLocationIs('http://localhost/profile', $this->client);
    }

    public function testTheNewestIntentWins(): void
    {
        $this->client->request('GET', '/login?_target_path=/');
        $this->client->request('GET', '/login?_target_path=/profile');

        $this->signIn();

        self::assertLocationIs('http://localhost/profile', $this->client);
    }

    public function testTheTargetIsConsumedByTheFirstSuccessfulSignIn(): void
    {
        $this->client->request('GET', '/login?_target_path=/profile');
        $this->signIn();
        self::assertLocationIs('http://localhost/profile', $this->client);

        $this->signIn();

        self::assertLocationIs('http://localhost/account', $this->client);
    }

    public function testAFailedAttemptKeepsTheTarget(): void
    {
        $this->client->request('GET', '/login?_target_path=/profile');
        $this->signIn(password: 'definitely-the-wrong-password');
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSame('Invalid credentials.', trim($crawler->filter('p.error')->text()));

        $this->signIn();

        self::assertLocationIs('http://localhost/profile', $this->client);
    }

    // ---- open-redirect prevention (AC-9 ... AC-13) --------------------------------------------

    #[DataProvider('hostileValues')]
    public function testAHostileTargetIsIgnoredAtTheWriter(string $hostile): void
    {
        $this->client->request('GET', '/login?'.http_build_query(['_target_path' => $hostile]));
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorExists('[data-auth-panel]');

        $this->signIn();

        self::assertLocationIs('http://localhost/account', $this->client);
    }

    public function testAHostileValueDoesNotClobberAGoodOne(): void
    {
        $this->client->request('GET', '/login?_target_path=/profile');
        $this->client->request('GET', '/login?'.http_build_query(['_target_path' => '//evil.example']));
        self::assertResponseStatusCodeSame(200);

        $this->signIn();

        self::assertLocationIs('http://localhost/profile', $this->client);
    }

    #[DataProvider('nonStringParameters')]
    public function testANonStringParameterIsIgnored(string $query): void
    {
        $this->client->request('GET', '/login?'.$query);
        self::assertResponseStatusCodeSame(200);

        $this->signIn();

        self::assertLocationIs('http://localhost/account', $this->client);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonStringParameters(): iterable
    {
        yield 'list' => ['_target_path[]=/profile'];
        yield 'map' => ['_target_path[x]=/profile'];
    }

    public function testAPathOfExactlyTheByteLimitIsHonoured(): void
    {
        $path = self::paddedPath(2048);
        self::assertSame(2048, \strlen($path));

        $this->client->request('GET', '/login?'.http_build_query(['_target_path' => $path]));
        $this->signIn();

        self::assertLocationIs('http://localhost'.$path, $this->client);
    }

    public function testAPathOneByteOverTheLimitIsIgnored(): void
    {
        $path = self::paddedPath(2049);
        self::assertSame(2049, \strlen($path));

        $this->client->request('GET', '/login?'.http_build_query(['_target_path' => $path]));
        self::assertResponseStatusCodeSame(200);
        $this->signIn();

        self::assertLocationIs('http://localhost/account', $this->client);
    }

    /**
     * Symfony's own `_target_path` POST parameter (`form_login`'s `target_path_parameter`) is a
     * second door the overlay does not use. Asserted shut: the `Location` must name `localhost` the
     * way a browser parses it, not the way `parse_url` alone would.
     */
    #[DataProvider('hostileValues')]
    public function testSymfonysOwnPostParameterCannotLeaveTheOrigin(string $hostile): void
    {
        $this->signIn(extra: ['_target_path' => $hostile]);

        self::assertResponseStatusCodeSame(302);
        self::assertSame('localhost', self::browserHost((string) $this->client->getResponse()->headers->get('Location')));
    }

    // ---- defence in depth at the reader (AC-14, password half) --------------------------------

    public function testAForeignTargetInTheSessionStillNeverLeavesTheOriginOnPasswordSignIn(): void
    {
        $this->stashTargetPathDirectly('https://evil.example/x');

        $this->signIn();

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame('localhost', self::browserHost($location));
        self::assertStringNotContainsString('evil.example', $location);
    }

    // ---- no echo, no log (AC-15) --------------------------------------------------------------

    #[DataProvider('hostileValues')]
    public function testAHostileQueryValueIsNeitherEchoedNorLogged(string $hostile): void
    {
        $this->client->disableReboot();
        $baseline = (string) $this->client->request('GET', '/login')->outerHtml();

        $this->client->request('GET', '/login?'.http_build_query(['_target_path' => $hostile]));
        $bodies = [(string) $this->client->getResponse()->getContent()];
        $this->signIn();
        $bodies[] = (string) $this->client->getResponse()->getContent();

        $this->assertNothingEchoedOrLogged($hostile, $baseline, $bodies);
    }

    /**
     * The POST door is Symfony's: for a value it accepts as a path on our host it repeats that path
     * in `Location` and in the redirect's own body, by design, so only the log half of AC-15 can
     * hold there (AC-13 proves where the redirect points).
     */
    #[DataProvider('hostileValues')]
    public function testAHostilePostValueIsNeverLogged(string $hostile): void
    {
        $this->client->disableReboot();
        $baseline = (string) $this->client->request('GET', '/login')->outerHtml();

        $this->signIn(extra: ['_target_path' => $hostile]);

        $this->assertNothingEchoedOrLogged($hostile, $baseline, []);
    }

    // ---- preserved behaviour (AC-27 ... AC-29) ------------------------------------------------

    /**
     * With a target stashed, a wrong password for a real account and any password for an unknown
     * email still render the same page. Asserted against each other, live. The two pages
     * legitimately differ in the typed email (`lastUsername`) and in the session's CSRF token (it
     * is re-issued after each failed attempt), so those two values are normalised out; everything
     * else must match byte for byte.
     */
    public function testNeutralErrorsAreByteIdenticalWithATargetStashed(): void
    {
        $this->client->request('GET', '/login?_target_path=/profile');

        $this->signIn(password: 'a-wrong-password-1');
        $wrongStatus = $this->client->getResponse()->getStatusCode();
        $wrongLocation = $this->client->getResponse()->headers->get('Location');
        $wrongBody = (string) $this->client->followRedirect()->outerHtml();

        $this->signIn(username: 'nobody-registered-this@example.com', password: 'a-wrong-password-1');
        $unknownStatus = $this->client->getResponse()->getStatusCode();
        $unknownLocation = $this->client->getResponse()->headers->get('Location');
        $unknownBody = (string) $this->client->followRedirect()->outerHtml();

        self::assertSame($wrongStatus, $unknownStatus);
        self::assertSame($wrongLocation, $unknownLocation);
        self::assertStringContainsString('Invalid credentials.', $wrongBody);
        self::assertSame(
            self::normalised($wrongBody, self::EMAIL),
            self::normalised($unknownBody, 'nobody-registered-this@example.com'),
        );
    }

    public function testTheSixthFailedAttemptIsStillThrottledWithATargetStashed(): void
    {
        $this->client->request('GET', '/login?_target_path=/profile');

        $errors = [];
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->signIn(password: 'a-wrong-password-'.$attempt);
            self::assertResponseRedirects('/login');
            $errors[$attempt] = trim($this->client->followRedirect()->filter('p.error')->text());
        }

        self::assertSame('Invalid credentials.', $errors[5]);
        self::assertMatchesRegularExpression(self::THROTTLED_MESSAGE, $errors[6]);
    }

    #[DataProvider('badCsrfTokens')]
    public function testABadCsrfTokenDoesNotAuthenticate(?string $token): void
    {
        $this->client->request('GET', '/login?_target_path=/profile');

        $this->postWithToken($token);
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    /**
     * Kept apart from the test above on purpose: an anonymous visit to a protected page makes the
     * entry point stash *that* page as the target, so probing `/account` in between would overwrite
     * `/profile` and make this assertion meaningless.
     */
    #[DataProvider('badCsrfTokens')]
    public function testABadCsrfTokenDoesNotConsumeTheTarget(?string $token): void
    {
        $this->client->request('GET', '/login?_target_path=/profile');

        $this->postWithToken($token);
        self::assertResponseRedirects('/login');

        $this->signIn();
        self::assertLocationIs('http://localhost/profile', $this->client);
    }

    private function postWithToken(?string $token): void
    {
        $this->client->request('POST', '/login', array_filter([
            '_username' => self::EMAIL,
            '_password' => self::PASSWORD,
            '_csrf_token' => $token,
        ], static fn (?string $v): bool => null !== $v));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function badCsrfTokens(): iterable
    {
        yield 'missing' => [null];
        yield 'wrong' => ['not-the-token'];
    }

    // ---- data & helpers -----------------------------------------------------------------------

    /**
     * The feature spec's hostile list, verbatim.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileValues(): iterable
    {
        yield 'protocol-relative' => ['//evil.example'];
        yield 'triple slash' => ['///evil.example'];
        yield 'slash backslash' => ['/\\evil.example'];
        yield 'backslash slash' => ['\\/evil.example'];
        yield 'double backslash' => ['\\\\evil.example'];
        yield 'absolute https' => ['https://evil.example/'];
        yield 'absolute own origin' => ['http://localhost/profile'];
        yield 'scheme without slashes' => ['http:evil.example'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,x'];
        yield 'bare host' => ['evil.example'];
        yield 'no leading slash' => ['profile'];
        yield 'empty' => [''];
        yield 'leading space' => [' /profile'];
        yield 'literal TAB' => ["/\t/evil.example"];
        yield 'literal LF' => ["/\n/evil.example"];
        yield 'encoded slash' => ['/%2F/evil.example'];
        yield 'encoded slashes lowercase' => ['/%2f%2fevil.example'];
        yield 'encoded backslash' => ['/%5Cevil.example'];
        yield 'encoded TAB' => ['/%09/evil.example'];
        yield 'double-encoded slashes' => ['/%252F%252Fevil.example'];
        yield '2049 bytes' => [self::paddedPath(2049, '/')];
    }

    private static function paddedPath(int $bytes, string $prefix = '/profile?pad='): string
    {
        return $prefix.str_repeat('a', $bytes - \strlen($prefix));
    }

    /**
     * @param array<string, string> $extra additional form fields
     */
    private function signIn(?string $username = null, string $password = self::PASSWORD, array $extra = []): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/login', [
            '_username' => $username ?? self::EMAIL,
            '_password' => $password,
            '_csrf_token' => $token,
        ] + $extra);
    }

    private static function normalised(string $html, string $email): string
    {
        $html = str_replace($email, '{email}', $html);

        return (string) preg_replace('/(name="_csrf_token" value=")[^"]*"/', '$1{token}"', $html);
    }

    private static function assertLocationIs(string $expected, KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(302);
        self::assertSame($expected, $client->getResponse()->headers->get('Location'));
    }

    /**
     * The host a browser would navigate to: the WHATWG URL parser drops TAB/CR/LF and reads `\` as
     * `/` in an http(s) URL before looking for the authority.
     */
    private static function browserHost(string $location): ?string
    {
        $host = parse_url(str_replace(["\t", "\r", "\n", '\\'], ['', '', '', '/'], $location), \PHP_URL_HOST);

        return \is_string($host) ? $host : null;
    }

    /**
     * Simulates a careless future writer: puts a value straight into the firewall's target path,
     * bypassing `IntendedActionPath`. The one place a test touches the attribute.
     */
    private function stashTargetPathDirectly(string $value): void
    {
        $factory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $session = $factory->createSession();
        $session->set('_security.main.target_path', $value);
        $session->save();
        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    /**
     * @param list<string> $bodies
     */
    private function assertNothingEchoedOrLogged(string $hostile, string $baseline, array $bodies): void
    {
        // A hostile value that is also ordinary page text (`profile`, `/profile`) cannot be told
        // apart from the page, so only values absent from a clean page are searched for verbatim.
        $needles = ['evil.example'];
        if ('' !== $hostile && !str_contains($baseline, $hostile) && !str_contains($baseline, htmlspecialchars($hostile))) {
            $needles[] = $hostile;
        }

        foreach ($bodies as $body) {
            foreach ($needles as $needle) {
                self::assertStringNotContainsString($needle, $body);
            }
        }

        // Our own code's records, and anything that would reach an operator (warning and above, on
        // any channel). Not the framework's debug lines: the `request` channel logs every request
        // URI by design (CLAUDE.md, the Google footgun), which is not this slice's code.
        foreach ($this->logRecords() as $record) {
            if ('app' !== $record->channel && $record->level->value < Level::Warning->value) {
                continue;
            }
            $text = $record->message.json_encode($record->context, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES);
            foreach ($needles as $needle) {
                self::assertStringNotContainsString($needle, $text, \sprintf('Log record "%s" on channel %s echoes the hostile value.', $record->message, $record->channel));
            }
        }
    }
}
