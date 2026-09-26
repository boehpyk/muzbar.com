<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Infrastructure\Http\Form\LaunchSignupFormData;
use App\Infrastructure\Http\Form\LaunchSignupFormType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The pre-launch stub at `/`, plus its "notify me" signup. Temporary by design: when the public UI
 * ships, this controller, its form, its template, `coming-soon.css` and the `prelaunch_signup` table
 * are deleted together and the route name moves to the real home.
 *
 * WHY THIS WRITES SQL FROM A CONTROLLER, IN A CODEBASE THAT OTHERWISE NEVER DOES. A launch mailing
 * list has no invariant beyond "a syntactically valid address, once" — the form enforces the first
 * and a primary key the second — and no lifecycle this code will ever see. An aggregate, a port and
 * a handler would be ceremony wrapped around one `INSERT`, and worse, they would read as a
 * `Notification`-context model that the real one would then feel obliged to inherit. Keeping it all
 * in Infrastructure (which Deptrac allows) makes it obviously disposable.
 */
final class ComingSoonController extends AbstractController
{
    /** The first entry is the fallback when `Accept-Language` names neither. */
    private const array LOCALES = ['en', 'ru'];

    #[Route('/', name: 'home', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        Connection $connection,
        RateLimiterFactoryInterface $launchSignupLimiter,
        TranslatorInterface $translator,
        LocaleSwitcher $localeSwitcher,
    ): Response {
        // Negotiated here rather than with a `{_locale}` prefix because the stub has one URL: a
        // prefix would mean redirecting `/` to `/en` before launch and un-redirecting it after.
        $locale = $request->getPreferredLanguage(self::LOCALES) ?? self::LOCALES[0];
        // Both calls are needed. `LocaleListener` already handed the translator the default locale
        // before this controller ran, so `$request->setLocale()` alone changes `<html lang>` and
        // nothing else — an English page labelled Russian. `LocaleSwitcher` re-points the
        // translator (and every other locale-aware service) but does not touch the request.
        $request->setLocale($locale);
        $localeSwitcher->setLocale($locale);

        $form = $this->createForm(LaunchSignupFormType::class, new LaunchSignupFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$launchSignupLimiter->create($request->getClientIp() ?? 'unknown-client')->consume()->isAccepted()) {
                // A hand-made `FormError` is rendered verbatim — only validator messages arrive
                // already translated — so this one is translated here.
                $form->addError(new FormError($translator->trans('too_many', domain: 'coming_soon')));

                return $this->page($form, Response::HTTP_TOO_MANY_REQUESTS);
            }

            /** @var LaunchSignupFormData $data */
            $data = $form->getData();

            // `DO NOTHING` rather than an error on a repeat: the visitor gets the same "thanks"
            // either way, so the form cannot be used to test whether an address is already listed.
            $connection->executeStatement(
                'INSERT INTO prelaunch_signup (email, locale, created_at) VALUES (?, ?, NOW())
                 ON CONFLICT (email) DO NOTHING',
                [mb_strtolower(trim((string) $data->email)), $locale],
            );

            $this->addFlash('launch_signup', 'thanks');

            // Post/Redirect/Get: a refresh of the thank-you page must not resubmit the form.
            return $this->redirectToRoute('home', status: Response::HTTP_SEE_OTHER);
        }

        return $this->page(
            $form,
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    /**
     * @param FormInterface<LaunchSignupFormData> $form
     */
    private function page(FormInterface $form, int $status): Response
    {
        $response = $this->render('coming_soon.html.twig', ['signupForm' => $form], new Response(status: $status));

        // The body now depends on a request header. Without `Vary`, any cache in front of us — a
        // browser's, or a CDN added later — would serve the first visitor's language to everyone.
        $response->setVary('Accept-Language');

        return $response;
    }
}
