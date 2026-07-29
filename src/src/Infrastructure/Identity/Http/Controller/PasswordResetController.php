<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Http\Controller;

use App\Application\Identity\Command\RequestPasswordReset;
use App\Application\Identity\Command\ResetPasswordWithToken;
use App\Application\Identity\Handler\CheckPasswordResetTokenHandler;
use App\Application\Identity\Handler\RequestPasswordResetHandler;
use App\Application\Identity\Handler\ResetPasswordWithTokenHandler;
use App\Application\Identity\Query\CheckPasswordResetToken;
use App\Domain\Identity\Exception\InvalidEmail;
use App\Domain\Identity\Exception\InvalidResetToken;
use App\Domain\Identity\Exception\PasswordResetLinkAlreadyUsed;
use App\Domain\Identity\Exception\PasswordResetLinkExpired;
use App\Domain\Identity\Exception\PasswordResetLinkInvalidated;
use App\Domain\Identity\Exception\PasswordResetRequestNotFound;
use App\Domain\Identity\Exception\PasswordResetTokenMismatch;
use App\Domain\Identity\Exception\StalePasswordResetRequest;
use App\Domain\Identity\Exception\TooManyPasswordResetRequests;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Exception\WeakPassword;
use App\Infrastructure\Identity\Form\ForgotPasswordFormData;
use App\Infrastructure\Identity\Form\ForgotPasswordFormType;
use App\Infrastructure\Identity\Form\NewPasswordFormData;
use App\Infrastructure\Identity\Form\NewPasswordFormType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The four anonymous pages of the password-reset flow: ask for a link, "check your inbox", open a
 * link, set a new password.
 *
 * THE INVERTED TWIN OF `EmailVerificationController`, WHICH SITS NEXT TO THIS FILE FOREVER. Read the
 * two together: they share a shape — a public form, a neutral response, a token route, an
 * indistinguishable failure — and they differ in four places that ADR-0011 decision 9 enumerates.
 * Two of those four differences live in this file and each carries its reason at the call site,
 * because a reader who notices a difference and finds no explanation will assume one of the two is a
 * bug and "fix" it: the GET here **mutates nothing** (`check()`), and a replay is **refused** rather
 * than absorbed (`reset()`, and the flash it produces).
 *
 * ALL FOUR ROUTES ARE ANONYMOUS, AND NOTHING IS ADDED TO `access_control` — `^/account` stays the
 * only rule. That is the only coherent option rather than laziness: these routes exist *for* people
 * who cannot log in. Putting a recovery flow behind the thing it recovers from locks the door whose
 * key is inside, and the result is a support queue.
 *
 * WHAT THE CLASS HOLDS IS A PRESENTATION POLICY, NOT A RULE. The Application layer keeps nine
 * distinguishable failures apart — nine exception classes, so the system can tell them apart in a log
 * and in a test — and this class renders all nine as one external answer. Collapsing them is a
 * decision about what a stranger is allowed to learn, which is a boundary concern by definition;
 * making the Domain do it would have thrown the information away where it could never be recovered.
 *
 * ROUTE SHAPES, AND THE TRAP THAT IS DISARMED BY LAYOUT RATHER THAN BY `priority`. Slice 2's
 * `/verify-email/{token}` (requirement `[^/]+`) genuinely matches the literal `/verify-email/sent`,
 * and only declaration order plus an explicit `priority` keeps that flow working. Here the
 * parameterised route lives under `/reset-password/` and **every literal lives under
 * `/forgot-password/`**, so nothing is shadowed and no `priority` is needed. Verified with
 * `router:match /forgot-password/sent` and `router:match /reset-password/sent`, not assumed. **If a
 * literal is ever added under `/reset-password/…`, the trap returns immediately** — put it under
 * `/forgot-password/` instead, or re-derive the priority argument from scratch.
 *
 * THE FLOW DEPENDS ON A WORKING SESSION, WHICH IS AN ACCEPTED COST RECORDED IN ADR-0011 DECISION 8
 * RATHER THAN AN OVERSIGHT. The plaintext token is handed from `check()` to `reset()` through the
 * session instead of through the URL, so Redis being down breaks account recovery rather than merely
 * un-throttling it. The alternative — a live account-takeover credential in the address bar of a page
 * the user sits on and types into, and therefore in browser history, in cloud-synced history, in any
 * outbound `Referer`, and in whatever gets pasted into a support chat — was worse.
 */
