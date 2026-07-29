<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Form;

use App\Domain\Identity\ValueObject\Email;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The shape of what a browser is allowed to submit to `/forgot-password` — one address, and nothing
 * else.
 *
 * Mutable and nullable for the reason `RegistrationFormData` explains at length: the Form component
 * has to be able to build a half-filled, invalid object to re-render the page with the visitor's
 * input beside a field error, and an invalid `Email` value object is not allowed to exist. The
 * handler builds the value object; this class only has to be bindable.
 *
 * THE SHAPE IS THE ANTI-ENUMERATION DEFENCE, EXACTLY AS IT IS ON `ResendVerificationFormData`. There
 * is no `userId` field, no `token` field and no `redirect` field, so a crafted POST has nothing to
 * bind to; with `allow_extra_fields: false` on the form type, anything extra is rejected rather than
 * quietly dropped. What this endpoint *cannot be asked* is expressed as an absence rather than as a
 * check somewhere.
 *
 * The constraints duplicate rules `Email` also enforces, which is the pattern slice 1 established:
 * the form validates for **message quality** (a sentence next to the input), the value object
 * validates for **correctness** (the rule holds for every adapter, including the ones that do not
 * exist yet). The constant is quoted rather than retyped so the two cannot drift.
 *
 * WHY A FAILED VALIDATION MAY PRODUCE A VISIBLY DIFFERENT RESPONSE WITHOUT UNDOING AC-5. Failing
 * here gives a 422 with a field error, while succeeding gives the neutral 302 — which looks like it
 * reopens the enumeration oracle the controller exists to close. It does not, because the branch is
 * on **syntax**, which the submitter already knows: "that is not an email address" says nothing
 * about who holds an account. The indistinguishability that matters is between four *well-formed*
 * addresses with four different states behind them, and that lives one layer out, in the
 * controller's catch block.
 */
final class ForgotPasswordFormData
{
    /**
     * `Email(mode: strict)` runs egulias/email-validator's RFC-compliant parser rather than the
     * loose HTML5 pattern, matching slice 1's choice and keeping this form's judgement close to the
     * value object's. The two still disagree at the edges — strict mode accepts internationalised
     * addresses that `Email`'s `FILTER_VALIDATE_EMAIL` refuses — and that gap is handled rather than
     * ignored: `InvalidEmail` is one of the three exceptions the controller folds into the neutral
     * response, so an address in the gap gets the same answer as everything else instead of a 500.
     */
    #[Assert\NotBlank(message: 'Please enter an email address.')]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: Email::MAX_LENGTH)]
    public ?string $email = null;
}
