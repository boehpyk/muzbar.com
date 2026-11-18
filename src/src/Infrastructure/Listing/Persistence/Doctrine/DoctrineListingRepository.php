<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Persistence\Doctrine;

use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Exception\ListingNotFound;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\ListingId;
use Doctrine\DBAL\ParameterType;
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

    /**
     * Flushes the listing, then rewrites its values (ADR-0020 decision 2).
     *
     * **This is the ONLY writer of `listing_attribute_value`.** The table is not mapped, so an
     * EntityManager flush alone persists no values — anything that saves a `Listing` some other way
     * (a fixture's `persist()`, a bulk import) silently drops its details. Same connection, so the
     * rows commit or roll back with the caller's `holdSteady()` transaction.
     */
    public function save(Listing $listing): void
    {
        $this->entityManager->persist($listing);
        $this->entityManager->flush();

        $this->writeValues($listing);
    }

    /**
     * Delete-then-insert, the aggregate's own state rewritten whole (as Doctrine rewrites a `jsonb`
     * column) — legitimate per ADR-0012: no invariant lives on a single row.
     *
     * ponytail: rewrites every value on every save; diff against a load snapshot if a listing ever
     * carries hundreds of values.
     */
    private function writeValues(Listing $listing): void
    {
        $connection = $this->entityManager->getConnection();
        $listingId = $listing->id()->toString();

        $connection->executeStatement(
            'DELETE FROM listing_attribute_value WHERE listing_id = :id',
            ['id' => $listingId],
        );

        $values = $listing->attributeValues()->toList();

        if ([] === $values) {
            return;
        }

        $rows = [];
        $params = [];
        $types = [];

        foreach ($values as $value) {
            // Exactly one typed column per kind; the CHECK refuses anything else.
            $rows[] = '(?, ?, ?, ?, ?, ?)';
            array_push(
                $params,
                $listingId,
                $value->attributeId()->toString(),
                $value->optionId()?->toString(),
                $value->number(),
                $value->boolean(),
                $value->text(),
            );
            array_push(
                $types,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::BOOLEAN,
                ParameterType::STRING,
            );
        }

        $connection->executeStatement(
            'INSERT INTO listing_attribute_value (listing_id, attribute_id, option_id, value_int, value_bool, value_text) VALUES '.implode(', ', $rows),
            $params,
            $types,
        );
    }
}
