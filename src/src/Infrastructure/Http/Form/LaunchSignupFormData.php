<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One address someone wants a launch email sent to. Validation uses Symfony's default messages on
 * purpose: they ship translated (the `validators` domain), so a Russian visitor gets a Russian error
 * without us maintaining one.
 *
 * `max: 254` is RFC 5321's path limit — the same ceiling `Domain\Identity\ValueObject\Email` uses,
 * restated rather than imported because this stub deliberately owns no Domain code (see
 * `ComingSoonController`).
 */
final class LaunchSignupFormData
{
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: 254)]
    public ?string $email = null;
}
