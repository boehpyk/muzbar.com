<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Form;

use App\Domain\Identity\ValueObject\Email;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The shape of what a browser is allowed to submit to `/verify-email/resend` — one address, and
 * nothing else.
 *
 * Mutable and nullable for the reason `RegistrationFormData` explains at length: the Form component
 * has to be able to build a half-filled, invalid object to re-render the page with the visitor's
 * input beside a field error, and an invalid `Email` value object is not allowed to exist. The
 * handler builds the value object; this class only has to be bindable.
 *
 * THE SHAPE IS THE ANTI-ENUMERATION DEFENCE, THE SAME WAY IT WAS THE ANTI-ESCALATION DEFENCE ON
 * REGISTRATION. There is no `userId` field, no `token` field and no `redirect` field, so there is
 * nothing for a crafted POST to bind to; combined with `allow_extra_fields: false` on the form type,
 * anything extra is rejected rather than quietly dropped. The interesting property of this endpoint
 * is what it *cannot* be asked, and that is expressed here as an absence rather than as a check
 * somewhere.
 *
 * The constraints duplicate rules `Email` also enforces, which is deliberate and is slice 1's
 * pattern: the form validates for **message quality** (a sentence next to the input), the value
 * object validates for **correctness** (the rule holds for every adapter, including the ones that
 * do not exist yet). The constants are quoted rather than retyped so the two cannot drift.
 *
 * A NOTE ON WHAT VALIDATION IS AND IS NOT DOING HERE, because it is genuinely counter-intuitive on
 * this particular form: failing validation produces a *different* response (422 with a field error)
 * from succeeding (302 with the neutral flash), which looks like it undoes AC-15's
 * indistinguishability. It does not, because the branch is on **syntax**, which the submitter
 * already knows — "that is not an email address" reveals nothing about who holds an account. The
 * indistinguishability that matters is between four *well-formed* addresses with four different
 * states behind them, and that lives one layer out, in the controller's catch block. Distinguishing
 * malformed input is a usability win with no information leak; distinguishing account state is a
 * leak with no usability win.
 */
final class ResendVerificationFormData
{
    /**
     * `Email(mode: strict)` runs egulias/email-validator's RFC-compliant parser rather than the
     * loose HTML5 pattern, which is what slice 1 chose and what keeps this form's judgement close to
     * the value object's. The two still disagree at the edges — strict mode accepts
     * internationalised addresses that `Email`'s `FILTER_VALIDATE_EMAIL` refuses — and that gap is
     * handled rather than ignored: `InvalidEmail` is one of the four exceptions the controller
     * folds into the neutral response, so an address in the gap gets the same answer as everything
     * else instead of a 500.
     */
    #[Assert\NotBlank(message: 'Please enter an email address.')]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: Email::MAX_LENGTH)]
    public ?string $email = null;
}
