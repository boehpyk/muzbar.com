<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Http\Controller;

use App\Application\Identity\Command\RegisterUser;
use App\Application\Identity\Handler\RegisterUserHandler;
use App\Domain\Identity\Exception\EmailAlreadyRegistered;
use App\Domain\Identity\Exception\InvalidEmail;
use App\Domain\Identity\Exception\WeakPassword;
use App\Infrastructure\Identity\Form\RegistrationFormData;
use App\Infrastructure\Identity\Form\RegistrationFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The HTTP adapter over the `RegisterUser` use case.
 *
 * Its entire job is translation: request -> command, domain exception -> field error, success ->
 * redirect. There is no business rule in this file, and if one ever appears here it belongs in the
 * aggregate instead.
 *
 * NO MESSENGER BUS IN THIS SLICE. The handler is injected and called directly. A bus would buy
 * nothing today — the command is synchronous, its result (the new `UserId`) is needed in the same
 * request, and an async transport would make registration succeed before the account exists.
 * Adding an indirection whose only benefit is looking like a bigger application is how a codebase
 * becomes hard to read.
 */
final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, RegisterUserHandler $registerUser): Response
    {
        $data = new RegistrationFormData();
        $form = $this->createForm(RegistrationFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $registerUser(new RegisterUser(
                    (string) $data->email,
                    (string) $data->plainPassword,
                ));

                $this->addFlash('success', 'Your account has been created. Please sign in.');

                // 302 to the login page, and the user is deliberately NOT authenticated here
                // (AC-3). Auto-login after registration is a real convenience and it belongs to
                // `identity-login-overlay`, which owns the one-step flow end to end. Bolting it on
                // now would mean two places deciding when a session starts.
                return $this->redirectToRoute('app_login');
            } catch (EmailAlreadyRegistered) {
                // Both duplicate paths land here — the handler's `existsByEmail()` pre-check and
                // the adapter's translation of the unique-index violation when that pre-check lost
                // a race (AC-9). One catch, one sentence, so the racing user and the ordinary user
                // see byte-identical pages.
                //
                // The exception's own message carries the email for the operator's log; it is
                // never echoed. This fixed string is what the visitor gets.
                $form->get('email')->addError(new FormError('An account with this email already exists.'));
            } catch (InvalidEmail $e) {
                // Defensive, and it does fire in one real case: `Assert\Email(mode: strict)` accepts
                // internationalised addresses (`ünï@exämple.com`) that the `Email` value object's
                // `FILTER_VALIDATE_EMAIL` rejects. The two validators disagree at the edges, which
                // is exactly why the value object exists — but the visitor deserves a field error,
                // not a 500.
                $form->get('email')->addError(new FormError($e->getMessage()));
            } catch (WeakPassword $e) {
                // Likewise for the character/byte asymmetry documented on `RegistrationFormData`.
                // `WeakPassword`'s messages name only the violated bound, never the password or its
                // length, so echoing them leaks nothing.
                $form->get('plainPassword')->addError(new FormError($e->getMessage()));
            }
        }

        // Re-rendered with 422 on a failed submit so the response *status* tells the truth about
        // what happened — a 200 carrying an error page is a lie that breaks Turbo, HTTP caches and
        // anything else reading the status line.
        return $this->render('identity/register.html.twig', [
            'registrationForm' => $form,
        ], new Response(null, $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK));
    }
}
