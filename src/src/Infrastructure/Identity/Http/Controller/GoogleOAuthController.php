<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Http\Controller;

use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The two ends of "Continue with Google" (ADR-0014). Neither does the interesting work.
 *
 * `start` hands the visitor to Google. knpu's `OAuth2PKCEClient` (see `knpu_oauth2_client.yaml`)
 * puts a random `state` and the PKCE verifier in the session and the S256 challenge in the URL, so
 * there is nothing for this class to stash. GET is acceptable: a cross-site link to it can only
 * bounce a visitor to Google's account chooser, and the callback is bound to this session by state
 * and PKCE.
 *
 * `check` is the callback. `GoogleAuthenticator` intercepts it on the firewall, so its body runs
 * only if the firewall is misconfigured — the `SecurityController::logout()` precedent.
 */
final class GoogleOAuthController extends AbstractController
{
    /**
     * Scopes are the minimum the use case reads: `sub` (from `openid`), `email` and
     * `email_verified` (from `email`). No `profile` — a name or photo we never use is data we would
     * have to protect.
     */
    private const array SCOPES = ['openid', 'email'];

    #[Route('/connect/google', name: 'app_oauth_google_start', methods: ['GET'])]
    public function start(
        #[Autowire(service: 'knpu.oauth2.client.google')]
        OAuth2ClientInterface $google,
        #[Autowire('%env(default::GOOGLE_CLIENT_ID)%')]
        ?string $googleClientId,
        LoggerInterface $logger,
    ): RedirectResponse {
        // AC-3. The env var is optional at boot (`default::`) so a missing credential cannot take
        // the worker or `docker build` down — which moves the failure here, to the one request that
        // needs it. Unguarded, knpu would happily send the visitor to Google with `client_id=` and
        // they would meet Google's error page instead of ours. `error` so it reaches Sentry: on a
        // box where it fires, every Google sign-in is broken.
        // `default::` resolves an empty or missing variable to null, not ''.
        if (null === $googleClientId || '' === $googleClientId) {
            $logger->error('Google sign-in requested but GOOGLE_CLIENT_ID is not configured.');
            $this->addFlash('error', 'Google sign-in is temporarily unavailable.');

            return $this->redirectToRoute('app_login');
        }

        return $google->redirect(self::SCOPES, []);
    }

    #[Route('/connect/google/check', name: 'app_oauth_google_check', methods: ['GET'])]
    public function check(): never
    {
        throw new \LogicException('This route is intercepted by GoogleAuthenticator on the main firewall and is never executed.');
    }
}