final class PasswordResetController extends AbstractController
{
    /**
     * Where `check()` puts the plaintext token and `reset()` reads it from. ADR-0011 decision 8.
     *
     * A constant rather than a literal in two methods, because the two halves of a handoff spelled
     * out twice is a typo away from a flow that always says "that link is no longer valid" — with
     * every test that drives the two routes in sequence still passing, since both halves would be
     * wrong together.
     */
    private const string SESSION_TOKEN_KEY = 'identity.password_reset_token';

    /**
     * The one answer `/forgot-password` gives to success, an unknown address, an account at the
     * per-user cap, and an address the `Email` value object refuses (AC-5, AC-6). One constant, so
     * that "the same sentence" is enforced by the compiler rather than by somebody copying a string
     * correctly four times.
     *
     * Note the shape of the sentence: it is a conditional that asserts nothing. "We have sent you a
     * link" would be a lie in three of the four cases and, worse, a true statement in the fourth —
     * which is exactly the difference an enumerator is looking for.
     */
    private const string NEUTRAL_REQUEST_FLASH = 'If that address belongs to an account, we have sent it a link for setting a new password. Please check your inbox.';

    /**
     * The single sentence every reset-link failure produces — nine causes, one string (AC-16). In a
     * constant for the same reason as above, and it matters more here: the nine causes are spread
     * across two routes and four catch arms, so "same flash" maintained by hand would decay on the
     * first added branch and hand an attacker a way to sort "expired" from "never existed".
     *
     * It offers the way forward rather than reporting a dead end, because a person holding a broken
     * reset link has exactly one useful next action and the system knows what it is.
     */
    private const string INVALID_LINK_FLASH = 'That link is no longer valid. Enter your address and we will send a new one.';

