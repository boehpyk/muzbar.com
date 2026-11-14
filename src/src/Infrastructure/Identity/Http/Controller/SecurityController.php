<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Http\Controller;

use App\Infrastructure\Identity\Http\IntendedActionPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Login and logout — both of which are mostly *not* implemented here.
 *
 * The firewall's `form_login` listener intercepts `POST /login` before routing hands anything to
 * this class, checks the credentials and the CSRF token, and redirects. `login()` therefore only
 * ever runs for the GET that renders the form, and for the re-render after a failed attempt. That
 * inversion is the whole reason there is no `if ($request->isMethod('POST'))` in this file: the
 * password is never compared by application code (ADR-0005).
 */
final class SecurityController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, Security $security): Response
    {
        // The one writer of the session target path (ADR-0015), and only on GET: the failed-login
        // re-render arrives as a GET without the parameter, so it leaves a stashed target alone.
        // `all()`, never `get()`: `get()` throws on `_target_path[]=`. A rejected value is ignored
        // silently — it is attacker-controlled text, so it is neither logged nor echoed.
        if ($request->isMethod('GET') && $request->hasSession()) {
            $path = IntendedActionPath::fromUntrusted($request->query->all()['_target_path'] ?? null);
            $firewall = $security->getFirewallConfig($request);
            if (null !== $path && null !== $firewall) {
                // The firewall's own name rather than a `'main'` literal, so it cannot drift from
                // security.yaml.
                $this->saveTargetPath($request->getSession(), $firewall->getName(), $path);
            }
        }

        return $this->render('identity/login.html.twig', [
            // Whatever the firewall stashed on the last failed attempt. It is an exception object,
            // not a string, and the template renders its translated message key — see the template
            // for why that matters to AC-13.
            'error' => $authenticationUtils->getLastAuthenticationError(),

            // Re-populating only the identifier is safe and saves a retype. The password is never
            // echoed back.
            'lastUsername' => $authenticationUtils->getLastUsername(),
        ]);
    }

    /**
     * Deliberately empty.
     *
     * The route has to exist so `security.yaml`'s `logout.path` can point at it and so
     * `path('app_logout')` resolves in a template, but the firewall's logout listener handles the
     * request — invalidating the session, clearing the cookie, firing the logout event — and never
     * lets execution reach this body. Symfony throws a `LogicException` here if it ever does,
     * which would mean the firewall is misconfigured.
     */
    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('This method is intercepted by the logout key on the firewall and is never executed.');
    }
}
