<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Catalog\ValueObject\Lineage;
use App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineCategoryRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Configuration;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Builds `Category` aggregates through `Category::create()`, the only door the aggregate has.
 *
 * `childOf($parent)` asks the real repository for the parent's lineage, so a fixture tree gets
 * exactly the depth rule production gets (a fifth level throws `CategoryTreeTooDeep` here too)
 * rather than a hand-assembled parent id that skips it. The parent must already be persisted.
 *
 * @extends PersistentObjectFactory<Category>
 */
final class CategoryFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Category::class;
    }

    /**
     * @return array{id: CategoryId, name: CategoryName, slug: CategorySlug, parent: ?Category}
     */
    protected function defaults(): array
    {
        $word = self::faker()->unique()->lexify('cat-????????');

        return [
            'id' => CategoryId::fromString(Uuid::v7()->toRfc4122()),
            'name' => CategoryName::fromString(ucfirst($word)),
            'slug' => CategorySlug::fromString($word),
            'parent' => null,
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(static function (array $attributes): Category {
            /** @var CategoryId $id */
            $id = $attributes['id'];
            /** @var CategoryName $name */
            $name = $attributes['name'];
            /** @var CategorySlug $slug */
            $slug = $attributes['slug'];
            /** @var ?Category $parent */
            $parent = $attributes['parent'];

            return Category::create($id, $name, $slug, self::lineageOf($parent));
        });
    }

    public function childOf(Category $parent): static
    {
        return $this->with(['parent' => $parent]);
    }

    private static function lineageOf(?Category $parent): Lineage
    {
        if (null === $parent) {
            return Lineage::ofRoot();
        }

        // Foundry exposes no object-manager accessor; a query builder carries its EntityManager.
        $repository = Configuration::instance()->persistence()->repositoryFor(Category::class);
        \assert($repository instanceof EntityRepository);
        $manager = $repository->createQueryBuilder('c')->getEntityManager();

        return (new DoctrineCategoryRepository($manager))->lineageOf($parent->id());
    }
}
