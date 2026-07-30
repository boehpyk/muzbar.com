<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\PasswordResetRequestId;

/**
 * Invariant I-19: only the token whose digest matches may redeem the request.
 *
 * In the flow as wired today this is close to unreachable, because the repository finds the request
 * *by* its token hash — so the digest the handler passes to `assertRedeemableWith()` is the digest it
 * looked up with. That is exactly why the check, and this exception, must exist anyway: **the lookup
 * is a convenience of one adapter, and the rule belongs to the aggregate.** A second adapter, a
 * lookup by user id, a fixture or a console tool must not be able to redeem the wrong challenge
 * simply by finding it through a different door.
 *
 * It is also the **last** of the four checks in `assertRedeemableWith()`, deliberately: it is the
 * only one that touches a secret, and there is no reason to perform a cryptographic comparison for a
 * request that was superseded, spent or expired anyway.
 *
 * The message names neither the presented digest nor the stored one. The comparison is constant-time
 * inside `HashedResetToken::equals()` precisely so that no timing signal escapes; printing either
 * value into a log would hand back in one line the information that care was taken to withhold — and
 * on this flow what stands behind that digest is the account itself.
 */
final class PasswordResetTokenMismatch extends \DomainException
{
    public static function forRequest(PasswordResetRequestId $id): self
    {
        return new self(\sprintf(
            'The presented token does not match password reset request "%s".',
            $id->toString(),
        ));
    }
}
