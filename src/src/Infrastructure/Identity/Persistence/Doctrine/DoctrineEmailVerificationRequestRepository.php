<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Infrastructure\Identity\Persistence\Doctrine\Type\UserIdType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Uid\Uuid;

/**
 * The Doctrine adapter behind the `EmailVerificationRequestRepository` port.
 *
 * The context's second adapter, and structurally the same animal as
 * `DoctrineUserRepository`: constructor-injected `EntityManagerInterface`, and **not** a
 * `ServiceEntityRepository` subclass (ADR-0007 decision 7). Inheriting from Doctrine would make the
 * adapter's public surface the union of the port and Doctrine's fifty-odd methods, every one of
 * them an invitation to bypass the aggregate. The entity manager is a collaborator, not a parent.
 *
 * Read this class next to `DoctrineUserRepository` — the interesting parts are where the two
 * deliberately differ, and each of those differences is marked below.
 */
final readonly class DoctrineEmailVerificationRequestRepository implements EmailVerificationRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * UUIDv7 for the same index-physics reason `DoctrineUserRepository::nextIdentity()` spells out
     * at length: v7 leads with a millisecond timestamp, so inserts land on the right-hand edge of
     * the primary key's B-tree instead of scattering v4-style across every page of it. That
     * argument is not repeated here; it is identical, and identical reasoning duplicated is
     * reasoning that will eventually disagree with itself.
     *
     * What *is* worth adding is that the argument bites harder on this table than on
     * `identity_user`. A user row is written once ever; a verification request is written on every
     * registration and every resend, and the pruning job owed later will delete from the same
     * index. Time-ordered keys mean those deletions clear whole left-hand pages rather than
     * punching holes through the middle of the tree.
     *
     * As there, the Domain knows none of this: `EmailVerificationRequestId` validates the RFC 4122
     * layout and stays version-agnostic, so the choice of v7 lives in the adapter that owns the
     * database.
     */
    public function nextIdentity(): EmailVerificationRequestId
    {
        return EmailVerificationRequestId::fromString(Uuid::v7()->toRfc4122());
    }

    /**
     * Persist-and-flush, and **nothing else** — no `try`, no `catch`, no translation.
     *
     * THIS IS THE DELIBERATE DIFFERENCE FROM `DoctrineUserRepository::save()`, which is obliged to
     * catch `UniqueConstraintViolationException` on `uniq_identity_user_email` and rethrow
     * `EmailAlreadyRegistered`. There, translation is the adapter's whole job: two people racing to
     * register the same address is an ordinary Tuesday, invariant I-6 can only be enforced by the
     * index, and "that address is taken" is a real business answer that a form can render.
     *
     * Here the only unique index is `uniq_identity_email_verification_request_token_hash`, and the
     * port states the case exactly: a collision would mean *"two independent draws of 256 CSPRNG
     * bits landed on the same value. There is no business answer to that, because it is not a
     * business event; the honest response is a 500 and an alert."* Wrapping it would dress an
     * impossibility up as a handled case and leave a `catch` block in the handler that no test can
     * reach and no reader can evaluate — a failure contract should describe things that happen.
     *
     * The generalisable rule, which is the reason both adapters are worth reading together: an
     * adapter translates a database error when the rule it enforces is a *domain* rule the database
     * merely happens to be holding. It stays out of the way when the error means the world is
     * broken. Uniqueness on an email is the first; uniqueness on a 256-bit random digest is the
     * second.
     */
    public function save(EmailVerificationRequest $request): void
    {
        $this->entityManager->persist($request);
        $this->entityManager->flush();
    }

    /**
     * One indexed lookup on `uniq_identity_email_verification_request_token_hash` — the only hot
     * query on this table, run once per click on a verification link.
     *
     * **It filters on the digest and on nothing else**, which is the port's contract and worth not
     * quietly "improving". Adding `AND redeemed_at IS NULL AND expires_at > now()` would look
     * tidier and would be a real bug: it collapses "this link expired yesterday", "this link was
     * already used" and "this token was never issued" into one indistinguishable `null`, exactly
     * when the system most wants to tell them apart — in a log, in a test, and in deciding whether
     * a redeemed row is a benign replay (AC-8) or corruption. That the *visitor* sees one identical
     * response for all of them (AC-11) is a presentation policy chosen to defeat enumeration, and
     * presentation policy is no reason to throw the information away three layers down.
     *
     * Business state is judged by the aggregate — `redeem()`, `isExpiredAt()` — because a
     * repository that judges business state is an aggregate with a SQL accent.
     *
     * `findOneBy()` binds the `HashedVerificationToken` itself; Doctrine resolves the parameter's
     * type from the field mapping and hands the value object to
     * `HashedVerificationTokenType::convertToDatabaseValue()`. That is why that type can refuse
     * bare strings — see its docblock, which is the one place these types disagree with each other
     * on purpose.
     */
    public function findByTokenHash(HashedVerificationToken $hash): ?EmailVerificationRequest
    {
        return $this->requests()->findOneBy(['tokenHash' => $hash]);
    }

    /**
     * The anti-abuse count (I-12), served by `idx_identity_email_verification_request_user_issued`
     * — equality on the leading `user_id`, then a range on `issued_at`, which is the column order
     * that index was built in and the reason it was named explicitly (AC-37 asserts the plan with
     * `EXPLAIN`).
     *
     * DQL rather than `EntityRepository::count()` because the criteria include a range, and
     * `COUNT` rather than counting hydrated objects because the answer is one integer: hydrating
     * five aggregates, running four DBAL conversions each, to compare a number against
     * `MAX_ISSUES_PER_HOUR` would be paying for objects the handler never looks at.
     *
     * BOTH PARAMETER TYPES ARE PASSED EXPLICITLY, AND BOTH WOULD BE WRONG BY DEFAULT. Doctrine
     * infers an untyped parameter through `ParameterTypeInferer`, which knows nothing about this
     * project's custom types:
     *
     * - a `UserId` object is not one of the shapes it recognises, so it falls through to
     *   `ParameterType::STRING` and the object reaches DBAL to be cast — which happens to work only
     *   because `UserId` implements `__toString()`. Working by accident through a magic method is
     *   not the same as working, and it would stop the day a value object drops `__toString()`.
     * - a `\DateTimeImmutable` infers `datetime_immutable`, the **naive** type, which formats
     *   `'Y-m-d H:i:s'` with no UTC offset at all. Postgres would then reinterpret the instant in
     *   the session's timezone while the stored column is `TIMESTAMP WITH TIME ZONE` — a silent
     *   window shift that is invisible on a UTC box and wrong on any other. `datetimetz_immutable`
     *   is the type the column actually uses, so it is the type the comparison must use.
     *
     * The result is cast rather than trusted: `COUNT` comes back from the PostgreSQL driver as a
     * `bigint`, which PDO surfaces as a *string*, so the cast is what makes the `int` return type
     * honest instead of a lie PHP would paper over on the way out.
     */
    public function countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int
    {
        $count = $this->entityManager
            ->createQuery(
                <<<'DQL'
                    SELECT COUNT(request.id)
                    FROM App\Domain\Identity\Entity\EmailVerificationRequest request
                    WHERE request.userId = :userId
                      AND request.issuedAt >= :since
                    DQL
            )
            ->setParameter('userId', $userId, UserIdType::NAME)
            ->setParameter('since', $since, Types::DATETIMETZ_IMMUTABLE)
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * The pruning backlog for this table, added by `identity-challenge-pruning` together with
     * `deleteExpiredBefore()` below — the two methods that discharge this port's long-standing
     * *"the pruning job owed later will add its own method, with its own justification"* note.
     *
     * `COUNT` rather than a hydrating query, for `countIssuedForUserSince()`'s reason one rung
     * louder: the answer is one integer and the rows it counts are rows whose entire purpose is to
     * disappear. Building aggregates out of them, six DBAL conversions apiece, to produce a number
     * for a log line would be paying for objects nobody will ever look at.
     *
     * `:threshold` IS BOUND WITH `Types::DATETIMETZ_IMMUTABLE` EXPLICITLY, AND THAT IS THE ONE LINE
     * IN THIS METHOD THAT CAN BE SILENTLY WRONG. Doctrine's `ParameterTypeInferer` would look at a
     * bare `\DateTimeImmutable` and pick `datetime_immutable`, the **naive** type, which formats
     * `'Y-m-d H:i:s'` with no UTC offset at all against a column that is
     * `TIMESTAMP(0) WITH TIME ZONE`. Postgres then reinterprets the instant in the session's
     * timezone, and the retention window shifts by whatever that offset happens to be — invisible on
     * a UTC box, wrong on any other, and wrong in a direction that deletes rows the policy meant to
     * keep. `countIssuedForUserSince()` carries the same note about the same trap; this is the
     * second and third places it applies, and the reasoning is copied across deliberately rather
     * than referred to, because a comparison that silently changes meaning with `TZ` is worth
     * spelling out at every call site that could acquire it.
     *
     * **The `<` is strict, and it matches `deleteExpiredBefore()` exactly.** That agreement is the
     * contract, not a coincidence: if this method counted with `<=` and the sweep deleted with `<`,
     * a row sitting exactly on the threshold would be counted forever and removed never, and a
     * perfectly healthy table would report a permanent backlog of one — an alarm nobody could clear
     * and everybody would learn to ignore.
     *
     * **The predicate is `expires_at` and nothing else.** No `redeemed_at`, no join to
     * `identity_user` for its `email_verified_at`, no notion of "dead" — see the port's docblock and
     * ADR-0012 decision 1 for why a "dead" predicate would be the rejected shared base class rebuilt
     * in SQL, where no unit test can see it.
     *
     * The result is cast rather than trusted: `COUNT` arrives from the PostgreSQL driver as a
     * `bigint`, which PDO surfaces as a *string*, so the cast is what makes the `int` return type
     * honest instead of a lie PHP would paper over on the way out.
     */
    public function countExpiredBefore(\DateTimeImmutable $threshold): int
    {
        $count = $this->entityManager
            ->createQuery(
                <<<'DQL'
                    SELECT COUNT(request.id)
                    FROM App\Domain\Identity\Entity\EmailVerificationRequest request
                    WHERE request.expiresAt < :threshold
                    DQL
            )
            ->setParameter('threshold', $threshold, Types::DATETIMETZ_IMMUTABLE)
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * One batch of the sweep: at most `$limit` rows whose `expires_at` is strictly before
     * `$threshold`, deleted in a single statement, returning how many actually went.
     *
     * WHY THIS IS NATIVE SQL WHEN EVERY OTHER QUERY IN THIS CLASS IS DQL. Not preference, and not
     * speed — capability. DQL's `DELETE` supports neither a `LIMIT` nor a subquery inside its
     * `WHERE ... IN`, so the batching the use case requires is **not expressible in it at all**.
     * This is Infrastructure doing the job it exists for: the Domain stated the selection (a
     * threshold, a strict `<`) and the Application stated the workload (batches of a thousand), and
     * the adapter picks whichever tool of its own can honour both. Table and column names are spelled
     * out literally rather than derived, which is ADR-0007 decision 6 applied where it bites hardest
     * — this is the first statement in the repository's history that *removes* rows, and a name
     * guessed by a naming strategy is not a name you want a `DELETE` aimed at.
     *
     * `ORDER BY expires_at` INSIDE THE SUBQUERY IS LOAD-BEARING, NOT DECORATION. It makes every batch
     * the **oldest remaining rows**, which buys two things a reader would otherwise strip out as
     * pointless work in a statement that discards its own result. First, monotone progress: a run cut
     * short by the per-run cap has demonstrably cleared the front of the queue, so the next run picks
     * up behind it and the backlog falls run over run. Without the ordering, batches would sample the
     * overdue set arbitrarily and a capped run's progress would be unpredictable — the backlog could
     * fall by a thousand and the oldest row still be there a week later. Second, physics:
     * `nextIdentity()` above already predicted this method, in these words — *"the pruning job owed
     * later will delete from the same index. Time-ordered keys mean those deletions clear whole
     * left-hand pages rather than punching holes through the middle of the tree."* Sweeping oldest
     * first is what makes that prediction come true, because `expires_at` order and UUIDv7 order are
     * the same order; the range scan walks the left edge of the `expires_at` B-tree and the primary
     * key's left edge empties along with it.
     *
     * **There is deliberately no `SELECT ... FOR UPDATE SKIP LOCKED` around the subquery.** It is the
     * textbook answer to two workers picking the same ids, so it is named here rather than left as an
     * apparent oversight: it would buy a property this design already has. The run lock in the console
     * command makes overlap rare, and the sweep is **idempotent by construction** — a `DELETE`
     * matching a predicate that two runs both evaluate simply deletes the row once and reports zero
     * to the loser. Adding the clause would put a concurrency primitive into a job that runs once an
     * hour, in exchange for turning a harmless race into a slightly quieter one.
     *
     * **NOTHING IS HYDRATED, AND THAT IS THE GUARANTEE RATHER THAN THE OPTIMISATION.** No
     * `EmailVerificationRequest` is constructed and no `HashedVerificationToken` is rebuilt, so a row
     * whose `token_hash` will not survive a round trip through its value object is *a deleted row*
     * rather than *a stalled sweep*. The load-and-delete alternative would throw inside hydration on
     * that one row, kill the run, and go on killing every run afterwards while the backlog grew and
     * nothing named the offender (AC-17).
     *
     * The consequence worth knowing: the identity map is **not** notified. Doctrine has no idea these
     * rows are gone, so an `EmailVerificationRequest` fetched before the sweep is still served from
     * memory afterwards. That is the correct trade here — the sweep's callers hold no such objects —
     * but any assertion about what survived must come from a fresh repository or a raw `COUNT`.
     *
     * `:threshold` is bound with `Types::DATETIMETZ_IMMUTABLE` for exactly the reason
     * `countExpiredBefore()` spells out, and it matters more here because this statement deletes:
     * the naive inference would shift the window by the session offset and the rows it took with it
     * would not come back. `:limit` is `ParameterType::INTEGER` so that Postgres receives an integer
     * for `LIMIT` rather than a quoted string it would have to be talked into casting.
     *
     * The return is cast because DBAL 4's `executeStatement()` is typed `int|string` — the affected-row
     * count can arrive from a driver as a string, the same `bigint`-through-PDO story as `COUNT`. The
     * number is what the caller's loop terminates on (fewer than `$limit` means the table is drained),
     * so it is the one value in the slice that must be measured rather than assumed.
     */
    public function deleteExpiredBefore(\DateTimeImmutable $threshold, int $limit): int
    {
        $deleted = $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                DELETE FROM identity_email_verification_request
                 WHERE id IN (
                     SELECT id
                       FROM identity_email_verification_request
                      WHERE expires_at < :threshold
                      ORDER BY expires_at
                      LIMIT :limit
                 )
                SQL,
            [
                'threshold' => $threshold,
                'limit' => $limit,
            ],
            [
                'threshold' => Types::DATETIMETZ_IMMUTABLE,
                'limit' => ParameterType::INTEGER,
            ],
        );

        return (int) $deleted;
    }

    /**
     * @return EntityRepository<EmailVerificationRequest>
     */
    private function requests(): EntityRepository
    {
        return $this->entityManager->getRepository(EmailVerificationRequest::class);
    }
}
