<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\AttributeId as CatalogAttributeId;
use App\Domain\Catalog\ValueObject\AttributeType;
use App\Domain\Identity\Entity\User;
use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeInput;
use App\Domain\Listing\ValueObject\AttributeOptionId;
use App\Domain\Listing\ValueObject\AttributeRule;
use App\Domain\Listing\ValueObject\AttributeSchema;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\CategoryStatus;
use App\Domain\Listing\ValueObject\Currency;
use App\Domain\Listing\ValueObject\ListingDescription;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\ListingTitle;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Listing\ValueObject\SellerId;
use App\Infrastructure\Listing\Persistence\Doctrine\DoctrineListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Configuration;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Builds `Listing` aggregates through `Listing::draft()`, the only door the aggregate has.
 *
 * **It bypasses L-2** (leaf-only): it always hands `draft()` `CategoryStatus::Leaf`, because a
 * factory cannot read the catalog. So it will happily file a listing under a branch, or under a
 * category that does not exist — and the **default category does not exist** (no FK). A test that
 * depends on the category being real, a leaf, or listed-on (C-23/C-24, the leaf choices, a category
 * path on the drafts page) must build with `->inCategory($category)`, and must build the tree in an
 * order that keeps the listing on a leaf, or the factory hides the refusal it is testing.
 *
 * **Values persist only through `ListingRepository::save()`** (ADR-0020): `listing_attribute_value` is
 * unmapped, so an EntityManager flush — which is all Foundry's `create()` does — writes no value row.
 * `withValues()` therefore revises the listing through the aggregate (so L-9 applies to fixtures)
 * and saves it through the repository, after Foundry has persisted it.
 *
 * **`published()` / `withdrawn()` go through the aggregate** (`publish()`, `withdraw()`), against a
 * schema built from the category's real lineage (`required` included), saved through
 * `ListingRepository::save()` — and they **release the recorded events**. Foundry knows nothing of
 * ADR-0008's buffer: a `ListingPublished` left on the object would surface as a phantom event the
 * next time anything releases it. Like `draft()` they take the category as a `Leaf` (no L-2 here),
 * but L-9 and L-13 do apply, so a fixture that cannot lawfully be public throws instead of lying.
 *
 * @extends PersistentObjectFactory<Listing>
 */
final class ListingFactory extends PersistentObjectFactory
{
    /** The default `publishedAt` of `published()` — a whole second, like every instant the Clock port gives. */
    public const string PUBLISHED_AT = '2026-11-20 10:00:00';

    public static function class(): string
    {
        return Listing::class;
    }

