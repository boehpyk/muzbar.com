<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A placeholder that exists to be an intended action distinct from `default_target_path`
 * (`/account`), so a redirect after sign-in is observably the one the visitor asked for
 * (ADR-0015). Its content belongs to a later slice; until then it renders no account data at all.
 */
final class ProfileController extends AbstractController
{
    /**
     * `#[IsGranted]` duplicates the `^/profile` rule in `security.yaml` on purpose — the same
     * belt-and-braces reasoning as `AccountController`.
     */
    #[Route('/profile', name: 'app_profile', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        return $this->render('identity/profile.html.twig');
    }
}
