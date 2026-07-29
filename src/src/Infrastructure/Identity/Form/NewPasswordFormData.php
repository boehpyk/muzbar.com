<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Form;

use App\Domain\Identity\ValueObject\PlainPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The shape of what a browser is allowed to submit to `POST /reset-password` — one password, twice,
 * and nothing else.
 *
 * Mutable and nullable for the reason `RegistrationFormData` explains: the Form component has to be
 * able to build a half-filled, invalid object in order to re-render the page with a field error, and
 * a value object that refuses to be invalid cannot do that.
 *
 * **THERE IS NO `token` PROPERTY, AND THAT ABSENCE IS AC-14** — the strongest of the three "shape is
 * the defence" arguments in this context, after `RegistrationFormData`'s missing `roles` and
 * `ResendVerificationFormData`'s missing `userId`. The token comes from the session stash that
 * `PasswordResetController::check()` wrote (ADR-0011 decision 8); it is never rendered, never posted
 * and never bindable. Combined with `allow_extra_fields: false` on the form type, a POST carrying
 * `new_password_form[token]` is **rejected**, not quietly ignored — so there is no version of this
 * endpoint in which a submitted token could be preferred over, or confused with, the stashed one.
 * A hidden field would have put a live account-takeover credential into the HTML of a page the user
 * sits on, which is precisely what the redirect exists to prevent; a property here would have made
 * that hidden field a one-line change away.
 *
 * There is likewise no `email` property. The account is identified by the token and by nothing else,
 * for the reason `ResetPasswordWithToken` states: a second, weaker way to name the target raises an
 * immediate question about what happens when the two disagree, and every answer to that question is
 * worse than not asking it.
 *
 * **NO NEW PASSWORD POLICY IS INTRODUCED HERE** (AC-22). The constraints below are the ones
 * `RegistrationFormData` already carries, with the bounds quoted from `PlainPassword` rather than
 * retyped. Two places that define "strong enough" are two places that drift, and the one that drifts
 * loose is the one people use when they are locked out and in a hurry.
 */
final class NewPasswordFormData
{
    /**
     * `Length::$max` counts *characters* while `PlainPassword::MAX_LENGTH` is a *byte* ceiling
     * imposed by Symfony's `NativePasswordHasher`. The two therefore diverge for multi-byte input
     * near 4 KB — a passphrase of 4096 emoji passes here and is refused by the value object. That is
     * handled rather than ignored: `PasswordResetController::reset()` catches `WeakPassword` and
     * puts it back on this field, **without burning the token** (AC-23). Expressing the byte limit
     * here instead would break `min`, which must stay a character count so a Cyrillic passphrase is
     * judged by what the human typed.
     *
     * `NotCompromisedPassword` calls Have I Been Pwned's k-anonymity range API — only a
     * five-character hash prefix leaves the server, never the password. `skipOnError: true` does not
     * mean "skip if another constraint already failed"; it means **skip if the HIBP request itself
     * errors**, so a third-party outage lets a locked-out user finish recovering rather than
     * becoming a muzbar outage. On *this* form that fallback matters more than it does on
     * registration: a stranger who cannot register comes back tomorrow, whereas somebody who cannot
     * complete a reset cannot get into their account at all. The length policy still applies in that
     * case, and `validator.yaml` disables the constraint entirely under `APP_ENV=test`, which is
     * what keeps the suite offline and fast.
     */
    #[Assert\NotBlank(message: 'Please enter a password.')]
    #[Assert\Length(min: PlainPassword::MIN_LENGTH, max: PlainPassword::MAX_LENGTH)]
    #[Assert\NotCompromisedPassword(
        message: 'This password has been leaked in a data breach, please choose another.',
        skipOnError: true,
    )]
    public ?string $plainPassword = null;
}
