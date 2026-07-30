<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Exception\StalePasswordResetRequest;

/**
 * Invariant I-23, written once: **a reset request issued strictly before its user's last password
 * change is not redeemable**, however alive it otherwise looks.
 *
 * WHY THIS LIVES IN THE APPLICATION LAYER AND NOT ON AN AGGREGATE. The rule spans **two aggregates**
 * — the challenge knows when it was issued, the account knows when its password last changed, and
 * neither may hold a reference to the other (ADR-0009 decision 4). There is therefore no object that
 * *could* enforce it: `PasswordResetRequest` has a `UserId`, not a `User`, and adding the `User`
 * would hand every holder of a challenge the power to mutate the account behind it. This is the same
 * asymmetry as email uniqueness (I-6) and the issuance cap (I-21) — a rule an aggregate can
 * *declare* but only a use case can *check*. Putting it in the layer that holds both objects is the
 * boundary working, not a workaround for it.
 *
 * WHY IT EXISTS AT ALL — IT IS THE SECOND LAYER (ADR-0011 decision 5). The reissue sweep in
 * `RequestPasswordResetHandler` (decision 4) is the primary mechanism: each new request invalidates
 * the account's outstanding ones, so a superseded link is already dead by the time anyone clicks it.
 * But **"benign" is not "impossible"**. A lost invalidation write, a crash halfway through the sweep,
 * or a request created concurrently with a reset leaves a sibling request that is still live and
 * still redeemable — an independent, repeatable chance to set a password on an account whose owner
 * believes they have just recovered it. This guard closes that window with one nullable column and
 * one comparison: **no cross-row write, no lock, and it holds even when the sweep is lost.** That is
 * what makes it worth four lines and what makes `User::passwordChangedAt` load-bearing rather than
 * audit garnish — without that column the invariant is simply unexpressible.
 *
 * WHY A TRAIT RATHER THAN TWO COPIES. Both `CheckPasswordResetTokenHandler` (the GET's judgement)
 * and `ResetPasswordWithTokenHandler` (the POST's redemption) must apply exactly this rule, and they
 * must apply *the same* one: the day the two copies drift is the day a link the GET called good is
 * refused by the POST, or — far worse — the reverse. **The comparison and its operator are written
 * here exactly once**, which is the entire requirement; the trait is merely the cheapest shape that
 * satisfies it without inventing a collaborator neither handler needs to inject.
 */
trait GuardsAgainstStaleResetRequests
{
    /**
     * THE COMPARISON IS STRICT (`<`), AND THE TIE GOES TO THE USER. Timestamps are stored to
     * whole-second precision (ADR-0007: `datetimetz_immutable` discards microseconds at the *type*),
     * so a request issued in the **same second** as a password change is a real occurrence rather
     * than a theoretical one — and with one `Clock` reading per use case it is exactly what a
     * legitimate reissue-then-reset inside one second looks like. A `<=` would refuse that user for
     * no security gain whatsoever, because the sweep has already handled every case where the two
     * events genuinely conflict. Erring toward letting the legitimate person through is right when
     * this is the *second* layer and not the primary one.
     *
     * A `null` `passwordChangedAt` means **no request can be stale**, and the early exit is a
     * definition rather than a convenience: an account whose password has never changed has nothing
     * for an outstanding request to be older *than*. That is the state of every account until its
     * first reset, so this branch is the common one.
     *
     * @throws StalePasswordResetRequest when the request predates the user's last password change
     */
    private function assertNotStale(PasswordResetRequest $request, User $user): void
    {
        $passwordChangedAt = $user->passwordChangedAt();

        if (null !== $passwordChangedAt && $request->issuedAt() < $passwordChangedAt) {
            throw StalePasswordResetRequest::forRequest($request->id(), $request->issuedAt(), $passwordChangedAt);
        }
    }
}
