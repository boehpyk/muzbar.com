<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Exception\EmailAlreadyRegistered;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\UserId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Uid\Uuid;

/**
 * The Doctrine adapter behind the `UserRepository` port.
 *
 * Everything Postgres-shaped stops here. The Domain states what it needs (mint an id, save,
 * find, check existence) and this class is the only place in the codebase allowed to know that
 * "check existence" means `SELECT COUNT(*)` and that "already registered" arrives as SQLSTATE
 * 23505.
 *
 * It deliberately does **not** extend `ServiceEntityRepository`. Inheriting from Doctrine would
 * make the adapter's public surface the union of the port and Doctrine's fifty-odd methods, and
 * every one of those extra methods is an invitation for a caller to bypass the aggregate. The
 * `EntityManagerInterface` is a collaborator, not a parent.
 */
final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * UUIDv7, not v4 — and the reason is index physics, not fashion.
     *
     * A v7 UUID leads with a millisecond timestamp, so successive ids sort in creation order.
     * Inserts therefore land in the right-hand edge of the primary key's B-tree, touching a
     * handful of hot pages that are already in Postgres' buffer cache. Random v4 ids scatter
     * across the whole tree instead: every insert dirties a different page, the cache hit rate
     * collapses as the table grows, and the index bloats through page splits that leave each
     * page half empty. On a single VDS with finite RAM that difference is not academic.
     *
     * The Domain does not know any of this. `UserId` validates the RFC 4122 *layout* and stays
     * version-agnostic on purpose, so this performance choice lives exactly where it belongs —
     * in the adapter that owns the database.
     */
    public function nextIdentity(): UserId
    {
        return UserId::fromString(Uuid::v7()->toRfc4122());
    }

    /**
     * Persist-and-flush is inside the adapter, so the Application handler owns no Doctrine
     * concept and one command remains one transaction.
     *
     * THE TRANSLATION IS THE POINT. Invariant I-6 — email uniqueness — spans every user, so the
     * only honest enforcement is the `uniq_identity_user_email` index. When it fires, DBAL raises
     * a `UniqueConstraintViolationException`, which is a sentence about SQLSTATE 23505. The
     * Domain must never learn that sentence; it only knows the rule was broken. Catching here and
     * rethrowing the domain exception is precisely what "adapter" means.
     *
     * The handler also pre-checks with `existsByEmail()`. That pre-check loses a race by design:
     * it exists to produce a good error message in the common case, while this catch produces the
     * identical error in the rare one, so the racing user and the ordinary user see the same page
     * (AC-9).
     */
    public function save(User $user): void
    {
        $this->entityManager->persist($user);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // The DBAL exception is passed as the cause so the operator keeps the SQLSTATE and
            // the offending statement in the log, while the caller only ever sees the domain
            // exception.
            //
            // Note that the EntityManager is closed once a flush fails — Doctrine cannot know
            // which of the pending changes survived. That is correct and expected here: this
            // exception ends the request, and the caller re-renders a form rather than continuing
            // to persist.
            throw EmailAlreadyRegistered::withEmail($user->email(), $e);
        }
    }

    /**
     * `find()` rather than a query, so a User already loaded in this request comes back from the
     * identity map without a round trip — and, more importantly, comes back as the *same object*,
     * which is what keeps two references to one aggregate from diverging mid-transaction.
     */
    public function findById(UserId $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }

    /**
     * Hits `uniq_identity_user_email`. The `Email` value object is bound as a parameter and
     * `EmailType` normalises it, so a mixed-case address finds the lower-cased row without any
     * `LOWER()` in the SQL — meaning the index is still usable, which a functional index would
     * otherwise be needed for.
     */
    public function findByEmail(Email $email): ?User
    {
        return $this->users()->findOneBy(['email' => $email]);
    }

    /**
     * `count()` issues `SELECT COUNT(*) … WHERE email = ?` — an index-only lookup that hydrates
     * nothing. Answering this with `findByEmail() !== null` would build a whole `User`, run four
     * DBAL type conversions and register the aggregate in the identity map, all to discard it and
     * keep a boolean. Registration would then be paying for an object it never uses on its
     * happy path.
     */
    public function existsByEmail(Email $email): bool
    {
        return $this->users()->count(['email' => $email]) > 0;
    }

    /**
     * @return EntityRepository<User>
     */
    private function users(): EntityRepository
    {
        return $this->entityManager->getRepository(User::class);
    }
}
