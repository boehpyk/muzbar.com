<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The single enforcement point for ADR-0005's invariant I-5: *"a usable account has a verified email
 * or a linked verified OAuth identity."*.
 *
 * Slice 1 modelled that invariant as `User::isUsable()` and deliberately left nobody acting on it —
 * enforcing a rule before shipping the flow that lets a user satisfy it would have meant a happy
 * path only the box operator could reach. This class, plus one `user_checker:` line in
 * `security.yaml`, is the whole of the promised enforcement. Nothing in `Domain` or `Application`
 * changed to make it possible, which was the prediction ADR-0005's amendment made and is the reason
 * authentication policy was put in the Security layer in the first place.
 *
 * WHY A USER CHECKER RATHER THAN A ROLE OR A VOTER. The rejected alternative was to let an
 * unverified user hold a session and gate individual actions behind something like `ROLE_VERIFIED`.
 * It multiplies enforcement points: every route added between now and the end of the product is a
 * place to forget the annotation, and forgetting is silent. A checker has exactly one enforcement
 * point — authentication — so the rule is either on or off, and `security.yaml` says which.
 */
final class VerifiedAccountUserChecker implements UserCheckerInterface
{
    /**
     * EMPTY, AND THE EMPTINESS IS THE SECURITY CONTROL. Do not "fix" this method.
     *
     * Symfony calls `checkPreAuth()` from `UserCheckerListener::preCheckCredentials()`, which is
     * hooked to `CheckPassportEvent` — that is, **before the password is verified**. It runs as soon
     * as the user provider has found an account for the submitted identifier, and it runs whether or
     * not the person submitting it knows the password.
     *
     * So move the verification check up here and the login form becomes a free account-enumeration
     * oracle: an attacker posts an address with a junk password and reads the response. "Please
     * verify your email address before signing in." means *this address holds an unverified
     * account*; "Invalid credentials." means it does not. That is a database of real, recently
     * registered addresses — the exact inventory a credential-stuffing or phishing campaign wants —
     * handed out to anonymous requests, at the speed of the login throttle, by a check that looks
     * like it is making the system stricter. It also undoes `hide_user_not_found`, whose entire job
     * is to make "unknown email" and "wrong password" indistinguishable (slice 1's AC-13).
     *
     * The rule therefore lives in `checkPostAuth()` below, where the caller has already proven they
     * hold the password and is by definition entitled to know the state of that account (AC-21).
     *
     * The lesson generalises past this class: **where an authentication check runs decides who it
     * leaks to.** A correct rule at the wrong point in the sequence is a vulnerability, and it is
     * one that no test asserting "unverified users cannot log in" will ever catch — that test
     * passes either way. Only a test posting a *wrong* password against an unverified account tells
     * the two designs apart, which is why the acceptance criteria insist on one.
     */
    public function checkPreAuth(UserInterface $user): void
    {
    }

    /**
     * Runs on `AuthenticationSuccessEvent`, i.e. only once the correct password has been presented
     * and the authenticator is about to hand out a token. Throwing here aborts that: no session is
     * started, the firewall stashes the exception, and `SecurityController::login()` re-renders the
     * form with its message.
     *
     * `CustomUserMessageAccountStatusException` rather than the stock `DisabledException`/
     * `LockedException` because none of the framework's account-status exceptions means "unverified
     * email", and reusing one would put a message on screen that does not describe the situation.
     * Its message is passed through the translator by the login template like any other, and it is a
     * fixed string with no interpolation — there is nothing in it that could echo an address back
     * (AC-32).
     *
     * THE `instanceof` IS NOT DEFENSIVE PADDING. `UserCheckerInterface` is typed against
     * `UserInterface`, and the firewall is free to hand this checker any implementation — in-memory
     * users during a future test, an API-key user when Phase 3 lands, an OAuth user later in this
     * phase. `isUsable()` exists on `SecurityUser` and on nothing else, so the guard is what keeps a
     * second authenticator from fatal-erroring in production. Refusing to check an unknown user type
     * (rather than refusing to *authenticate* it) is the right default: this class's remit is the
     * accounts it understands, and a firewall that hands it something else has a configuration
     * problem that failing open here does not create and failing closed here would only obscure.
     *
     * THE SECOND PARAMETER IS FORWARD-COMPATIBILITY, NOT DECORATION. `UserCheckerInterface` in the
     * installed symfony/security-core declares the method as
     * `checkPostAuth(UserInterface $user /* , ?TokenInterface $token = null *\/): void` — the token
     * is commented out in the interface but documented in the `@param` above it, which is Symfony's
     * standard way of announcing a parameter that becomes mandatory in the next major. Declaring it
     * now (optional, so the current signature still matches) means this class needs no edit at
     * 8.0 and no deprecation fires in the meantime. It is unused today by design: the decision
     * depends on the account, not on the token being minted for it.
     */
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof SecurityUser && !$user->isUsable()) {
            throw new CustomUserMessageAccountStatusException('Please verify your email address before signing in.');
        }
    }
}