    /**
     * Ask for a reset link.
     *
     * The most attacked endpoint in the system: anonymous, unauthenticated, causes mail to be sent to
     * a **third party**, and — unlike slice 2's resend form — has a destructive side effect on the
     * named account, because issuing a link invalidates that account's outstanding one (ADR-0011
     * decision 4). Every defence below is sized for that.
     */
    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        RequestPasswordResetHandler $requestPasswordReset,
        RateLimiterFactoryInterface $passwordResetRequestLimiter,
        LoggerInterface $logger,
    ): Response {
        // BEFORE THE CONTROLLER BODY DOES ANYTHING ELSE (AC-8). Not after the form is built, not
        // after validation, and certainly not inside the success branch: the point of a boundary
        // limiter is to be cheaper than the work it protects, and it protects against submissions
        // that are *individually valid*. A limiter that only counted successful requests would let
        // an attacker probe indefinitely with junk, and one that ran after `handleRequest()` would
        // still pay for parsing and validating every request in a flood.
        //
        // POST ONLY. A GET renders the empty form and costs nothing worth rationing; charging it a
        // token would mean a person who opened the page twice, or a browser that prefetched it, had
        // silently spent part of a five-per-hour budget before typing anything.
        if ($request->isMethod(Request::METHOD_POST)) {
            // Keyed on the client IP, which is the coarsest honest identifier available for an
            // anonymous endpoint — there is no session and no account to key on yet. Keying on the
            // **submitted address** is not merely worse, it is forbidden: a limiter that behaves
            // differently per address is an enumeration oracle in its own right, and would undo
            // through a side door the exact guarantee the neutral response below exists to provide.
            // `rate_limiter.yaml` argues that at length; the per-account rule lives in the Domain,
            // behind the neutral response, where its effect is real and invisible.
            //
            // The trade is accepted knowingly: everyone behind one corporate NAT shares five an
            // hour. At muzbar's scale that is a theoretical inconvenience, and it is not the only
            // defence — `PasswordResetRequest::MAX_ISSUES_PER_HOUR` is what protects one specific
            // person's inbox, and it is immune to the source IP.
            //
            // `getClientIp()` is nullable (there is no client in a CLI-driven kernel request), and
            // an empty key would silently pool every such request into one bucket. Naming the
            // fallback makes that bucket visible if it ever fills up. It is also the method whose
            // answer is the proxy's address until `framework.trusted_proxies` says otherwise — see
            // `rate_limiter.yaml`, and note that no test can catch a regression there.
            $limit = $passwordResetRequestLimiter->create($request->getClientIp() ?? 'unknown-client')->consume();

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
                throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Too many password-reset requests from this address. Please try again later.');
            }
        }

        $data = new ForgotPasswordFormData();
        $form = $this->createForm(ForgotPasswordFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $requestPasswordReset(new RequestPasswordReset((string) $data->email));
            } catch (UserNotFound|TooManyPasswordResetRequests|InvalidEmail $e) {
                // THE FOUR-WAY COLLAPSE (AC-5). This catch is the whole security property of the
                // endpoint, and it reads like sloppy error handling, so: every one of these is a
                // *normal outcome* of a public form, not a bug. Nobody registered with that
                // address; the account has already had three links this hour; the address passed
                // `Assert\Email(strict)` but not the value object's `FILTER_VALIDATE_EMAIL`. In all
                // three cases no mail is sent and nothing is written, and in all three the visitor
                // is told exactly what a visitor whose mail *was* sent is told.
                //
                // The response has to match to the byte, which is why the flash, the status and the
                // `Location` are produced by the code path below rather than duplicated in here.
                // Anything that varies is an oracle, and it does not take a message to leak state:
                // a different status, a different redirect target, a stray field error, even a
                // reliably different response time would be enough to sort a list of a million
                // addresses into "has an account here" and "does not". That list is the product an
                // attacker came for; the mail was never the point. (The timing difference is real
                // and is *bounded* rather than removed — AC-37, and it is what the five-per-hour
                // limiter above is buying.)
                //
                // Logged at INFO, deliberately not at warning or error. All three are ordinary human
                // behaviour — a typo, an impatient double-submit, an address at a provider whose
                // syntax we happen to refuse — and an endpoint whose normal use fills the error log
                // trains an operator to ignore the error log.
                //
                // **THE ADDRESS IS DELIBERATELY ABSENT AND THE EXCEPTION CLASS IS ALL THAT IS
                // WRITTEN.** The class name is the only part of this event that is *ours*: everything
                // else came off an anonymous form. Logging the address would write an attacker-chosen
                // string of unverified addresses into the log a line at a time — a log an operator
                // reads, a log shipper indexes and a support tool renders — which turns our own
                // logging into somebody else's storage, and, on the one endpoint whose whole design
                // is that it reveals nothing about who holds an account, into a copy of the
                // enumeration answer written down where the neutral response cannot protect it.
                // The class name is enough to see the distribution, which is what an operator
                // actually needs.
                $logger->info('A password-reset request produced no email.', ['reason' => $e::class]);
            }

            // ONE EXIT FOR ALL FOUR OUTCOMES — success and the three caught above (AC-6). Reached by
            // **falling out of the `try`**, not by a `return` inside it, so there is structurally one
            // response to keep identical rather than four to keep in step. Four `return`s that
            // happen to be identical today are four copies of a literal that can drift apart while
            // all four stay green; this shape makes the property hold by construction, which is the
            // only way it survives the next person adding a branch.
            $this->addFlash('success', self::NEUTRAL_REQUEST_FLASH);

            return $this->redirectToRoute('app_forgot_password_sent');
        }

        // 422 on a failed submit, matching `RegistrationController` and the resend form: a 200
        // carrying an error page is a lie that breaks Turbo, HTTP caches and anything else that
        // reads the status line. As `ForgotPasswordFormData` argues, distinguishing *this* branch
        // leaks nothing — the submitter already knows whether what they typed was an email address.
        return $this->render('identity/forgot_password.html.twig', [
            'forgotPasswordForm' => $form,
        ], new Response(null, $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK));
    }

    /**
     * The "check your inbox" page.
     *
     * Static and address-free (AC-36), which is what makes it safe as the universal destination of
     * every well-formed submission. A page that said "we sent a link to name@example.com" would have
     * to be told which address — putting it in the session or the query string — and a page that
     * varied at all between the four AC-5 cases would break the byte-identical guarantee at the last
     * possible moment.
     *
     * It also has to survive a **direct GET from somebody who never submitted anything**, since it is
     * an anonymous URL anyone can type: there is nothing here that could distinguish that visitor
     * from one who has just requested a link, because there is nothing dynamic here at all.
     */
    #[Route('/forgot-password/sent', name: 'app_forgot_password_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('identity/forgot_password_sent.html.twig');
    }

    /**
     * Open a reset link: judge the token, stash it, and get it out of the URL.
     *
     * **THIS GET MUTATES NOTHING, AND THAT IS INVERSION #3 vs `identity-email-verification`**
     * (AC-12, ADR-0011 decision 9). `EmailVerificationController::verify()` — the file next door —
     * redeems on the GET and is right to; this route deliberately does the opposite, so the reason
     * belongs here rather than being left to a reader who would otherwise conclude the redemption was
     * forgotten.
     *
     * Security appliances and mail clients fetch every URL in an incoming message before a human sees
     * it. Slice 2 can afford to let that prefetch burn its challenge, because *its* replay is a
     * friendly no-op: the scanner's fetch and the human's click compose to the same end state. Here a
     * replay must be **refused** (AC-18), because setting a password is destructive and repeatable —
     * so a GET that burnt the token would mean the scanner consumes the link and the actual user
     * opens a dead one, **every time**, on the one flow people reach when they are already locked
     * out. `CheckPasswordResetTokenHandler` therefore only judges; `reset()`'s POST is what burns.
     *
     * **`requirements` DELIBERATELY ACCEPTS ANY SINGLE PATH SEGMENT (`[^/]+`) AND DOES NO FORMAT
     * CHECKING** (AC-17). Do not add a `[A-Za-z0-9_-]{43}` regex here, however cheap an outer gate it
     * looks. Slice 2 shipped exactly that and had to remove it: a route requirement's failure mode is
     * a **bare 404** that no `catch` in this method can turn into anything useful, which makes the
     * single invalid-link response unsatisfiable for a malformed token and makes the
     * `InvalidResetToken` arm below **dead code**. The people who hit that case are precisely the ones
     * who need the form — mail clients hard-wrap long lines and people paste badly — and sending them
     * to a dead end instead of to the one page that fixes it is the whole failure. A duplicated rule
     * is not defence in depth when the outer copy has the wrong failure mode; it is the wrong answer,
     * delivered sooner.
     *
     * **AND NOTHING WILL TELL YOU IF SOMEBODY RE-ADDS IT**: every test in the suite uses a well-formed
     * token, so all of them still pass with the regex in place. `ResetToken::fromString()` is the only
     * format gate, it runs before any repository lookup, and a malformed token therefore costs one
     * `preg_match` and no database query — which is also what keeps "malformed" and "never issued"
     * indistinguishable by response time.
     *
     * GET, and only GET: a reset link is followed by clicking it in a mail client, so it cannot be
     * anything else. Normally a state-changing GET would be a design error — here it changes no state
     * at all, which is the point.
     */
    #[Route(
        '/reset-password/{token}',
        name: 'app_reset_password_check',
        requirements: ['token' => '[^/]+'],
        methods: ['GET'],
    )]
    public function check(
        string $token,
        Request $request,
        CheckPasswordResetTokenHandler $checkResetToken,
        RateLimiterFactoryInterface $passwordResetSubmitLimiter,
        LoggerInterface $logger,
    ): Response {
        // AC-19, and the justification is **not** token brute force — a 256-bit token is not
        // guessable, and ten attempts an hour buys an attacker exactly the same nothing as five.
        // What this rations is the endpoint: an anonymous route that can reach a database lookup
        // and, on the POST, a deliberately slow password hasher is a CPU-amplification primitive.
        // Ten per hour is chosen for the *legitimate* user, who does surprisingly many requests —
        // clicks the link, gets redirected, reloads because the page looked odd, mistypes the new
        // password, fails `NotCompromisedPassword`, tries again — plus whatever their mail client
        // prefetched before any of that.
        if (!($limit = $passwordResetSubmitLimiter->create($request->getClientIp() ?? 'unknown-client')->consume())->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Too many password-reset attempts from this address. Please try again later.');
        }

        $session = $request->getSession();

        try {
            $checkResetToken(new CheckPasswordResetToken($token));
        } catch (InvalidResetToken|PasswordResetRequestNotFound|PasswordResetLinkExpired|PasswordResetLinkAlreadyUsed|PasswordResetLinkInvalidated $e) {
            // The five ordinary failures, all of which happen to real people on a normal day: a
            // mangled URL, a token that was never issued, a link older than an hour, a link already
            // used, and a link superseded by a later request.
            //
            // That fifth one is the cost of ADR-0011 decision 4 landing on a real user, and it is
            // worth naming because it does not exist in the verification flow at all: somebody who
            // clicks "send me a link" twice and then opens the **first** mail is told their link is
            // dead. That is the price of not leaving a stolen link useful for its whole hour, and it
            // is why the per-user cap is three rather than verification's five.
            //
            // `PasswordResetLinkAlreadyUsed` is the replay, and it lands here in the *neutral* set
            // rather than in a friendly "already done" branch — **inversion #1 vs
            // `EmailVerificationController`**, which answers its replay with "already verified, please
            // sign in". The difference is not politeness: verification's effect is idempotent, so
            // saying "already done" costs nothing, whereas a used reset link that still reached a
            // password-setting form would be a live takeover surface. Telling the visitor which of
            // the five fired would also sort tokens into "existed" and "did not" for free.
            $logger->info('A password-reset link could not be opened.', ['reason' => $e::class]);

            return $this->invalidLink($session);
        } catch (PasswordResetTokenMismatch|StalePasswordResetRequest|UserNotFound $e) {
            // ERROR, because all three are supposed to be unreachable, and an unreachable state that
            // occurs is the most valuable thing in a log:
            //
            //   TokenMismatch — the repository looked the request up *by* this exact digest and the
            //     aggregate then found a different one. That is a repository returning a row it was
            //     not asked for: a broken DBAL type or a broken query.
            //   Stale — the request predates the user's last password change, which means the
            //     reissue sweep in `RequestPasswordResetHandler` was **lost**. This exception is the
            //     only signal that ever happens, and it is the entire reason I-23 exists as a second
            //     layer; a log that could not separate it from an expired link would waste it.
            //   UserNotFound — the request holds a `UserId` matching no row. There is no FK
            //     (ADR-0009 decision 4) and nothing deletes users today, so a dangling request means
            //     a user row was removed without cleaning this table up. Worth knowing before the
            //     GDPR-erasure conversation, which will have to sweep it.
            //
            // The visitor gets the same sentence as everyone else. They can act on none of it, and
            // telling them would leak that *something* exists behind that token.
            $logger->error('A password-reset link failed in a state that should be unreachable.', [
                'reason' => $e::class,
                'exception' => $e,
            ]);

            return $this->invalidLink($session);
        }

        // THE TOKEN LEAVES THE URL HERE (AC-13, ADR-0011 decision 8). It goes into the session and
        // the visitor is redirected to the token-less `/reset-password`, so the plaintext is in the
        // address bar, in browser history and in any outbound `Referer` for exactly **one redirect
        // hop** — and in no page the user then sits on and types into. Slice 2 got this for free
        // because its token's URL produced an instant 302 anyway; here the form page is a place
        // people linger, screenshot, and paste into support chats.
        //
        // Stored as the raw plaintext because that is what `ResetPasswordWithToken` needs and the
        // session lives in Redis, not in a cookie — the browser holds an opaque session id and never
        // the token itself.
        $session->set(self::SESSION_TOKEN_KEY, $token);

        return $this->noReferrer($this->redirectToRoute('app_reset_password'));
    }

    /**
     * Set the new password.
     *
     * Reached only by redirect from `check()`, and identified **only** by the session-stashed token —
     * there is no token in this route's path, no token in the form and no token in the rendered page
     * (AC-14). See `NewPasswordFormData`, where that absence is the design rather than an omission.
     *
     * This is the one place in the system where somebody who cannot prove they know the old password
     * can change it, so every failure below is deliberate about which of two buckets it lands in: a
     * **link** problem (the neutral invalid-link response, and the stash is destroyed) or a
     * **password** problem (422, and the stash survives).
     */
    #[Route('/reset-password', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        ResetPasswordWithTokenHandler $resetPasswordWithToken,
        RateLimiterFactoryInterface $passwordResetSubmitLimiter,
        LoggerInterface $logger,
    ): Response {
        $session = $request->getSession();
        $token = $session->get(self::SESSION_TOKEN_KEY);

        if (!\is_string($token)) {
            // AC-16 cases (h) and (i): this page reached with nothing stashed. Ordinary rather than
            // suspicious — a bookmarked `/reset-password`, a session that expired while the user
            // chose a password, a second browser tab, a Redis restart. The same response as every
            // other failure, because "you have no token" and "your token is dead" are the same
            // situation from the visitor's side and the same non-answer from ours.
            //
            // Checked **before** the limiter, deliberately: there is nothing here to protect. No
            // handler runs, no query is made and no hasher is reached, so charging a token would
            // spend a locked-out person's ten-per-hour budget on a page that did no work — and the
            // budget exists for the person who is about to mistype a password twice.
            $logger->info('A password-reset form was reached with no stashed token.', ['reason' => 'no-stashed-token']);

            return $this->invalidLink($session);
        }

        // POST only, for the reason `request()` gives: a GET renders the form and costs nothing
        // worth rationing, and charging it would silently spend part of the budget of somebody who
        // simply reloaded the page.
        if ($request->isMethod(Request::METHOD_POST)
            && !($limit = $passwordResetSubmitLimiter->create($request->getClientIp() ?? 'unknown-client')->consume())->isAccepted()
        ) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Too many password-reset attempts from this address. Please try again later.');
        }

        $data = new NewPasswordFormData();
        $form = $this->createForm(NewPasswordFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $resetPasswordWithToken(new ResetPasswordWithToken($token, (string) $data->plainPassword));

                // THE STASH IS DESTROYED ON SUCCESS. The token is spent — `redeem()` has run and a
                // replay is refused — so keeping it would only mean carrying a dead secret around in
                // a session for as long as the session lives.
                $session->remove(self::SESSION_TOKEN_KEY);

                $this->addFlash('success', 'Your password has been changed — please sign in with it.');

                // **NO AUTO-LOGIN** (AC-25), and this is a decision rather than an omission. Logging
                // the browser in here would make the reset flow an authentication path: the session
                // would be granted on the strength of a link that arrived by email, on a route with
                // no password check of its own, which is a second way into every account that exists
                // in parallel with the firewall. Sending the person to `/login` instead means the new
                // credential is exercised immediately, through the real authenticator, by the person
                // who chose it — which also tells them at once if they mistyped something they now
                // have to remember. Auto-login stays `identity-login-overlay`'s decision to make.
                //
                // Note what happens to *other* sessions: the provider rebuilds `SecurityUser` from
                // the database on every request and the framework compares the stored hash, so any
                // session authenticated as this user before the reset is deauthenticated on its very
                // next request (AC-24). That is emergent, not coded here — which is exactly why it is
                // asserted end to end rather than reasoned about.
                return $this->noReferrer($this->redirectToRoute('app_login'));
            } catch (WeakPassword $e) {
                // **THE SUBTLE ONE. `WeakPassword` IS NOT PART OF THE NEUTRAL INVALID-LINK SET, AND
                // MUST NOT BE** (AC-23). Every other exception this handler throws means *the link is
                // no good*; this one means **the link is fine and the password is not**. Folding it
                // in with the rest would destroy a perfectly valid token over a typo, and hand the
                // user — who is already locked out — a "that link is no longer valid" message for a
                // link they are holding, that works, and that they must now request again. A recovery
                // flow that spends somebody's one link on their own mistake is a support ticket by
                // construction.
                //
                // So: no `invalidLink()`, **no `$session->remove()`**, and the fall-through to the
                // 422 render below with the same stash still in place, so the next attempt uses the
                // same still-live token. Nothing was burnt, because `ResetPasswordWithTokenHandler`
                // builds `PlainPassword` *after* the token checks and *before* `redeem()` — the
                // ordering that also keeps an attacker with no valid token away from the hasher.
                //
                // Reached only through the character/byte asymmetry `NewPasswordFormData` documents:
                // the form's `Length` counts characters and the value object's max counts bytes, so
                // a multi-byte passphrase near 4 KB passes the form and is refused by the Domain.
                // `WeakPassword`'s messages name only the violated bound, never the password or its
                // length, so echoing them leaks nothing.
                $form->get('plainPassword')->addError(new FormError($e->getMessage()));
            } catch (InvalidResetToken|PasswordResetRequestNotFound|PasswordResetLinkExpired|PasswordResetLinkAlreadyUsed|PasswordResetLinkInvalidated|PasswordResetTokenMismatch|StalePasswordResetRequest|UserNotFound $e) {
                // Every *link* failure, folded into the one neutral response (AC-16). All eight are
                // re-checked here rather than trusted from `check()`, because that ran in a different
                // HTTP request — possibly minutes ago, possibly on behalf of a mail scanner — and
                // everything it established may since have stopped being true: a newer request may
                // have superseded this one, the hour may have elapsed, another reset may have
                // completed. `ResetPasswordWithTokenHandler` explains why a remembered check is not a
                // check.
                //
                // The stash is destroyed by `invalidLink()`. A token that can never work again is a
                // secret with no purpose, and leaving it would also mean this page kept offering a
                // form that could only ever fail.
                //
                // One log line at INFO for all eight, unlike `check()`'s two-tier split, because by
                // the time a POST reaches here the interesting distinctions have already been drawn
                // and logged on the GET that preceded it; the same token failing again a minute later
                // is a race, not a new fact.
                $logger->info('A password-reset submission could not be redeemed.', ['reason' => $e::class]);

                return $this->invalidLink($session);
            }
        }

        // 422 on a failed submit — a form error, a mismatch between the two boxes, a rejected CSRF
        // token, or the `WeakPassword` caught above — with the session stash **untouched** (AC-23).
        return $this->noReferrer($this->render('identity/reset_password.html.twig', [
            'newPasswordForm' => $form,
        ], new Response(null, $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK)));
    }

    /**
     * The one invalid-link response, shared by every failure both reset-link routes can produce.
     *
     * A private method rather than copied lines, because "same status, same `Location`, same flash"
     * across **nine causes and four catch arms** (AC-16) is a property that decays the moment it is
     * maintained by hand: the next person adds a branch, writes a very slightly different sentence,
     * and hands an attacker a way to sort "expired" from "never existed". Here there is one
     * implementation and nothing to keep in step.
     *
     * IT ALWAYS CLEARS THE STASH, INCLUDING ON THE `check()` PATH WHERE THERE IS USUALLY NOTHING TO
     * CLEAR. A stashed token that can never work again is a secret with no purpose, and the case
     * where it is not a no-op is the one that matters: somebody who has a live token stashed and then
     * opens a *second*, dead link would otherwise be redirected to a form that silently operates on
     * the older token — a page doing something other than what the visitor just asked for. One
     * unconditional `remove()` makes that unrepresentable rather than making it a branch somebody has
     * to get right.
     *
     * It redirects to `/forgot-password` rather than rendering an error page, because a dead end is a
     * support ticket: the visitor holds a broken link and the only useful thing the system can offer
     * is the way to get a working one.
     */
    private function invalidLink(SessionInterface $session): Response
    {
        $session->remove(self::SESSION_TOKEN_KEY);

        $this->addFlash('error', self::INVALID_LINK_FLASH);

        return $this->noReferrer($this->redirectToRoute('app_forgot_password'));
    }

    /**
     * `Referrer-Policy: no-referrer` on **every** response from both reset-link routes (AC-15) —
     * success and failure alike, because a header that appeared only on success would itself be the
     * signal that distinguishes the two responses the rest of this class works to make identical.
     *
     * The token travels in the URL path of `check()`, and the path is the part of a URL that leaks:
     * it lands in nginx access logs, in browser history, in any corporate proxy on the way, and in
     * the `Referer` header of requests the visited page goes on to make. This header addresses the
     * last of those. On a 302 it governs the redirected navigation, so `/reset-password` is reached
     * with no referrer carrying the token; on the rendered form it covers every link and asset
     * request that page makes.
     *
     * IT IS NOT THE MITIGATION, AND SAYING SO MATTERS. The real defences are the redirect that takes
     * the token out of the URL after one hop (ADR-0011 decision 8), the token being single-use, and
     * its one-hour life. This is the cheap belt-and-braces layer — one line, no cost, closes one
     * specific path. Treating it as the answer is exactly the kind of security theatre that lets a
     * real hole survive review.
     */
    private function noReferrer(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
