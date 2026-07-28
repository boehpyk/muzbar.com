<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Infrastructure\Identity\Mail\TwigVerificationMailer;
use App\Tests\Fixture\ThrowingVerificationMailer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email as MimeEmail;

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
    use MailerAssertionsTrait;

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
     * AC-1, AC-3, AC-4, AC-26: a successful registration creates exactly one
     * `identity_email_verification_request` row for the new user and sends exactly one email
     * containing an absolute link with a 43-character token in the path, stating the expiry in
     * human terms, sent from the configured no-reply sender and rendering no address anywhere in
     * the body other than the recipient's own — the whole chain from `RegistrationController`
     * through `IssueVerificationOnUserRegistered` to `RequestEmailVerificationHandler` to
     * `TwigVerificationMailer`, driven end to end through HTTP rather than through any one handler
     * in isolation.
     */
    public function testSuccessfulRegistrationCreatesExactlyOneVerificationRequestAndSendsOneEmail(): void
    {
        $this->submitRegistration('one-link-one-mail@example.com', 'a-strong-password-1');

        $userRow = $this->connection->fetchAssociative('SELECT id FROM identity_user WHERE email = ?', ['one-link-one-mail@example.com']);
        self::assertIsArray($userRow);
        $userId = $this->asString($userRow['id']);

        self::assertSame(1, $this->countVerificationRequestsFor($userId));

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailAddressContains($message, 'To', 'one-link-one-mail@example.com');

        // AC-4: the `From` a human reads is the configured no-reply sender — `config/packages/
        // mailer.yaml` drives both `headers.From` and `envelope.sender` from the single `MAILER_FROM`
        // env var, but nothing asserted the header actually carries it until now. Read back from the
        // environment rather than hardcoded, for the same reason the AC-6 fix reads `DEFAULT_URI`
        // back from the environment instead of from a service the mailer itself is wired from: the
        // assertion must be able to fail if the configured value ever drifts from what is expected.
        $mailerFrom = $_SERVER['MAILER_FROM'] ?? $_ENV['MAILER_FROM'] ?? null;
        self::assertIsString($mailerFrom, 'Expected MAILER_FROM to be present in the test environment (see .env).');
        self::assertEmailAddressContains($message, 'From', $mailerFrom);

        self::assertInstanceOf(MimeEmail::class, $message);
        $textBody = $message->getTextBody();
        self::assertIsString($textBody);
        self::assertMatchesRegularExpression('#/verify-email/[A-Za-z0-9_-]{43}#', $textBody);

        // AC-3: the mail must state the expiry "in human terms", not just the absolute timestamp
        // that already appears next to it — `verify_email.txt.twig` renders
        // `EmailVerificationRequest::LIFETIME_SECONDS` as a round number of hours for exactly this
        // reason. Derived from the aggregate's own constant, not hardcoded as "24", so this
        // assertion tracks the same source of truth the template does rather than agreeing with it
        // by coincidence.
        $lifetimeHours = intdiv(EmailVerificationRequest::LIFETIME_SECONDS, 3600);
        self::assertStringContainsString(\sprintf('expires in %d hours', $lifetimeHours), $textBody);

        // AC-4, AC-32: no address anywhere in the body except the recipient's own — a regression
        // here would mean some other user's or the sender's address leaking into a message the
        // recipient did not consent to have that information disclosed through. Extracting every
        // email-shaped substring and asserting the set is exactly `{recipient}` is stronger than a
        // single `assertStringNotContainsString` for the sender address: it also catches an
        // unrelated address nobody thought to check for.
        preg_match_all('/[A-Za-z0-9.+_-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $textBody, $matches);
        self::assertSame(['one-link-one-mail@example.com'], array_values(array_unique($matches[0])));
    }

    /**
     * AC-5, AC-28: registration still succeeds — the `identity_user` row is committed and the
     * response is the ordinary redirect — even when the verification mail's transport throws.
     * `IssueVerificationOnUserRegistered` catches `\Throwable` precisely so a downstream mail
     * failure can never turn an already-successful registration into a 500 (the account is
     * committed by the time that listener runs; there is no transaction left to roll back).
     *
     * The `VerificationMailer` port is replaced with a double that always throws, bound over the
     * real service via the test container `framework.test: true` exposes — see
     * `ThrowingVerificationMailer`'s own docblock for why this is the sanctioned way to force a
     * transport failure rather than trying to break the real SMTP relay.
     *
     * GOTCHA WORTH RECORDING: `self::getContainer()->set()` must target the *concrete adapter's*
     * service id (`App\Infrastructure\Identity\Mail\TwigVerificationMailer`), not the port alias
     * (`App\Domain\Identity\Port\VerificationMailer`). Symfony's compiler resolves an alias
     * reference to its target id at *compile* time (`ResolveReferencesToAliasesPass`), so
     * `RequestEmailVerificationHandler`'s constructor argument is already wired directly to the
     * concrete class id before this test ever runs — overriding the alias id at runtime changes
     * what `$container->get(VerificationMailer::class)` would return if asked, but not what was
     * already injected into the handler. `ThrowingVerificationMailer` still satisfies the
     * `VerificationMailer` type-hint the handler declares regardless of which id it was registered
     * under, because PHP enforces the parameter's declared type, not the container id string used to
     * fetch it.
     *
     * One more thing this proves along the way: `RequestEmailVerificationHandler` saves the request
     * row *before* calling the mailer (its own "SAVE BEFORE SEND" comment), so the request row still
     * exists even though no mail went out — a link that could never have reached anyone, rather than
     * a mailed link with no row behind it.
     *
     * SECOND GOTCHA, AND THE ONE THAT ACTUALLY BIT FIRST: `KernelBrowser` reboots the kernel — and
     * therefore rebuilds the container — before every request unless told not to
     * (`KernelBrowser::$reboot`, default `true`). `submitRegistration()` below issues a GET then a
     * POST; without `disableReboot()`, the second request would silently discard this test's
     * container override and hit the real mailer, and the test would fail with "1 sent" for a
     * reason that has nothing to do with the assertion it looks like it is making.
     */
    public function testTransportFailureStillCommitsTheUserAndReturnsTheNormalRedirect(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(TwigVerificationMailer::class, new ThrowingVerificationMailer());

        $response = $this->submitRegistration('mail-fails@example.com', 'a-strong-password-1');

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/verify-email/sent', $response->headers->get('Location'));

        $userRow = $this->connection->fetchAssociative('SELECT id FROM identity_user WHERE email = ?', ['mail-fails@example.com']);
        self::assertIsArray($userRow, 'The user row must be committed even though the verification mail failed to send.');

        $userId = $this->asString($userRow['id']);
        self::assertSame(1, $this->countVerificationRequestsFor($userId), 'The request row must still be saved (save-before-send), even though the send itself threw.');

        self::assertEmailCount(0);
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

    private function countVerificationRequestsFor(string $userId): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM identity_email_verification_request WHERE user_id = ?', [$userId]);

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
