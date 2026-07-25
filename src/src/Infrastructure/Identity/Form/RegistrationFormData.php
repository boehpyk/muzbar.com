<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Form;

use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\PlainPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The shape of what a browser is allowed to submit to `/register` — and nothing more.
 *
 * MUTABLE AND NULLABLE, ON PURPOSE. Everything else in this slice is `final readonly` with
 * validation in the constructor, and this class is the deliberate exception: Symfony's Form
 * component has to be able to build a half-filled, invalid object in order to re-render the page
 * with the user's input and a field error next to it. A value object cannot do that — an invalid
 * `Email` is not allowed to exist — which is precisely why the form binds to a DTO and the *handler*
 * builds the value objects.
 *
 * The constraints below duplicate rules that `Email` and `PlainPassword` also enforce. That
 * duplication is the design, not a smell: the form validates for **message quality** (a sentence
 * next to the right input), the value object validates for **correctness** (the rule holds for the
 * console command and every future adapter too). The constants are quoted rather than retyped so
 * the two layers cannot drift apart silently.
 *
 * THE ROLE INJECTION DEFENCE (AC-20) IS THIS CLASS'S SHAPE. There is no `roles` property, so
 * `roles[]=ROLE_ADMIN` has nothing to bind to; combined with `allow_extra_fields: false` on the form
 * type, the request is rejected rather than silently ignored. Privilege escalation is not prevented
 * by a check somewhere — it is unrepresentable.
 */
final class RegistrationFormData
{
    #[Assert\NotBlank(message: 'Please enter an email address.')]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: Email::MAX_LENGTH)]
    public ?string $email = null;

    /**
     * `Length::$max` counts *characters* while `PlainPassword::MAX_LENGTH` is a *byte* ceiling
     * imposed by Symfony's `NativePasswordHasher`. The two therefore diverge for multi-byte input
     * near 4 KB — a passphrase of 4096 emoji passes here and is refused by the value object. That
     * is handled, not ignored: `RegistrationController` catches `WeakPassword` and puts it back on
     * this field. Expressing the byte limit here instead would break `min`, which must stay a
     * character count so a Cyrillic passphrase is judged by what the human typed.
     *
     * `NotCompromisedPassword` calls Have I Been Pwned's k-anonymity range API — only a five-character
     * hash prefix leaves the server, never the password. `skipOnError: true` does not mean "skip if
     * another constraint already failed"; it means **skip if the HIBP request itself errors**, so a
     * third-party outage lets registration proceed rather than becoming a muzbar outage. The length
     * policy still applies in that case. `validator.yaml` disables the constraint entirely under
     * `APP_ENV=test`, which is what keeps the suite offline and fast.
     */
    #[Assert\NotBlank(message: 'Please enter a password.')]
    #[Assert\Length(min: PlainPassword::MIN_LENGTH, max: PlainPassword::MAX_LENGTH)]
    #[Assert\NotCompromisedPassword(
        message: 'This password has been leaked in a data breach, please choose another.',
        skipOnError: true,
    )]
    public ?string $plainPassword = null;
}
