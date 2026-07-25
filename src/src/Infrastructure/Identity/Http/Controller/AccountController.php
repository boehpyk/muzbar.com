<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Http\Controller;

use App\Infrastructure\Identity\Security\SecurityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The smallest possible page that proves "logged in" is a real, observable state.
 *
 * It exists so login has somewhere to land and so the functional tests have something to assert
 * against; the actual account area arrives with the public UI. Whatever it grows into, the rule it
 * establishes today holds: **a page renders the authenticated user's own data and nobody else's**
 * (Constitution §8, AC-21/AC-22). No roles, no password hash, no list of other accounts.
 */
final class AccountController extends AbstractController
{
    /**
     * `#[IsGranted]` duplicates the `^/account` rule in `security.yaml` on purpose. The
     * access_control rule is the enforcement that survives refactoring; the attribute is the
     * documentation a reader of *this* method actually sees. Authorisation that is only visible in
     * a config file three directories away is authorisation people forget exists.
     */
    #[Route('/account', name: 'app_account', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(#[CurrentUser] SecurityUser $user): Response
    {
        return $this->render('identity/account.html.twig', [
            // The email string, not the `SecurityUser` object: handing the template the whole
            // adapter would put `getPassword()` and `getRoles()` one dot away from a careless
            // `{{ user.password }}`. Passing exactly what the page renders makes the privacy rule
            // structural instead of a matter of template discipline.
            'email' => $user->getUserIdentifier(),
        ]);
    }
}