    /**
     * @return array{id: ListingId, sellerId: SellerId, categoryId: CategoryId, title: ListingTitle, description: ListingDescription, price: Price}
     */
    protected function defaults(): array
    {
        return [
            'id' => ListingId::fromString(Uuid::v7()->toRfc4122()),
            'sellerId' => SellerId::fromString(Uuid::v7()->toRfc4122()),
            'categoryId' => CategoryId::fromString(Uuid::v7()->toRfc4122()),
            'title' => ListingTitle::fromString('Fender Precision V'),
            'description' => ListingDescription::fromString(''),
            'price' => Price::fromMinorUnits(123450, Currency::EUR),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(static function (array $attributes): Listing {
            /** @var ListingId $id */
            $id = $attributes['id'];
            /** @var SellerId $sellerId */
            $sellerId = $attributes['sellerId'];
            /** @var CategoryId $categoryId */
            $categoryId = $attributes['categoryId'];
            /** @var ListingTitle $title */
            $title = $attributes['title'];
            /** @var ListingDescription $description */
            $description = $attributes['description'];
            /** @var Price $price */
            $price = $attributes['price'];

            return Listing::draft($id, $sellerId, $categoryId, CategoryStatus::Leaf, $title, $description, $price, AttributeSchema::none(), AttributeInput::fromArray([]));
        })->afterPersist(static function (Listing $listing, array $attributes): void {
            $manager = self::entityManager();

            /** @var array<string, string> $values */
            $values = $attributes['values'] ?? [];
            if ([] !== $values) {
                $rules = [];
                foreach (array_keys($values) as $attributeId) {
                    $rules[] = self::ruleFor($manager, (string) $attributeId);
                }

                // Bypasses L-2 like draft() does above (the status is a given); L-9 applies to the values.
                $listing->revise(
                    $listing->sellerId(),
                    $listing->categoryId(),
                    CategoryStatus::Leaf,
                    AttributeSchema::of(...$rules),
                    $listing->title(),
                    $listing->description(),
                    $listing->price(),
                    AttributeInput::fromArray($values),
                );

                (new DoctrineListingRepository($manager))->save($listing);
            }

            $publishedAt = $attributes['publishedAt'] ?? null;
            if ($publishedAt instanceof \DateTimeImmutable) {
                $listing->publish($listing->sellerId(), CategoryStatus::Leaf, self::lineageSchema($manager, $listing->categoryId()), $publishedAt);
                if (true === ($attributes['withdrawn'] ?? false)) {
                    $listing->withdraw($listing->sellerId());
                }

                (new DoctrineListingRepository($manager))->save($listing);
                $listing->releaseEvents(); // ADR-0008's Foundry hole: nobody dispatches these, nobody may see them later.
            }
        });
    }

    /**
     * Public: through `publish()` (L-13 applies — every `required` attribute of the category's
     * lineage must have a value, so give `withValues()` the required ones). `$at` defaults to
     * `PUBLISHED_AT`; pass a whole-second instant.
     */
    public function published(?\DateTimeImmutable $at = null): static
    {
        return $this->with(['publishedAt' => $at ?? new \DateTimeImmutable(self::PUBLISHED_AT, new \DateTimeZone('UTC'))]);
    }

    /** Was public, is not now: `published()` then `withdraw()`; `published_at` kept. */
    public function withdrawn(?\DateTimeImmutable $at = null): static
    {
        return $this->published($at)->with(['withdrawn' => true]);
    }

    /**
     * Gives the listing attribute values, keyed by attribute id with the raw text a seller would
     * type (an option id for a choice, "6", "yes", "SN-1"). The schema is built from the given
     * attributes themselves — their kind, bounds and stored options — not from the listing's
     * category, so build values only for attributes that are in force there if the test cares:
     * the factory checks each value against its rule (L-9) and throws on a bad one, but cannot
     * know the category's lineage.
     *
     * @param array<string, string> $values
     */
    public function withValues(array $values): static
    {
        return $this->with(['values' => $values]);
    }

    private static function entityManager(): EntityManagerInterface
    {
        // Foundry exposes no object-manager accessor; a query builder carries its EntityManager.
        $repository = Configuration::instance()->persistence()->repositoryFor(Listing::class);
        \assert($repository instanceof EntityRepository);

        return $repository->createQueryBuilder('l')->getEntityManager();
    }

    /** The category's effective schema (own + every ancestor's attributes), `required` included. */
    private static function lineageSchema(EntityManagerInterface $manager, CategoryId $categoryId): AttributeSchema
    {
        $ids = $manager->getConnection()->fetchFirstColumn(
            'WITH RECURSIVE up (id, parent_id, d) AS (
                SELECT id, parent_id, 0 FROM catalog_category WHERE id = :category
                UNION ALL
                SELECT c.id, c.parent_id, up.d + 1 FROM catalog_category c JOIN up ON c.id = up.parent_id WHERE up.d < 10
            )
            SELECT a.id FROM catalog_attribute a JOIN up ON a.category_id = up.id ORDER BY a.id',
            ['category' => $categoryId->toString()],
        );

        return AttributeSchema::of(...array_map(
            static fn (mixed $id): AttributeRule => self::ruleFor($manager, \is_string($id) ? $id : throw new \LogicException('A non-string attribute id.')),
            $ids,
        ));
    }

    private static function ruleFor(EntityManagerInterface $manager, string $attributeId): AttributeRule
    {
        $attribute = $manager->find(Attribute::class, CatalogAttributeId::fromString($attributeId))
            ?? throw new \LogicException(\sprintf('ListingFactory::withValues(): attribute %s is not persisted.', $attributeId));
        $id = AttributeId::fromString($attributeId);

        return match ($attribute->type()) {
            AttributeType::Choice => AttributeRule::choice($id, array_map(
                static fn (mixed $optionId): AttributeOptionId => AttributeOptionId::fromString(\is_string($optionId) ? $optionId : throw new \LogicException('A non-string option id.')),
                $manager->getConnection()->fetchFirstColumn('SELECT id FROM catalog_attribute_option WHERE attribute_id = ? ORDER BY id', [$attributeId]),
            ), $attribute->isRequired()),
            AttributeType::Number => AttributeRule::number($id, $attribute->numberRules()?->minimum(), $attribute->numberRules()?->maximum(), $attribute->isRequired()),
            AttributeType::Boolean => AttributeRule::boolean($id, $attribute->isRequired()),
            AttributeType::Text => AttributeRule::text($id, $attribute->isRequired()),
        };
    }

    public function inCategory(Category $category): static
    {
        return $this->with(['categoryId' => CategoryId::fromString($category->id()->toString())]);
    }

    public function soldBy(User|SellerId $seller): static
    {
        return $this->with(['sellerId' => $seller instanceof User ? SellerId::fromString($seller->id()->toString()) : $seller]);
    }

    public function titled(string $title): static
    {
        return $this->with(['title' => ListingTitle::fromString($title)]);
    }

    public function pricedAt(string $decimal, Currency $currency = Currency::EUR): static
    {
        return $this->with(['price' => Price::fromDecimal($decimal, $currency)]);
    }
}
