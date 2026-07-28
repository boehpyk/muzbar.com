<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Http\Controller;

use App\Application\Identity\Command\RequestEmailVerification;
use App\Application\Identity\Command\VerifyEmailWithToken;
use App\Application\Identity\Handler\RequestEmailVerificationHandler;
use App\Application\Identity\Handler\VerifyEmailWithTokenHandler;
use App\Application\Identity\VerificationOutcome;
use App\Domain\Identity\Exception\EmailAlreadyVerified;
use App\Domain\Identity\Exception\EmailVerificationLinkAlreadyRedeemed;
use App\Domain\Identity\Exception\EmailVerificationLinkExpired;
use App\Domain\Identity\Exception\EmailVerificationRequestNotFound;
use App\Domain\Identity\Exception\EmailVerificationTokenMismatch;
use App\Domain\Identity\Exception\InvalidEmail;
use App\Domain\Identity\Exception\InvalidVerificationToken;
use App\Domain\Identity\Exception\TooManyVerificationRequests;
use App\Domain\Identity\Exception\UserNotFound;
use App\Infrastructure\Identity\Form\ResendVerificationFormData;
use App\Infrastructure\Identity\Form\ResendVerificationFormType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The three anonymous pages of the verification flow: redeem a link, "check your inbox", and ask for
 * a new link.
 *
 * ALL THREE ARE ANONYMOUS, AND NOTHING IS ADDED TO `access_control` — `^/account` stays the only
 * rule. That is not laziness, it is the only coherent option: these routes exist *for* people who
 * cannot log in. An unverified user is now refused at authentication by
 * `VerifiedAccountUserChecker`, so a firewall rule protecting the resend form would lock the door
 * whose key is inside. Whenever a recovery flow is put behind the thing it recovers from, the result
 * is a support queue.
 *
 * The class is a translator and holds no rules. What it *does* hold, and what is worth reading it
 * for, is a **presentation policy**: six distinguishable internal failures are rendered as one
 * indistinguishable external answer. The Application layer deliberately kept them distinct — six
 * exception classes, so the system can tell them apart in a log and a test — and collapsing them is
 * a decision about what a stranger is allowed to learn, which is a boundary concern by definition.
 * Making that collapse the Domain's job would have thrown the information away where it could never
 * be recovered.
 *
 * ROUTE ORDERING, WHICH IS A REAL TRAP AND IS DISARMED ON PURPOSE. `/verify-email/{token}` accepts
 * any single non-empty path segment (`[^/]+`), so it genuinely *does* match the literal paths
 * `/verify-email/sent` and `/verify-email/resend`. Nothing about the pattern excludes them. Two
 * things stop it swallowing both and turning the whole flow into "invalid link" redirects:
 *
 *   1. The two literal routes are declared **first** in this file. Symfony's attribute loader adds
 *      routes in method-declaration order, and the router matches first-wins, so order alone is
 *      sufficient today.
 *   2. They also carry an explicit `priority`, which the attribute loader sorts on before order is
 *      considered. That is the belt to declaration order's braces: it survives somebody sorting the
 *      methods alphabetically, extracting `verify()` into a trait, or moving one route to another
 *      class — none of which a reader would expect to change routing, and all of which would.
 *
 * This was an accident waiting to happen and briefly *was* the design: the `{token}` requirement
 * used to demand exactly 43 characters, which excluded both literals for free. That regex has been
 * removed (it was also rejecting malformed tokens with a bare 404 instead of the resend form AC-12
 * requires), and with it went the accidental protection. Hence the two explicit mechanisms above.
 * Verified with `debug:router` and `router:match /verify-email/resend`, not assumed.
 */
final class EmailVerificationController extends AbstractController
{
    /**
     * The single sentence every failure of `verify()` produces, in one constant so that "the same
     * flash string" is enforced by the compiler rather than by two developers copying a sentence
     * correctly. AC-10 and AC-11 require the expired and never-issued cases to be byte-identical;
     * a constant is how that stops being a thing tests have to keep catching.
     */
    private const string INVALID_LINK_FLASH = 'That link is no longer valid. Enter your address and we will send a new one.';

