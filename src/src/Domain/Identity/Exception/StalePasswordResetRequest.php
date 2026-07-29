<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\PasswordResetRequestId;

/**
 * Invariant I-23: a request issued **strictly before** its user's last password change is not
 * redeemable, however alive it otherwise looks.
 *
 * WHY IT IS NOT AN AGGREGATE INVARIANT, AND WHY THAT IS NOT A COMPROMISE. The rule spans **two
 * aggregates** — the challenge knows when it was issued, the account knows when its password last
 * changed, and neither may hold a reference to the other (ADR-0009 decision 4). So there is no
 * object that could enforce it, and the enforcement lives in `ResetPasswordWithTokenHandler` and
 * `CheckPasswordResetTokenHandler`, comparing `$request->issuedAt() < $user->passwordChangedAt()`.
 * This is the same asymmetry as email uniqueness (I-6) and the issuance cap (I-21): a rule an
 * aggregate can *declare* but only a use case can *check*.
 *
 * WHY IT EXISTS AT ALL — THE SECOND LAYER (ADR-0011 decision 5). The reissue sweep (decision 4) is
 * the primary mechanism: each new request invalidates the account's outstanding ones, so a
 * superseded link is already dead. But **"benign" is not "impossible"**. A lost invalidation write, a
 * crash halfway through the sweep, or a request created concurrently with a reset leaves a sibling
 * request that is still live and still redeemable — an independent, repeatable chance to set a
 * password on an account whose owner believes they have just recovered it. This guard closes that
 * window with one nullable column and one comparison: **no cross-row write, no lock, and it holds
 * even when the sweep is lost.** That is what makes `User::passwordChangedAt` load-bearing rather
 * than audit garnish — without it, this invariant is simply unexpressible.
 *
 * THE COMPARISON IS STRICT (`<`), AND THE TIE GOES TO THE USER. Timestamps are stored to whole-second
 * precision (ADR-0007), so a request issued in the *same second* as a password change is a real
 * occurrence rather than a theoretical one — and with one `Clock` reading per use case it is exactly
 * what a legitimate reissue-then-reset within the same second looks like. A `<=` would refuse that
 * user for no security gain, because the sweep has already handled every case where the two events
 * genuinely conflict. Erring toward letting the legitimate user through is right when this is the
 * *second* layer and not the primary one.
 *
 * The message names the request id and the two instants — server-side handles that grant nobody
 * anything — and never a token (AC-10). To the visitor it is one more cause folded into the single
 * neutral invalid-link response (AC-16); it stays a distinct class because when this one fires it is
 * genuinely interesting, and a log that could not tell it apart from an expired link would waste the
 * only signal that the sweep failed.
 */
final class StalePasswordResetRequest extends \DomainException
{
    public static function forRequest(
        PasswordResetRequestId $id,
        \DateTimeImmutable $issuedAt,
        \DateTimeImmutable $passwordChangedAt,
    ): self {
        return new self(\sprintf(
            'Password reset request "%s" was issued at %s, before the password was last changed at %s.',
            $id->toString(),
            $issuedAt->format(\DateTimeInterface::ATOM),
            $passwordChangedAt->format(\DateTimeInterface::ATOM),
        ));
    }
}
