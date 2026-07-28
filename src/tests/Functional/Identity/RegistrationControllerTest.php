<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for `GET|POST /register` against the real `muzbar_test` database (DAMA
 * rollback).
 *
 * One `KernelBrowser` per test, created first: `WebTestCase::createClient()` refuses to boot a
 * kernel that is already booted, so the container (and therefore the DBAL `Connection`) must be
 * fetched *after* `createClient()`, never before it.
 */
final class RegistrationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    /**
     * AC-1: the form renders the three fields plus a CSRF token.
     */
    public function testGetRegisterRendersTheFormWithAllFieldsAndACsrfToken(): void
    {
        $crawler = $this->client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="registration_form[email]"]'));
        self::assertCount(1, $crawler->filter('input[name="registration_form[plainPassword][first]"]'));
        self::assertCount(1, $crawler->filter('input[name="registration_form[plainPassword][second]"]'));
        self::assertCount(1, $crawler->filter('input[name="registration_form[_token]"]'));
    }

    /**
     * AC-2: a valid submission creates exactly one row with the email lower-cased and trimmed, a
     * hash that matches the bcrypt/argon2 shape and is never the submitted plaintext, the base
     * role only, no verification timestamp, and a `registered_at` sourced from the Clock (bounded
     * "close to now" here — the exact-Clock-provenance assertion lives in the handler integration
     * test, which can freeze the port).
     */
    public function testValidSubmissionCreatesExactlyOneRowWithNormalisedAndHashedData(): void
    {
        $before = $this->countUsers();

        $this->submitRegistration(' Max@Example.COM ', 'a-strong-password-1');

        self::assertSame($before + 1, $this->countUsers());

        $row = $this->connection->fetchAssociative('SELECT * FROM identity_user WHERE email = ?', ['max@example.com']);
        self::assertIsArray($row);
        self::assertSame('max@example.com', $row['email']);
        self::assertMatchesRegularExpression('/^\$(2y|argon2)/', $this->asString($row['password_hash']));
        self::assertNotSame('a-strong-password-1', $row['password_hash']);
        self::assertSame(['ROLE_USER'], json_decode($this->asString($row['roles']), true));
        self::assertNull($row['email_verified_at']);

        $registeredAt = new \DateTimeImmutable($this->asString($row['registered_at']));
        self::assertLessThan(30, abs((new \DateTimeImmutable())->getTimestamp() - $registeredAt->getTimestamp()));
    }

    /**
     * Success redirects to "check your inbox" with a flash, and does NOT authenticate the visitor.
     *
     * The redirect target changed: slice 1's AC-3 sent a new user to `/login`, and
     * `identity-email-verification`'s AC-24 supersedes it with `/verify-email/sent`. Once the user
     * checker enforces the verified-email rule, a login attempt at this moment is guaranteed to be
     * refused, so telling the user to sign in would be instructing them to do something we have
     * just made impossible.
     *
     * The second half of the assertion is unchanged and still slice 1's AC-3: registration does not
     * start a session. That was always the more important half.
     */
    public function testValidSubmissionRedirectsToVerifyEmailSentWithAFlashAndDoesNotAuthenticate(): void
    {
        $this->submitRegistration('flash-check@example.com', 'a-strong-password-1');

        self::assertResponseRedirects('/verify-email/sent');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your account has been created');

        // Not auto-authenticated: a protected page still bounces to /login.
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    /**
     * AC-4: case-folding and trimming are asserted explicitly — the duplicate check must catch
     * `Max@Example.COM ` against an existing `max@example.com`, re-render (not redirect), and
     * create no second row.
     */
    public function testMixedCaseDuplicateReRendersWithAnErrorAndCreatesNoSecondRow(): void
    {
        $this->submitRegistration('max@example.com', 'a-strong-password-1');
        $before = $this->countUsers();

        $this->submitRegistration(' Max@Example.COM ', 'another-strong-password-2');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('form', 'An account with this email already exists.');

        self::assertSame($before, $this->countUsers());
    }

    /**
     * AC-5 (form boundary): shorter than 12 characters is a field error, 422, no row.
     *
     * Also AC-10's HTTP-body half: `PasswordType` defaults to `always_empty: true`, so the
     * rejected submission's own plaintext must not come back in the re-rendered page.
     */
    public function testPasswordShorterThanTwelveCharactersIsRejectedWithAFieldError(): void
    {
        $before = $this->countUsers();

        $response = $this->submitRegistration('short-password@example.com', 'short11chr');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame($before, $this->countUsers());
        self::assertStringNotContainsString('short11chr', (string) $response->getContent());
    }

    /**
     * AC-6: mismatched password / confirmation, field error, no row.
     */
    public function testMismatchedPasswordConfirmationIsRejected(): void
    {
        $before = $this->countUsers();

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form();
        $form['registration_form[email]'] = 'mismatch@example.com';
        $form['registration_form[plainPassword][first]'] = 'a-strong-password-1';
        $form['registration_form[plainPassword][second]'] = 'a-different-password-2';
        $this->client->submit($form);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('form', 'The two passwords must match.');
        self::assertSame($before, $this->countUsers());
    }

    /**
     * AC-7 (form boundary): a syntactically invalid email is rejected.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidEmailProvider(): iterable
    {
        yield 'no @ or domain' => ['not-an-email'];
        yield 'no domain after @' => ['a@'];
        yield 'no local part' => ['@b.com'];
    }

    #[DataProvider('invalidEmailProvider')]
    public function testSyntacticallyInvalidEmailIsRejected(string $invalid): void
    {
        $before = $this->countUsers();

        $this->submitRegistration($invalid, 'a-strong-password-1');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame($before, $this->countUsers());
    }

    /**
     * AC-7 (form boundary): an address longer than 180 characters is rejected.
     */
    public function testAnAddressLongerThan180CharactersIsRejected(): void
    {
        $before = $this->countUsers();

        $overlong = str_repeat('a', 60).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.com';
        self::assertGreaterThan(180, \strlen($overlong));

        $this->submitRegistration($overlong, 'a-strong-password-1');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame($before, $this->countUsers());
    }

    /**
     * AC-8: a missing CSRF token yields a form error and no row.
     */
    public function testMissingCsrfTokenIsRejected(): void
    {
        $before = $this->countUsers();

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form();
        $form['registration_form[email]'] = 'no-csrf@example.com';
        $form['registration_form[plainPassword][first]'] = 'a-strong-password-1';
        $form['registration_form[plainPassword][second]'] = 'a-strong-password-1';
        $form->remove('registration_form[_token]');
        $this->client->submit($form);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame($before, $this->countUsers());
    }

    /**
     * AC-8: a tampered CSRF token yields a form error and no row.
     */
    public function testTamperedCsrfTokenIsRejected(): void
    {
        $before = $this->countUsers();

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form();
        $form['registration_form[email]'] = 'tampered-csrf@example.com';
        $form['registration_form[plainPassword][first]'] = 'a-strong-password-1';
        $form['registration_form[plainPassword][second]'] = 'a-strong-password-1';
        $form['registration_form[_token]'] = 'this-is-not-a-valid-token';
        $this->client->submit($form);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame($before, $this->countUsers());
    }

    /**
     * AC-20: the form has no `roles` property and rejects extra fields, so a posted
     * `roles[]=ROLE_ADMIN` cannot bind to anything. Whatever HTTP outcome that produces, no row
     * this request could have created holds any role beyond the base one.
     */
    public function testRoleInjectionViaExtraFormFieldsIsIgnoredOrRejected(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $token = $crawler->filter('input[name="registration_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        // `Symfony\Component\DomCrawler\Form` only knows about fields the rendered HTML actually
        // declared, so it cannot express "inject a field the form never rendered". The DTO has no
        // `roles` property to bind to regardless — this posts the raw array directly to prove
        // the request-level defence (`allow_extra_fields: false`) rather than the crawler's.
        $this->client->request('POST', '/register', [
            'registration_form' => [
                'email' => 'role-injection@example.com',
                'plainPassword' => [
                    'first' => 'a-strong-password-1',
                    'second' => 'a-strong-password-1',
                ],
                'roles' => ['ROLE_ADMIN'],
                '_token' => $token,
            ],
        ]);

        $row = $this->connection->fetchAssociative('SELECT roles FROM identity_user WHERE email = ?', ['role-injection@example.com']);

        if (false === $row) {
            // `allow_extra_fields: false` rejected the whole submission — no row exists, which
            // trivially satisfies "no route accepts a role from request input".
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());

            return;
        }

        self::assertSame(['ROLE_USER'], json_decode($this->asString($row['roles']), true));
    }

    private function submitRegistration(string $email, string $password): Response
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form();
        $form['registration_form[email]'] = $email;
        $form['registration_form[plainPassword][first]'] = $password;
        $form['registration_form[plainPassword][second]'] = $password;
        $this->client->submit($form);

        $response = $this->client->getResponse();
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    private function countUsers(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM identity_user');

        if (!is_numeric($count)) {
            self::fail('Expected COUNT(*) to return a numeric value.');
        }

        return (int) $count;
    }

    private function asString(mixed $value): string
    {
        if (!\is_string($value)) {
            self::fail('Expected a string column value.');
        }

        return $value;
    }
}