    /**
     * Likewise the one answer the resend form gives to success, an unknown address, an already
     * verified account and an over-limit account (AC-15). Note the shape of the sentence: it is a
     * conditional that asserts nothing. "We have sent you a new link" would be a lie in three of the
     * four cases and, worse, a true statement in the fourth — which is exactly the difference an
     * enumerator is looking for.
     */
    private const string NEUTRAL_RESEND_FLASH = 'If that address belongs to an account that still needs verifying, we have sent it a new link. Please check your inbox.';

    /**
     * The "check your inbox" page. Static, and reached from two places: `RegistrationController`
     * after a successful signup (AC-24), and `resend()` below after any submission at all.
     *
     * Being static is what makes it safe to be the resend form's universal destination. A page that
     * said "we sent a link to name@example.com" would have to be told which address, which would put
     * the address in the session or the query string — and a page that varied at all between the
     * four AC-15 cases would break the byte-identical guarantee at the last possible moment.
     *
     * `priority: 10` because `/verify-email/{token}` below matches this exact path too. See the
     * class docblock — do not remove it, and do not move this method below `verify()`.
     */
    #[Route('/verify-email/sent', name: 'app_verify_email_sent', methods: ['GET'], priority: 10)]
    public function sent(): Response
    {
        return $this->render('identity/verify_email_sent.html.twig');
    }

