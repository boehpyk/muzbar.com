<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Infrastructure\Identity\Persistence\Doctrine\Type\UserIdType;
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
     * @return EntityRepository<EmailVerificationRequest>
     */
    private function requests(): EntityRepository
    {
        return $this->entityManager->getRepository(EmailVerificationRequest::class);
    }
}
