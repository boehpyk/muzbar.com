<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * `Referrer-Policy: no-referrer` on **every** response from both reset-link routes (AC-15).
 *
 * WHY A LISTENER AND NOT A LINE IN THE CONTROLLER, WHICH IS WHERE THIS USED TO LIVE. A private
 * `noReferrer()` helper can only decorate a response the controller **returns**. It cannot touch a
 * response the framework *builds* — and the reset-link routes produce exactly such a response on the
 * path that matters most: `PasswordResetController::check()` throws
 * `TooManyRequestsHttpException` when the per-IP limiter refuses (AC-19), the stack unwinds past
 * every line of the controller, and the 429 the error listener renders carried no header at all. So
 * the one response served on a URL **whose path is the plaintext token** was the one response
 * missing the header that governs where that path gets sent next.
 *
 * That is not merely an omission of a nice-to-have. AC-15 exists because *a header present on some
 * responses and not others is itself a signal*: it separates the responses this flow works to make
 * indistinguishable. A helper wrapped around four `return` statements makes the property true by
 * inspection today and false the first time somebody adds a fifth exit or throws. Here it is true by
 * construction: every response served on one of these URLs passes through the main request's
 * `kernel.response`, whatever produced it — a controller `return`, a thrown `HttpException`, a 500
 * from the error controller, a 405 from the router.
 *
 * **THE LAST OF THOSE COSTS A SECOND MATCHING RULE, AND THE REASON IS WORTH THE FOUR LINES IT
 * TAKES.** `RouterListener::onKernelRequest()` writes `_route` into the request attributes *only*
 * after a successful match (`vendor/symfony/http-kernel/EventListener/RouterListener.php:146`); a
 * `MethodNotAllowedException` is caught and rethrown at `:157` **before** that line is reached. So
 * `POST /reset-password/{token}` — a route declared `methods: ['GET']` — produces a 405 with no
 * `_route` at all, and a listener that only knows route names would skip the one response served on
 * the URL **whose path is the plaintext token**. `matches()` below therefore falls back to the path
 * when, and only when, routing never got far enough to name a route. Verified against the running
 * stack, not inferred: before that fallback existed, `curl -X POST /reset-password/abc` came back
 * 405 with no `Referrer-Policy` while the GET on the same URL had it.
 *
 * **WHY NOT THE OBVIOUS FIX — `return new Response(…, 429)` FROM THE CONTROLLER.** Because the
 * `Retry-After` on that 429 is the limiter's **own reset instant**, carried by the thrown
 * `TooManyRequestsHttpException` and put on the response by the framework: `ErrorListener` renders
 * the error in a sub-request, `FlattenException::createFromThrowable()` merges the exception's
 * `getHeaders()` into the flattened exception (`vendor/symfony/error-handler/Exception/FlattenException.php:57`),
 * and `ErrorController::__invoke()` builds `new Response($e->getAsString(), $e->getStatusCode(),
 * $e->getHeaders())` from it. **Not** `HttpKernel::handleThrowable()`, which is the plausible-looking
 * answer and the wrong one: its `$response->headers->add($e->getHeaders())` sits behind
 * `!$response->isClientError() && !$response->isServerError() && …` (`HttpKernel.php:255-263`), and
 * the response it is inspecting is already a 429, so that branch is skipped every single time. The
 * conclusion is unchanged — throw, don't build by hand — but the mechanism is the part a future
 * reader will re-check, and a wrong one makes the re-check fail against correct code.
 *
 * A hand-built response would have to re-derive "when may this client try again" a second time, next
 * to the authoritative expression and free to drift from it — and a wrong `Retry-After` is worse than
 * none, because clients obey it. Throwing keeps the correct value; this listener then runs on the
 * **main** request's `kernel.response` (`HttpKernel::handleThrowable()` calls `filterResponse()` with
 * the main request and its attributes intact), which is after the error sub-request has finished and
 * therefore after `Retry-After` is already on the response. The ordering is what makes both
 * properties true at once, and it is why the fix is here rather than at the throw site.
 *
 * SCOPED TO THE TWO RESET-LINK ROUTES, AND `/forgot-password` IS DELIBERATELY NOT IN THE LIST. AC-15
 * governs the routes that carry a token, and `/forgot-password` carries none: nothing in its URL is a
 * secret, so there is no referrer to suppress and the header would be decoration. If it is ever
 * wanted there it goes on all of that route's responses at once, by adding the route name below.
 *
 * IT IS NOT THE MITIGATION, AND SAYING SO MATTERS. The real defences are the redirect that takes the
 * token out of the URL after a single hop (ADR-0011 decision 8), the token being single-use, and its
 * one-hour life. This is the cheap belt-and-braces layer — one header, no cost, closes one specific
 * path: the `Referer` of requests the visited page goes on to make. Treating it as the answer is the
 * kind of security theatre that lets a real hole survive review.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class PasswordResetReferrerPolicy
{
    /**
     * The two routes AC-15 names. Route **names** wherever a name exists, because a name is what the
     * AC is written in terms of and a prefix test alone would silently start covering any future
     * route mounted under `/reset-password/…`, which ADR-0011 spends a paragraph forbidding.
     *
     * @var list<string>
     */
    private const array RESET_LINK_ROUTES = [
        'app_reset_password_check',
        'app_reset_password',
    ];

    /**
     * The path both of those routes are mounted at — `/reset-password` exactly, or one segment below
     * it. Used **only** when the request carries no `_route`, i.e. when routing itself refused (405,
     * 404) and no name exists to test.
     */
    private const string RESET_LINK_PATH = '/reset-password';

    public function __invoke(ResponseEvent $event): void
    {
        // MAIN REQUEST ONLY. When the controller throws, the error page is rendered in a sub-request
        // whose attributes have been replaced wholesale (`ErrorListener::duplicateRequest()`), so it
        // carries no `_route` — but it *keeps the original path*, so since the fallback below exists
        // this guard is the only thing stopping a nested render from matching. Nothing breaks
        // without it (it is the same `Response` object either way, and setting one header twice is
        // idempotent), which is exactly why it needs to be stated rather than left to luck: a
        // sub-request must not be what decides a main response's security headers, and ESI and
        // fragment renders belong outside this listener for the same reason. The main request's own
        // `kernel.response` still fires afterwards, with the original attributes intact, and that is
        // the response the browser receives.
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->matches($event->getRequest())) {
            return;
        }

        $event->getResponse()->headers->set('Referrer-Policy', 'no-referrer');
    }

    /**
     * Two rules rather than one, and the fallback is narrower than it looks: it fires only when
     * `_route` is absent, which happens only when the router matched nothing (404) or matched the
     * path but not the method (405) — see the class docblock. Over-covering *that* residual set is
     * harmless in a way over-covering real routes would not be, because this header only ever
     * suppresses an outgoing `Referer`: a 404 under `/reset-password/…` gaining it costs nothing,
     * whereas the 405 lacking it leaks the path that *is* the token.
     */
    private function matches(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        if (null !== $route) {
            return \in_array($route, self::RESET_LINK_ROUTES, true);
        }

        $path = $request->getPathInfo();

        return self::RESET_LINK_PATH === $path || str_starts_with($path, self::RESET_LINK_PATH.'/');
    }
}