    /**
     * Ask for a new link.
     *
     * The recovery path for every way the first mail can fail to arrive: a queue that was down when
     * the listener ran (`IssueVerificationOnUserRegistered` logs and returns rather than 500ing on a
     * committed account), a message a spam filter ate, a link left to expire, or an inbox its owner
     * simply cannot find. ADR-0010 leaned on this endpoint's existence to justify *not* building a
     * transactional outbox — a lost event costs one click here — so it is load-bearing infrastructure
     * wearing a very plain form.
     *
     * `priority: 10` for the same reason `sent()` has it: `/verify-email/{token}` matches this path.
     * If this route ever loses, every failed verification redirects to a redirect loop into itself.
     */
    #[Route('/verify-email/resend', name: 'app_verify_email_resend', methods: ['GET', 'POST'], priority: 10)]
    public function resend(
        Request $request,
        RequestEmailVerificationHandler $requestEmailVerification,
        RateLimiterFactoryInterface $verificationEmailResendLimiter,
        LoggerInterface $logger,
    ): Response {
        // BEFORE THE CONTROLLER BODY DOES ANYTHING ELSE (AC-18). Not after the form is built, not
        // after validation, and certainly not inside the success branch: the point of a boundary
        // limiter is to be cheaper than the work it protects, and it protects against submissions
        // that are *individually valid*. A limiter that only counted successful requests would let
        // an attacker probe indefinitely with junk, and one that ran after `handleRequest()` would
        // still pay for parsing and validating every request in a flood.
        //
        // POST ONLY. A GET renders the empty form and costs nothing worth rationing; charging it a
        // token would mean a person who opened the page twice, or a browser that prefetched it, had
        // silently spent part of their budget before typing anything.
        if ($request->isMethod(Request::METHOD_POST)) {
            // Keyed on the client IP, which is the coarsest honest identifier available for an
            // anonymous endpoint — there is no session and no account to key on yet, and keying on
            // the *submitted address* would let one host exhaust a victim's budget on their behalf
            // and would itself be a probe (a limiter that behaves differently per address is an
            // oracle). The trade is accepted knowingly: everyone behind one corporate NAT shares a
            // budget of five an hour. At muzbar's scale that is a theoretical inconvenience, and it
            // is not the only defence — the Domain's per-user `MAX_ISSUES_PER_HOUR` is the layer
            // that actually protects a specific person's inbox, and it is immune to the source IP.
            //
            // `getClientIp()` is nullable (there is no client in a CLI-driven kernel request), and
            // an empty key would silently pool every such request into one bucket. Naming the
            // fallback makes that bucket visible if it ever fills up.
            $limit = $verificationEmailResendLimiter->create($request->getClientIp() ?? 'unknown-client')->consume();

            if (!$limit->isAccepted()) {
                // A thrown `TooManyRequestsHttpException` rather than a hand-built `new Response(…,
                // 429)` because the framework's exception listener then adds `Retry-After` from the
                // limiter's own reset instant — a real, correct value rather than a guess — and
                // because the request genuinely is over, so unwinding the stack is honest. Nothing
                // below this line runs: no form, no validation, no handler, no mail.
                //
                // 429 is the one response on this endpoint that is *not* the neutral one, and that
                // is fine: it reveals a fact about the requester's own traffic, which they already
                // know, and nothing whatsoever about any account.
                throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Too many verification emails requested from this address. Please try again later.');
            }
        }

        $data = new ResendVerificationFormData();
        $form = $this->createForm(ResendVerificationFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $requestEmailVerification(new RequestEmailVerification((string) $data->email));
            } catch (UserNotFound|EmailAlreadyVerified|TooManyVerificationRequests|InvalidEmail $e) {
                // THE FOUR-WAY COLLAPSE (AC-15). This catch is the whole security property of the
                // endpoint, and it is easy to read as sloppy error handling, so: every one of these
                // is a *normal outcome* of a public form, not a bug. Nobody registered with that
                // address; the account is already verified; the account has had five links this
                // hour; the address passed `Assert\Email(strict)` but not the value object's
                // `FILTER_VALIDATE_EMAIL`. In all four cases no mail is sent, and in all four cases
                // the visitor is told exactly what a visitor whose mail *was* sent is told.
                //
                // The response has to match to the byte, which is why the flash, the status and the
                // `Location` are produced by the code path below rather than duplicated in here.
                // Anything that varies is an oracle — and it does not take a message to leak state.
                // A different status, a different redirect target, a stray field error, even a
                // reliably different response time would be enough to sort a list of a million
                // addresses into "has an account here" and "does not". That list is the product an
                // attacker came for; the mail was never the point.
                //
                // Logged at INFO, deliberately not at warning or error. Three of these four are
                // ordinary human behaviour — a typo, an impatient double-submit, a person who forgot
                // they already verified — and an endpoint whose normal use fills the error log
                // trains an operator to ignore the error log. The class name is enough to see the
                // distribution; the address is deliberately absent for the reason
                // `IssueVerificationOnUserRegistered` sets out at length (log identifiers, resolve
                // them at the point of use) and, here, because writing it would put an
                // attacker-chosen string of unverified addresses into the log a line at a time.
                $logger->info('A verification resend produced no email.', ['reason' => $e::class]);
            }

            // ONE EXIT FOR ALL FIVE OUTCOMES — success and the four caught above. Reached by falling
            // out of the `try`, not by a `return` inside it, so there is structurally only one
            // response to keep identical rather than five to keep in step.
            $this->addFlash('success', self::NEUTRAL_RESEND_FLASH);

            return $this->redirectToRoute('app_verify_email_sent');
        }

