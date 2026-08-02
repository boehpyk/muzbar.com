<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;
use App\Infrastructure\Identity\Persistence\Doctrine\Type\UserIdType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Uid\Uuid;

/**
 * The Doctrine adapter behind the `PasswordResetRequestRepository` port.
 *
 * The context's third adapter, and structurally the same animal as the first two:
 * constructor-injected `EntityManagerInterface`, and **not** a `ServiceEntityRepository` subclass
 * (ADR-0007 decision 7). Inheriting from Doctrine would make the adapter's public surface the union
 * of the port and Doctrine's fifty-odd methods, every one of them an invitation to bypass the
 * aggregate. The entity manager is a collaborator, not a parent. (It is also the second of the four
 * structural conflicts that made `symfonycasts/reset-password-bundle` unusable here — its repository
 * is expected to extend exactly that class and implement a vendor interface.)
 *
 * Read this class next to `DoctrineEmailVerificationRequestRepository`. The two are close to
 * identical, and the *one* place they differ in substance — `findOutstandingForUser()`, which has no
 * counterpart there — is the query that exists solely because issuing a reset link invalidates the
 * outstanding ones while issuing a verification link does not.
 */
final readonly class DoctrinePasswordResetRequestRepository implements PasswordResetRequestRepository
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
     * As with the verification table, the argument bites harder here than on `identity_user`: a user
     * row is written once ever, while a reset request is written on every request and every reissue,
     * and the pruning job owed later will delete from the same index. Time-ordered keys mean those
     * deletions clear whole left-hand pages rather than punching holes through the middle of the
     * tree.
     *
     * The Domain knows none of this: `PasswordResetRequestId` validates the RFC 4122 layout and
     * stays version-agnostic, so the choice of v7 lives in the adapter that owns the database — which
     * is also why that value object deliberately has no `generate()`.
     */
    public function nextIdentity(): PasswordResetRequestId
    {
        return PasswordResetRequestId::fromString(Uuid::v7()->toRfc4122());
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
     * Here the only unique index is `uniq_identity_password_reset_request_token_hash`, and a
     * violation of it would mean two independent draws of 256 CSPRNG bits landed on the same value.
     * There is no business answer to an impossibility, because it is not a business event; the
     * honest response is a 500 and an alert. Wrapping it would dress an impossibility up as a
     * handled case and leave a `catch` block in the handler that no test can reach and no reader can
     * evaluate — a failure contract should describe things that happen.
     *
     * The generalisable rule, which is the reason these adapters are worth reading together: an
     * adapter translates a database error when the rule it enforces is a *domain* rule the database
     * merely happens to be holding. It stays out of the way when the error means the world is
     * broken. Uniqueness on an email is the first; uniqueness on a 256-bit random digest is the
     * second.
     *
     * Note that this method is called **more than once per use case** and that the ordering of those
     * calls is a security decision made by the handlers, not here. `RequestPasswordResetHandler`
     * saves each swept request before saving the new one; `ResetPasswordWithTokenHandler` saves the
     * *request* before the *user* — the inversion of slice 2's order, chosen so that a crash between
     * the two leaves a burnt token and an unchanged password rather than a changed password and a
     * live, re-usable token. This adapter flushing on every call is what makes those orderings real
     * rather than notional.
     */
    public function save(PasswordResetRequest $request): void
    {
        $this->entityManager->persist($request);
        $this->entityManager->flush();
    }

    /**
     * One indexed lookup on `uniq_identity_password_reset_request_token_hash` — the only hot query
     * on this table, run on every GET of a reset link and again on the POST that spends it.
     *
     * **It filters on the digest and on nothing else**, which is the port's contract and worth not
     * quietly "improving". Adding `AND redeemed_at IS NULL AND invalidated_at IS NULL AND expires_at
     * > now()` would look tidier at the call site and would be a real bug: it collapses "this link
     * expired an hour ago", "a newer request superseded this one", "this link was already used" and
     * "this token was never issued" into one indistinguishable `null`, exactly when the system most
     * wants to tell them apart — in a log, in a test, and in any future decision about which of them
     * deserves an alert. That the *visitor* sees one identical response for all four (AC-16) is a
     * presentation policy chosen to defeat account enumeration, and presentation policy that lives
     * three layers out is never a reason to destroy information down here.
     *
     * Business state is judged by the aggregate — `assertRedeemableWith()`, `isExpiredAt()` —
     * because a repository that judges business state is an aggregate with a SQL accent. Here that
     * separation is not merely tidy: the four causes are exactly the four ordered checks
     * `assertRedeemableWith()` performs, and a repository that pre-filtered any of them would make
     * the aggregate's ordering unobservable.
     *
     * `findOneBy()` binds the `HashedResetToken` itself; Doctrine resolves the parameter's type from
     * the field mapping and hands the value object to `HashedResetTokenType::convertToDatabaseValue()`.
     * That is why that type can afford to refuse bare strings — see its docblock.
     */
    public function findByTokenHash(HashedResetToken $hash): ?PasswordResetRequest
    {
        return $this->requests()->findOneBy(['tokenHash' => $hash]);
    }

    /**
     * The anti-abuse count (I-21), served by `idx_identity_password_reset_request_user_issued` —
     * equality on the leading `user_id`, then a range on `issued_at`, which is the column order that
     * index was built in and the reason it was named explicitly (AC-43 asserts the plan with
     * `EXPLAIN`).
     *
     * DQL rather than `EntityRepository::count()` because the criteria include a range, and `COUNT`
     * rather than counting hydrated objects because the answer is one integer: hydrating three
     * aggregates and running six DBAL conversions each, to compare a number against
     * `MAX_ISSUES_PER_HOUR`, would be paying for objects the handler never looks at.
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
                    FROM App\Domain\Identity\Entity\PasswordResetRequest request
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
     * The reissue sweep's input (ADR-0011 decision 4) — the query with no counterpart in
     * `DoctrineEmailVerificationRequestRepository`, because there is nothing there to sweep.
     *
     * IT FILTERS AND `findByTokenHash()` DOES NOT, AND THE DISTINCTION IS A RULE RATHER THAN A MOOD.
     * It filters on **structure** — `redeemed_at IS NULL AND invalidated_at IS NULL`, two columns
     * the aggregate wrote about itself — and never on **judgement**. In particular there is
     * deliberately **no `expires_at > :now` here**: expiry is not a column, it is a comparison
     * against an instant, and an instant comes from the `Clock` port, which a repository must not
     * reach for. A repository that knew what time it was could answer questions whose truth changes
     * between the query and the assertion, and no test could pin it.
     *
     * So expired-but-outstanding rows come back and get invalidated along with the rest. That is
     * harmless — invalidating a request that was going to die of old age costs one write and makes
     * the reason it stopped being live explicit — and keeping clock arithmetic out of SQL is worth
     * far more than the write.
     *
     * Hydration is fine here precisely because the caller needs the *aggregates*: it calls
     * `invalidate()` on each one and saves it, never a bulk DQL `UPDATE`, because a bulk update
     * would set `invalidated_at` without the aggregate ever agreeing — making I-17 (never both
     * redeemed and invalidated) unenforceable and parking a security rule in SQL where no unit test
     * can reach it. The result is bounded by `PasswordResetRequest::MAX_ISSUES_PER_HOUR`, so N ≤ 3
     * in practice, which is what makes the principled choice also the cheap one.
     *
     * `ORDER BY issued_at` is part of the port's contract rather than decoration: a deterministic
     * order is what lets a test assert *which* request was swept first, and the sort is free because
     * the query rides `idx_identity_password_reset_request_user_issued` on its leading column with
     * `issued_at` sitting right behind it.
     *
     * The `getResult()` return is annotated rather than trusted — PHPStan at `max` types it as
     * `mixed`, and `list<PasswordResetRequest>` is a claim the DQL's `SELECT request` makes true.
     *
     * @return list<PasswordResetRequest>
     */
    public function findOutstandingForUser(UserId $userId): array
    {
        /** @var list<PasswordResetRequest> $requests */
        $requests = $this->entityManager
            ->createQuery(
                <<<'DQL'
                    SELECT request
                    FROM App\Domain\Identity\Entity\PasswordResetRequest request
                    WHERE request.userId = :userId
                      AND request.redeemedAt IS NULL
                      AND request.invalidatedAt IS NULL
                    ORDER BY request.issuedAt ASC
                    DQL
            )
            ->setParameter('userId', $userId, UserIdType::NAME)
            ->getResult();

        return $requests;
    }

    /**
     * How many rows this table currently owes the sweep — added by `identity-challenge-pruning`
     * together with `deleteExpiredBefore()`, the pair that finally pays off the IOU this port carried
     * from slice 3 (*"the pruning job owed later ... will add its own method when it is written"*).
     *
     * This number is the slice's whole observability claim, and it is a separate query rather than a
     * field of the delete's return for one reason: it is read **before** anything is removed. A count
     * taken after a sweep is ~0 in a healthy system and ~0 in a system whose sweeper died last
     * Tuesday, so it distinguishes nothing. Read first, it separates *"nothing to do"* from *"the job
     * stopped"* — and unlike the Redis heartbeat it cannot be faked by a job that runs and does
     * nothing, and it survives a `FLUSHALL`.
     *
     * `:threshold` CARRIES `Types::DATETIMETZ_IMMUTABLE` EXPLICITLY. Left to inference,
     * `ParameterTypeInferer` maps a `\DateTimeImmutable` onto the **naive** `datetime_immutable`,
     * whose format string is `'Y-m-d H:i:s'` — no offset — while `expires_at` is
     * `TIMESTAMP(0) WITH TIME ZONE`. Postgres resolves the offsetless literal against the session
     * timezone, so the retention boundary silently moves by however many hours that session is away
     * from UTC. Two properties make this the nastiest bug available in this slice: it is **invisible
     * on a UTC box**, which is every box we currently own, and it makes this method and
     * `deleteExpiredBefore()` disagree the moment only one of them is fixed. `countIssuedForUserSince()`
     * above hit the identical trap on a different column; that it recurs on the first new query written
     * in months is the argument for stating it here in full rather than pointing at it.
     *
     * **The `<` is strict and is shared, letter for letter, with `deleteExpiredBefore()`.** The port
     * calls that contract, and the failure mode explains why: a `<=` here against a `<` there leaves a
     * row exactly on the boundary permanently counted and never removed, so `/health/ready` would
     * report a backlog of one on a table with nothing wrong with it, forever.
     *
     * **The predicate reads `expires_at` and reads nothing else** — and this file is where that
     * sentence gets argued with, because this table has *two* terminal columns and a staleness rule
     * that lives on another aggregate entirely. `redeemed_at`, `invalidated_at` and
     * `identity_user.password_changed_at` therefore appear nowhere in it. See the port and ADR-0012
     * decision 1: `expires_at` is a ceiling on all three, and a predicate that tried to spell out
     * "dead" would be the shared `Challenge` base class ADR-0011 decision 9 rejected, resurrected in
     * the one layer where no unit test can read it.
     *
     * Cast, not trusted: PostgreSQL answers `COUNT` with a `bigint` and PDO hands `bigint` back as a
     * string, so the cast is what makes the `int` return type true rather than merely declared.
     */
    public function countExpiredBefore(\DateTimeImmutable $threshold): int
    {
        $count = $this->entityManager
            ->createQuery(
                <<<'DQL'
                    SELECT COUNT(request.id)
                    FROM App\Domain\Identity\Entity\PasswordResetRequest request
                    WHERE request.expiresAt < :threshold
                    DQL
            )
            ->setParameter('threshold', $threshold, Types::DATETIMETZ_IMMUTABLE)
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * One batch of the reset table's sweep — up to `$limit` rows older than `$threshold`, removed by
     * a single statement, returning the count that actually went.
     *
     * This is the first `DELETE` this repository has ever contained, and the rows are personal data:
     * `(user_id, issued_at)` is a dated record that a named person asked to recover their account.
     * That is what the retention window is *for*, and it is also why every guard below is written out
     * instead of assumed.
     *
     * NATIVE SQL RATHER THAN DQL, AND THE REASON IS A LIMIT RATHER THAN A PREFERENCE. DQL's `DELETE`
     * accepts no `LIMIT` clause and no subquery in its `WHERE ... IN`; between them that removes every
     * way of expressing "a thousand at a time", which is the batching the use case is built out of
     * (AC-11). So the adapter reaches past the ORM to the connection it already holds — no second
     * constructor argument, because the entity manager is still the single collaborator and its
     * connection is not a new dependency. Table and column names appear as literals here, which is
     * ADR-0007 decision 6 read in the direction it matters most: that convention already refuses to
     * let anything *derive* a name, and a derived name is a poor thing to point a `DELETE` at.
     *
     * `ORDER BY expires_at` IS PART OF THE ALGORITHM, NOT TIDINESS, and it is the line most likely to
     * be deleted by someone optimising a statement that throws its own ordering away. Two things
     * depend on it. **Monotone progress:** each batch takes the oldest remaining rows, so a run
     * stopped by the 50-batch or 30-second cap has provably cleared the front of the backlog and the
     * next run resumes behind it. Drop the ordering and Postgres is free to hand back any thousand
     * rows it finds cheapest, which still deletes a thousand and still lets the oldest row sit there
     * indefinitely — a truncated run's progress would become unpredictable in exactly the case where
     * predictability is the point. **And index physics:** `nextIdentity()` above already forecast this
     * method — *"the pruning job owed later will delete from the same index. Time-ordered keys mean
     * those deletions clear whole left-hand pages rather than punching holes through the middle of the
     * tree."* Deleting in `expires_at` order is what cashes that forecast, since issue order, expiry
     * order and UUIDv7 order are one order here; the scan walks the left edge of the new
     * `idx_identity_password_reset_request_expires_at` and the primary key's leftmost pages empty
     * behind it.
     *
     * **No `SELECT ... FOR UPDATE SKIP LOCKED`, deliberately.** Anyone who has written a queue reaches
     * for it on sight of `SELECT id ... LIMIT` feeding a `DELETE`, so its absence is recorded as a
     * decision rather than discovered as a gap. What it defends against is two workers selecting the
     * same ids — and the cost of that here is one wasted statement, because deleting by a predicate is
     * idempotent: the second run's `DELETE` matches nothing and reports zero. The console command's
     * Redis lock already makes the overlap rare and idempotency makes it harmless, so the clause would
     * add a concurrency primitive to an hourly housekeeping job in exchange for a property it holds
     * already.
     *
     * **NOT ONE AGGREGATE IS HYDRATED, AND THAT IS A CORRECTNESS PROPERTY.** No `PasswordResetRequest`
     * is built, so no `HashedResetToken` is reconstructed, so a row whose `token_hash` cannot be
     * turned back into a valid value object is deleted like any other instead of throwing inside
     * hydration, killing the run, and killing every run after it while the backlog climbed and nothing
     * named the row responsible (AC-17). The set-based design's sharpest practical argument is this
     * one, not its speed.
     *
     * Its price, stated: the identity map knows nothing about any of it. A `PasswordResetRequest`
     * loaded before the sweep is still handed back from memory afterwards, so anything asserting what
     * survived must ask a fresh repository or count rows directly.
     *
     * Both parameters are typed explicitly. `:threshold` gets `Types::DATETIMETZ_IMMUTABLE` for
     * `countExpiredBefore()`'s reason, with the stakes raised — a window shifted by a session offset
     * costs a wrong `COUNT` there and permanently missing rows here. `:limit` gets
     * `ParameterType::INTEGER` so `LIMIT` receives an integer rather than a quoted literal Postgres
     * has to be persuaded to cast.
     *
     * The return value is cast: DBAL 4 declares `executeStatement()` as `int|string`, the affected-row
     * count being the same `bigint`-shaped thing PDO likes to return as a string. It is also the
     * number the handler's loop terminates on — a short batch means the table is drained — which is
     * why the port insists it be rows *actually* deleted rather than rows requested.
     */
    public function deleteExpiredBefore(\DateTimeImmutable $threshold, int $limit): int
    {
        $deleted = $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                DELETE FROM identity_password_reset_request
                 WHERE id IN (
                     SELECT id
                       FROM identity_password_reset_request
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
     * @return EntityRepository<PasswordResetRequest>
     */
    private function requests(): EntityRepository
    {
        return $this->entityManager->getRepository(PasswordResetRequest::class);
    }
}
