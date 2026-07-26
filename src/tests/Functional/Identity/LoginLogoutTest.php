<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Support\ClearsLoginRateLimiter;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for `/login`, `/logout` and the `/account` gate.
 *
 * `ClearsLoginRateLimiter` is used because the throttle counters live in the real Redis
 * `cache.rate_limiter` pool in every environment, test included — Redis is not rolled back by
 * DAMA the way Postgres is, so a stale counter from an earlier test (or an earlier suite run)
 * would otherwise make an unrelated test's login attempt fail with the throttling message instead
 * of the message it is actually testing for. Each test also uses its own unique email as a second,
 * independent line of defence (belt and braces, not a substitute for clearing the pool).
 */
final class LoginLogoutTest extends WebTestCase
{
    use ClearsLoginRateLimiter;
    use RegistersAUserWithKnownCredentials;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearLoginRateLimiterPool();
    }

    /**
     * AC-11: correct credentials redirect to /account, which then shows that user's own email.
     * Also reinforces AC-24: this user is deliberately left unverified, and this slice installs no
     * `UserCheckerInterface`, so login must still succeed.
     */
    public function testSuccessfulLoginRedirectsToAccountAndShowsOwnEmail(): void
    {
        $this->registerUserWithKnownCredentials('login-success@example.com', 'a-correct-password-1');

        $this->login('login-success@example.com', 'a-correct-password-1');

        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-email', 'login-success@example.com');
    }

    /**
     * AC-12: a wrong password re-renders /login with exactly "Invalid credentials." and
     * establishes no session — proxied here by a subsequent /account bouncing to /login.
     */
    public function testWrongPasswordShowsInvalidCredentialsAndEstablishesNoSession(): void
    {
        $this->registerUserWithKnownCredentials('wrong-password@example.com', 'the-correct-password-1');

        $this->login('wrong-password@example.com', 'totally-the-wrong-password');

        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSame('Invalid credentials.', trim($crawler->filter('p.error')->text()));

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    /**
     * AC-13: an unknown email must be indistinguishable from a wrong password for a real account —
     * same status, same redirect target, and (the assertion that actually bites if this regresses)
     * a byte-identical rendered error string, not merely two non-empty ones.
     */
    public function testUnknownEmailProducesAByteIdenticalErrorToWrongPassword(): void
    {
        $this->registerUserWithKnownCredentials('real-account@example.com', 'the-correct-password-1');

        $this->login('real-account@example.com', 'totally-the-wrong-password');
        $wrongPasswordResponse = $this->client->getResponse();
        $wrongPasswordStatus = $wrongPasswordResponse->getStatusCode();
        $wrongPasswordLocation = $wrongPasswordResponse->headers->get('Location');
        $wrongPasswordError = trim($this->client->followRedirect()->filter('p.error')->text());

        $this->login('nobody-ever-registered-this@example.com', 'whatever-password-1');
        $unknownEmailResponse = $this->client->getResponse();
        $unknownEmailStatus = $unknownEmailResponse->getStatusCode();
        $unknownEmailLocation = $unknownEmailResponse->headers->get('Location');
        $unknownEmailError = trim($this->client->followRedirect()->filter('p.error')->text());

        self::assertSame($wrongPasswordStatus, $unknownEmailStatus);
        self::assertSame($wrongPasswordLocation, $unknownEmailLocation);
        self::assertSame($wrongPasswordError, $unknownEmailError);
        self::assertSame('Invalid credentials.', $unknownEmailError);
    }

    /**
     * AC-17: the session id changes across a successful login (session-fixation protection).
     */
    public function testSessionIdRotatesAcrossASuccessfulLogin(): void
    {
        $this->registerUserWithKnownCredentials('rotation-check@example.com', 'a-correct-password-1');

        // Establish a pre-login session by visiting a page that reads it (CSRF does).
        $this->client->request('GET', '/login');
        $sessionCookieBefore = $this->client->getCookieJar()->get('MOCKSESSID') ?? $this->client->getCookieJar()->get('PHPSESSID');
        self::assertNotNull($sessionCookieBefore, 'Expected a session cookie to be set after GET /login.');
        $idBefore = $sessionCookieBefore->getValue();

        $this->login('rotation-check@example.com', 'a-correct-password-1');

        $sessionCookieAfter = $this->client->getCookieJar()->get('MOCKSESSID') ?? $this->client->getCookieJar()->get('PHPSESSID');
        self::assertNotNull($sessionCookieAfter, 'Expected a session cookie to still be set after login.');
        self::assertNotSame($idBefore, $sessionCookieAfter->getValue());
    }

    /**
     * AC-16: logging out invalidates the session; a subsequent /account is a 302 to /login.
     *
     * Driven through the real "Sign out" form on /account rather than a bare request to /logout,
     * because logout is CSRF-protected: the token only exists on the rendered page, so this is the
     * only path a browser can actually take.
     */
    public function testLogoutInvalidatesTheSessionAndAccountThenRedirects(): void
    {
        $this->registerUserWithKnownCredentials('logout-check@example.com', 'a-correct-password-1');
        $this->login('logout-check@example.com', 'a-correct-password-1');
        self::assertResponseRedirects('/account');

        $this->logoutViaTheSignOutForm();
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    /**
     * `enable_csrf: true` on the `logout` firewall key (security.yaml) must actually be enforced,
     * not merely configured. A request to /logout carrying no token must both be rejected AND have
     * no side effect: the assertion that actually matters is that a subsequent GET /account still
     * returns 200 and still renders this user's own email, which would fail to hold if the firewall
     * had already cleared the session before rejecting the request for want of a token. Asserting
     * only the rejection status would pass under that bug too, so both are asserted.
     */
    public function testUntokenisedLogoutIsRejectedAndLeavesTheSessionIntact(): void
    {
        $this->registerUserWithKnownCredentials('logout-no-token@example.com', 'a-correct-password-1');
        $this->login('logout-no-token@example.com', 'a-correct-password-1');
        self::assertResponseRedirects('/account');
        $this->client->followRedirect();

        $this->client->request('POST', '/logout');

        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-email', 'logout-no-token@example.com');
    }

    /**
     * A token must be checked against the *logout* token id specifically, not merely be "some
     * token the session recognises". A valid, freshly-issued *login* CSRF token replayed against
     * /logout must be rejected just as an absent one is — otherwise a regression that checks only
     * for token presence (any non-empty `_csrf_token`) would pass this suite.
     */
    public function testLogoutRejectsAValidCsrfTokenIssuedForADifferentTokenId(): void
    {
        $this->registerUserWithKnownCredentials('logout-wrong-token@example.com', 'a-correct-password-1');
        $this->login('logout-wrong-token@example.com', 'a-correct-password-1');
        self::assertResponseRedirects('/account');
        $this->client->followRedirect();

        $loginCrawler = $this->client->request('GET', '/login');
        $loginToken = $loginCrawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($loginToken);

        $this->client->request('POST', '/logout', ['_csrf_token' => $loginToken]);

        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-email', 'logout-wrong-token@example.com');
    }

    /**
     * AC-19: anonymous /account is a 302 to /login — never 200, never a partial render.
     */
    public function testAnonymousAccountRedirectsToLogin(): void
    {
        $this->client->request('GET', '/account');

        self::assertResponseRedirects('/login');
    }

    /**
     * AC-21 / AC-22: no password hash and no `ROLE_*` string appears anywhere in the rendered
     * HTML of /register, /login or the authenticated /account page.
     */
    public function testNoPasswordHashOrRoleStringAppearsInAnyRenderedPage(): void
    {
        $user = $this->registerUserWithKnownCredentials('privacy-check@example.com', 'a-correct-password-1');
        $storedHash = $user->passwordHash()->toString();

        $registerHtml = (string) $this->client->request('GET', '/register')->outerHtml();
        self::assertStringNotContainsString($storedHash, $registerHtml);
        self::assertStringNotContainsString('ROLE_', $registerHtml);

        $loginHtml = (string) $this->client->request('GET', '/login')->outerHtml();
        self::assertStringNotContainsString($storedHash, $loginHtml);
        self::assertStringNotContainsString('ROLE_', $loginHtml);

        $this->login('privacy-check@example.com', 'a-correct-password-1');
        $accountCrawler = $this->client->followRedirect();
        $accountHtml = (string) $accountCrawler->outerHtml();
        self::assertStringNotContainsString($storedHash, $accountHtml);
        self::assertStringNotContainsString('ROLE_', $accountHtml);

        // AC-21: /account renders exactly one address, and it is the visitor's own. Scoped to
        // <body> because AssetMapper's importmap polyfill script in <head> legitimately contains
        // an unrelated "@" (a package version pin, `es-module-shims@1.10.0`) that would otherwise
        // produce a false positive here.
        $bodyHtml = $accountCrawler->filter('body')->outerHtml();
        self::assertSame(1, substr_count($bodyHtml, '@'));
        self::assertSelectorTextContains('.account-email', 'privacy-check@example.com');
    }

    private function login(string $username, string $password): void
    {
        // The CSRF token manager needs an active request/session context ("There is currently no
        // session available" otherwise), which only exists inside the HTTP request/response cycle
        // a real browser would drive — so the token is read off the rendered page, exactly as a
        // browser would, rather than fetched directly from the container.
        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/login', [
            '_username' => $username,
            '_password' => $password,
            '_csrf_token' => $token,
        ]);
    }

    /**
     * Logout is CSRF-protected, so a bare request to /logout is (correctly) a 403. The token lives
     * only on the rendered page, so this submits the actual "Sign out" form from /account — the same
     * sequence a browser performs, which is also what makes it a real regression test for the form
     * still being there and still carrying a valid token.
     */
    private function logoutViaTheSignOutForm(): void
    {
        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Sign out')->form());
    }
}
