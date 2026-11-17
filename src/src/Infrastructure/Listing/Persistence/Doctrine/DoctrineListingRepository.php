<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Persistence\Doctrine;

use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Exception\ListingNotFound;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\ListingId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The `Listing` aggregate's adapter (ADR-0007: implements the port, does not extend
 * `ServiceEntityRepository`). Every caller runs inside `Categories::holdSteady()`, so `save()`
 * flushes in the lock's transaction.
 */
final readonly class DoctrineListingRepository implements ListingRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function nextIdentity(): ListingId
    {
        return ListingId::fromString(Uuid::v7()->toRfc4122());
    }

    public function get(ListingId $id): Listing
    {
        return $this->entityManager->find(Listing::class, $id) ?? throw ListingNotFound::withId($id);
    }

    public function save(Listing $listing): void
    {
        $this->entityManager->persist($listing);
        $this->entityManager->flush();
    }
}