        // 422 on a failed submit, matching `RegistrationController`: a 200 carrying an error page is
        // a lie that breaks Turbo, HTTP caches and anything else that reads the status line. As
        // `ResendVerificationFormData` argues, distinguishing *this* branch leaks nothing — the
        // submitter already knows whether what they typed was an email address.
        return $this->render('identity/verify_email_resend.html.twig', [
            'resendForm' => $form,
        ], new Response(null, $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK));
    }

    /**
     * Redeem a verification link.
     *
     * `requirements` deliberately accepts **any** single path segment (`[^/]+`) and does no format
     * checking whatsoever. `VerificationToken::fromString()` inside the handler is the only gate,
     * and it runs before the repository is touched — so a malformed token costs one `preg_match`
     * and no database query, which is what the failure contract asks for.
     *
     * IT USED TO DUPLICATE THE VALUE OBJECT'S REGEX, AND THAT WAS THE BUG. The requirement was
     * `[A-Za-z0-9_-]{43}`, byte-for-byte the same rule as `VerificationToken::FORMAT`, defended as
     * a cheap outer gate that kept junk out of PHP. What it actually did was answer a mangled link
     * with a **bare 404** — because the router rejected the path before this method existed as far
     * as the request was concerned, making the `InvalidVerificationToken` catch below dead code.
     * AC-12 requires the opposite: a malformed token gets the same 302-to-the-resend-form as a
     * token that was never issued. The scenario the catch block itself names — mail clients wrap
     * long lines, people paste badly — is the *common* one, and it was being sent to a dead end
     * instead of to the one form that fixes it. A duplicated rule is not defence in depth when the
     * outer copy has the wrong failure mode; it is just the wrong answer, delivered sooner.
     *
     * What was lost is small and priced: a 10 kB junk path segment now reaches PHP. It reaches
     * exactly one regex against an unanchored-length string and a redirect, allocates nothing, and
     * hits no repository — the same work any 404 costs, minus the error page. nginx caps the
     * request line long before anything interesting arrives.
     *
     * The corollary is that this route now matches `/verify-email/sent` and `/verify-email/resend`
     * too. That is handled by declaration order plus `priority` on those two — see the class
     * docblock, which is where a reader tempted to reorder these methods will look.
     *
     * GET, and only GET. A verification link is followed by clicking it in a mail client, so it
     * cannot be anything else — which is worth stating because it means this is a state-changing
     * GET, normally a design error. It is defensible here for the same reasons it is unavoidable:
     * the token is single-use and unguessable, so the request is not forgeable by a third party the
     * way a CSRF-able state change is, and the flow is idempotent under repetition (AC-8) because
     * mail scanners will absolutely fetch this URL before the human does.
     */
    #[Route(
        '/verify-email/{token}',
        name: 'app_verify_email',
        requirements: ['token' => '[^/]+'],
        methods: ['GET'],
    )]
    public function verify(string $token, VerifyEmailWithTokenHandler $verifyEmail, LoggerInterface $logger): Response
    {
        try {
            $outcome = $verifyEmail(new VerifyEmailWithToken($token));
        } catch (InvalidVerificationToken|EmailVerificationRequestNotFound|EmailVerificationLinkExpired $e) {
            // The three ordinary failures: a mangled URL (mail clients wrap long lines and people
            // paste badly), a token that was never issued, and a link older than 24 hours. All three
            // are things that happen to real users on a normal day, so INFO is the honest level —
            // and all three get the identical response below, because the difference between "never
            // existed" and "expired" is precisely what an attacker probing tokens would want.
            //
            // `InvalidVerificationToken` is the *reachable* one, and only since the route stopped
            // duplicating the value object's regex. While `requirements` demanded 43 base64url
            // characters this branch could not fire through this route at all: the router 404'd the
            // mangled link first, and the arm below it was decoration. AC-12 is what it satisfies —
            // malformed and never-issued are indistinguishable to the visitor, and the value object
            // refuses before `findByTokenHash()` runs, so a junk token still costs zero queries.
            $logger->info('A verification link could not be redeemed.', ['reason' => $e::class]);

            return $this->invalidLink();
        } catch (EmailVerificationLinkAlreadyRedeemed|EmailVerificationTokenMismatch|UserNotFound $e) {
            // ERROR, because all three of these are supposed to be unreachable, and an unreachable
            // state that occurs is the most valuable thing in a log. The failure contract calls for
            // it explicitly on the first and the last; the middle one belongs with them by the same
            // reasoning. Concretely:
            //
            //   AlreadyRedeemed — the handler short-circuits on an already-verified user *before*
            //     redeeming, so reaching the aggregate's already-redeemed guard means a request is
            //     burnt while its user is not verified. Nothing in the code can produce that pair;
            //     it implies data corruption or a manual edit.
            //   TokenMismatch — the repository looked the request up *by* this exact digest and the
            //     aggregate then found a different one. That is a repository returning a row it was
            //     not asked for, i.e. a broken DBAL type or a broken query.
            //   UserNotFound — the request holds a `UserId` matching no row. There is no FK
            //     (ADR-0009 decision 4) and nothing deletes users today, so a dangling request means
            //     a user row was removed without cleaning this table up. Worth knowing about before
            //     the GDPR-erasure conversation, which will have to sweep it.
            //
            // The visitor gets the same sentence as everyone else. They cannot act on any of this
            // and telling them would only leak that *something* exists behind that token.
            $logger->error('A verification link failed in a state that should be unreachable.', [
                'reason' => $e::class,
                'exception' => $e,
            ]);

            return $this->invalidLink();
        }

        // Both outcomes send the visitor to the login form and neither starts a session (AC-13).
        // Auto-login on verification is a real convenience and it is `identity-login-overlay`'s
        // decision to make, not a side effect smuggled in through a link that arrives by email —
        // which is, after all, a channel we have just this moment finished proving the visitor
        // controls.
        //
        // `match` rather than `if`, so adding a third case to `VerificationOutcome` is a compile-time
        // error here instead of a silently missing branch.
        $this->addFlash('success', match ($outcome) {
            VerificationOutcome::Verified => 'Your email address is verified — please sign in.',

            // The friendly answer to a replay, and the reason the flow feels correct rather than
            // broken. Corporate scanners and mail clients pre-fetch links, so the *first* GET is
            // frequently a robot and the human's click is the replay; treating it as an error would
            // show "already used" to the one participant who has used it exactly once.
            VerificationOutcome::AlreadyVerified => 'Your email address is already verified — please sign in.',
        });

        return $this->noReferrer($this->redirectToRoute('app_login'));
    }

    /**
     * The one invalid-link response, shared by every failure `verify()` can produce.
     *
     * A private method rather than a copied pair of lines, because "same status, same `Location`,
     * same flash" (AC-10 … AC-12) is a property that decays the moment it is maintained by hand: the
     * next person adds a catch block, writes a very slightly different sentence, and hands an
     * attacker a way to sort "expired" from "never existed". Here there is one implementation and
     * nothing to keep in step.
     *
     * It redirects to the resend form rather than rendering an error page, because a dead end is a
     * support ticket: the visitor holds a broken link and the only useful thing the system can offer
     * is the way to get a working one.
     */
    private function invalidLink(): RedirectResponse
    {
        $this->addFlash('error', self::INVALID_LINK_FLASH);

        return $this->noReferrer($this->redirectToRoute('app_verify_email_resend'));
    }

    /**
     * `Referrer-Policy: no-referrer` on both verification responses (AC-39) — success and failure
     * alike, because a header that appears only on success is itself a signal.
     *
     * The token travels in the URL path, which is the part of a URL that leaks: it lands in nginx
     * access logs, in browser history, in any corporate proxy on the way, and in the `Referer`
     * header of requests the visited page goes on to make. This header addresses the last of those.
     * On a 302 it governs the redirected navigation, so the login page is reached with no referrer
     * carrying the token.
     *
     * IT IS NOT THE MITIGATION, AND SAYING SO MATTERS. The real defences are that the token is
     * single-use (redeemed on first successful click, so a leaked one is spent) and short-lived (24
     * hours). This is the cheap belt-and-braces layer that Risk 7 in the technical plan closed on —
     * one line, no cost, closes one specific path. Treating it as the answer would be exactly the
     * kind of security theatre that lets a real hole survive review, which is why the plan's
     * argument for adopting it was explicitly "and the header costs one line", not "and this makes
     * the token safe in a URL".
     */
    private function noReferrer(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
