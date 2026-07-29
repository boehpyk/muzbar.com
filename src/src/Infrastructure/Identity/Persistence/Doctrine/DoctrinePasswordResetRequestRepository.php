<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;
use App\Infrastructure\Identity\Persistence\Doctrine\Type\UserIdType;
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
     * @return EntityRepository<PasswordResetRequest>
     */
    private function requests(): EntityRepository
    {
        return $this->entityManager->getRepository(PasswordResetRequest::class);
    }
}
