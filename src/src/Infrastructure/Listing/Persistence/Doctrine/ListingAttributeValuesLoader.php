<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Persistence\Doctrine;

use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeOptionId;
use App\Domain\Listing\ValueObject\AttributeValue;
use App\Domain\Listing\ValueObject\AttributeValues;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;

/**
 * Hydrates `Listing::$attributeValues` from `listing_attribute_value` (ADR-0020 decision 2) — the
 * read half of what `DoctrineListingRepository::save()` writes. The table is unmapped, so this is
 * Doctrine's own hydration mechanism (reflection into a private property) used once more, in the
 * same layer, and the only place that does it.
 *
 * `postLoad` fires once per hydration: a second `get()` in one EntityManager is served from the
 * identity map and never clobbers unsaved values, while `refresh()` (Foundry's included) reloads them.
 */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: Listing::class)]
final class ListingAttributeValuesLoader
{
    public function postLoad(Listing $listing, PostLoadEventArgs $args): void
    {
        $rows = $args->getObjectManager()->getConnection()->fetchAllAssociative(
            'SELECT attribute_id, option_id, value_int, value_bool, value_text FROM listing_attribute_value WHERE listing_id = :id',
            ['id' => $listing->id()->toString()],
        );

        $values = array_map(self::toValue(...), $rows);

        new \ReflectionProperty(Listing::class, 'attributeValues')->setValue($listing, AttributeValues::of(...$values));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toValue(array $row): AttributeValue
    {
        $attributeId = $row['attribute_id'];
        $set = array_filter(
            [$row['option_id'], $row['value_int'], $row['value_bool'], $row['value_text']],
            static fn (mixed $column): bool => null !== $column,
        );

        // Impossible past `chk_listing_attribute_value_one_value`; refuse rather than guess a kind.
        if (!\is_string($attributeId) || 1 !== \count($set)) {
            throw new \LogicException(\sprintf('A listing_attribute_value row for attribute "%s" has %d typed columns set; exactly one is required.', \is_string($attributeId) ? $attributeId : '?', \count($set)));
        }

        $id = AttributeId::fromString($attributeId);

        return match (true) {
            \is_string($row['option_id']) => AttributeValue::ofOption($id, AttributeOptionId::fromString($row['option_id'])),
            \is_int($row['value_int']) => AttributeValue::ofNumber($id, $row['value_int']),
            \is_bool($row['value_bool']) => AttributeValue::ofBoolean($id, $row['value_bool']),
            \is_string($row['value_text']) => AttributeValue::ofText($id, $row['value_text']),
            default => throw new \LogicException(\sprintf('A listing_attribute_value row for attribute "%s" has a column of an unexpected type.', $attributeId)),
        };
    }
}
