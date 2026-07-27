<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\EmailVerificationRequestId;

/**
 * Invariant I-11: only the token whose digest matches may redeem the request.
 *
 * In the flow as wired today this is close to unreachable, because the repository finds the request
 * *by* its token hash — so the hash the handler passes to `redeem()` is the hash it looked up with.
 * That is exactly why the check, and this exception, must exist anyway: the lookup is a convenience
 * of one adapter, and the rule belongs to the aggregate. A second adapter, a lookup by user id, or a
 * console tool must not be able to redeem the wrong challenge simply by finding it a different way.
 *
 * The message names neither the presented digest nor the stored one. Comparing them is constant-time
 * inside `HashedVerificationToken::equals()` precisely so that no timing signal escapes; printing
 * either value into a log would hand back, in one line, the information that care was taken to
 * withhold.
 */
final class EmailVerificationTokenMismatch extends \DomainException
{
    public static function forRequest(EmailVerificationRequestId $id): self
    {
        return new self(\sprintf(
            'The presented token does not match email verification request "%s".',
            $id->toString(),
        ));
    }
}
