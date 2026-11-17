<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Identity\Entity\User;
use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\CategoryStatus;
use App\Domain\Listing\ValueObject\Currency;
use App\Domain\Listing\ValueObject\ListingDescription;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\ListingTitle;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Listing\ValueObject\SellerId;
use Symfony\Component\Uid\Uuid;
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
 * @extends PersistentObjectFactory<Listing>
 */
final class ListingFactory extends PersistentObjectFactory
{
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

            return Listing::draft($id, $sellerId, $categoryId, CategoryStatus::Leaf, $title, $description, $price);
        });
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
