<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Security;

use App\Application\Identity\Command\SignInWithOAuth;
use App\Application\Identity\Handler\SignInWithOAuthHandler;
use App\Domain\Identity\Exception\EmailAlreadyRegistered;
use App\Domain\Identity\Exception\InvalidEmail;
use App\Domain\Identity\Exception\InvalidOAuthIdentity;
use App\Domain\Identity\Exception\OAuthEmailNotVerified;
use App\Domain\Identity\Exception\OAuthProviderAlreadyLinked;
use App\Domain\Identity\ValueObject\OAuthProvider;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use KnpU\OAuth2ClientBundle\Client\OAuth2PKCEClient;
use KnpU\OAuth2ClientBundle\Exception\InvalidStateException;
use KnpU\OAuth2ClientBundle\Exception\MissingAuthorizationCodeException;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * The Google callback, on the `main` firewall (ADR-0014). The protocol happens here; the decision —
 * recognise, link or provision — happens in `SignInWithOAuthHandler`, which never sees Google.
 *
 * WHAT GUARDS WHAT.
 * - `state` binds the callback to the browser that started it (CSRF on the login itself). knpu checks
 *   it inside `getAccessToken()`.
 * - PKCE binds the authorization code to that same session: `OAuth2PKCEClient` keeps the verifier in
 *   the session and refuses the exchange without one, so a stolen code is useless elsewhere.
 * - `email_verified === true`, strictly. Google sends a JSON boolean; anything else — `"true"`, `1`,
 *   absent — is "not vouched for", because the link-by-email path is only safe for an address the
 *   provider has verified (the pre-hijacking defence).
 *
 * The passport carries only a `UserBadge` with the **account's** email, so `DomainUserProvider` loads
 * the user and `VerifiedAccountUserChecker` runs exactly as it does for a password login.
 *
 * LOGGING: the exception class, never its message. `EmailAlreadyRegistered`'s message contains the
 * address, and provider/HTTP exceptions can quote response bodies. Never the email, subject, code,
 * state, verifier or token. Everything at `error` goes to Sentry, so only failures that are ours or
 * Google's are logged at `error`; anything a visitor can cause is `warning` or `info`.
 */
final class GoogleAuthenticator extends OAuth2Authenticator
{
    use TargetPathTrait;

    public const string NEUTRAL_FAILURE = 'Google sign-in did not complete. Please try again or use your email and password.';

    public const string EMAIL_NOT_VERIFIED = 'Your Google account\'s email address is not verified by Google. Verify it with Google, or sign up with email and password.';

    public function __construct(
        #[Autowire(service: 'knpu.oauth2.client.google')]
        private readonly OAuth2ClientInterface $google,
        private readonly SignInWithOAuthHandler $signIn,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'app_oauth_google_check' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        // Cancelled at Google, or Google refused. Nothing to exchange; the value is not logged
        // because it is attacker-controlled text.
        if ($request->query->has('error')) {
            throw $this->fail('warning', 'Google sign-in was cancelled or refused at Google.', 'provider_error_param');
        }

        // Checked here rather than caught: `OAuth2PKCEClient` would throw a bare `\LogicException`
        // for this, and catching that type would also swallow unrelated bugs. No verifier means a
        // callback in a session that never started the flow (AC-13) — the same visitor-caused case
        // as a bad `state`, which the client checks only after the verifier.
        if (!$request->hasSession() || !$request->getSession()->has(OAuth2PKCEClient::VERIFIER_KEY)) {
            throw $this->fail('warning', 'Google sign-in callback had no PKCE verifier in the session.', 'missing_pkce_verifier');
        }

        try {
            $claims = $this->google->fetchUserFromToken($this->google->getAccessToken())->toArray();
        } catch (InvalidStateException|MissingAuthorizationCodeException $e) {
            throw $this->fail('warning', 'Google sign-in callback had no valid state or code.', $e::class);
        } catch (\Throwable $e) {
            // Token exchange or userinfo failed: Google down, bad secret, timeout, a malformed
            // response. Ours or Google's, never the visitor's — so `error`, and Sentry (AC-20).
            throw $this->fail('error', 'Google sign-in failed during the token exchange or userinfo call.', $e::class);
        }

        $subject = $claims['sub'] ?? '';
        $email = $claims['email'] ?? '';

        try {
            $accountEmail = ($this->signIn)(new SignInWithOAuth(
                OAuthProvider::Google->value,
                \is_string($subject) ? $subject : '',
                \is_string($email) ? $email : '',
                // Strict: the JSON boolean `true` and nothing else — not `"true"`, not `1` (AC-9).
                true === ($claims['email_verified'] ?? null),
            ));
        } catch (OAuthEmailNotVerified $e) {
            // The one refusal the visitor can act on, so it gets its own message (AC-9).
            $this->logger->info('Google sign-in refused: Google has not verified the email address.', ['reason' => $e::class]);

            throw new CustomUserMessageAuthenticationException(self::EMAIL_NOT_VERIFIED);
        } catch (OAuthProviderAlreadyLinked $e) {
            // The account already holds a different Google subject (AC-11, I-28). The user id names
            // the account for the operator; it is not an address or a claim.
            throw $this->fail('warning', 'Google sign-in refused.', $e::class, ['user_id' => $e->userId->toString()]);
        } catch (InvalidOAuthIdentity|InvalidEmail|EmailAlreadyRegistered $e) {
            // Malformed claims, or a lost provisioning race (a retry takes the link path). Class
            // only: `EmailAlreadyRegistered`'s message contains the address.
            throw $this->fail('warning', 'Google sign-in refused.', $e::class);
        } catch (\Throwable $e) {
            // Postgres down, or a bug. Not the visitor's doing.
            throw $this->fail('error', 'Google sign-in failed while signing the account in.', $e::class);
        }

        return new SelfValidatingPassport(new UserBadge($accountEmail->toString()));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $target = null;

        if ($request->hasSession()) {
            $target = $this->getTargetPath($request->getSession(), $firewallName);
            $this->removeTargetPath($request->getSession(), $firewallName);
        }

        return new RedirectResponse($target ?? $this->urls->generate('app_account'));
    }

    /**
     * Mirrors `form_login`: the error goes where `AuthenticationUtils::getLastAuthenticationError()`
     * reads it, so /login renders it with no template change. The callback page itself is never
     * rendered, so `code` never reaches a `Referer`.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($request->hasSession()) {
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        }

        return new RedirectResponse($this->urls->generate('app_login'));
    }

    /**
     * @param 'warning'|'error'     $level
     * @param array<string, string> $context
     */
    private function fail(string $level, string $message, string $reason, array $context = []): CustomUserMessageAuthenticationException
    {
        $this->logger->log($level, $message, ['reason' => $reason] + $context);

        return new CustomUserMessageAuthenticationException(self::NEUTRAL_FAILURE);
    }
}
