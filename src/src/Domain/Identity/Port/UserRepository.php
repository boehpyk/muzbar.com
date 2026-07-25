<?php

declare(strict_types=1);

namespace App\Domain\Identity\Port;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Exception\EmailAlreadyRegistered;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\UserId;

/**
 * The collection of Users, as the Domain wants to talk about it.
 *
 * One repository per aggregate root — there is no `RoleRepository`, because a role is reached
 * through the user that holds it. The interface is declared here and implemented in
 * `Infrastructure`: dependency inversion is what lets the Domain state its needs without knowing
 * that Postgres exists.
 */
interface UserRepository
{
    /**
     * Mints the identity for a User that does not exist yet.
     *
     * The repository generates the id — not the database — so a `User` is complete and valid the
     * moment it is constructed, before it has ever met a transaction. Nothing in the codebase can
     * then depend on a post-flush auto-increment, which means aggregates can raise events carrying
     * their own id, be passed around, or be discarded on a validation failure without ever having
     * touched the database.
     *
     * It also keeps UUID generation — a vendor concern (`symfony/uid`) — out of the Domain,
     * without the Domain having to hand-roll UUIDv7 out of `random_bytes`.
     */
    public function nextIdentity(): UserId;

    /**
     * Persists the aggregate, new or changed.
     *
     * The declared throw is worth dwelling on. Email uniqueness (invariant I-6) spans *all* users, so no
     * single aggregate can protect it — an aggregate only ever sees itself. The real guarantee is
     * therefore a database unique index, and the adapter's job is to catch that constraint
     * violation and translate it back into this domain exception. The Domain must never learn what
     * a SQL constraint is; it only knows the rule was broken. `RegisterUserHandler` also
     * pre-checks with `existsByEmail()`, which loses a race by design — the pre-check is for a
     * good error message, the index is for the truth.
     *
     * @throws EmailAlreadyRegistered when the stored email collides with an existing one
     */
    public function save(User $user): void;

    public function findById(UserId $id): ?User;

    public function findByEmail(Email $email): ?User;

    public function existsByEmail(Email $email): bool;
}
