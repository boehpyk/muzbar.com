<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Identity\Entity\User;
use App\Tests\Factory\ListingFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The "active" fixture the facet counts are proved against — phase1-gate's section B, lifted out of
 * `SchemaMutationGateTest` (search-browse D10) so one fixture serves `BrowseListingsTest` and
 * `SearchIndexUsageTest`: the values tree, then Fretboard wood and Finish defined through
 * `DefineAttributeHandler`, then seven listings built after the defines, through the aggregate.
 *
 * ```
 * Instruments   condition (required), fretboard-wood (Rosewood, Maple), finish (Gloss, Matte)
 * ├── Guitars   brand (required: Fender, Gibson)
 * │   └── Electric
 * └── Bass
 *
 * P1   published, Electric, Fender, Rosewood           Dr  draft,     Electric, Fender, Rosewood
 * P1b  published, Electric, Gibson, Rosewood           W   withdrawn, Electric, Fender, Rosewood
 * P2   published, Bass,             Rosewood           S   published, Electric, Fender, Maple
 * F    published, Electric, Fender, Finish = Gloss
 * ```
 *
 * Every listing holds Condition = Used (required to publish). Each odd row exists for one clause only
 * (see `BrowseListingsTest`): Dr and W for `status`, P2 for the subtree, S for the archived option,
 * F for the archived attribute. Includes `BuildsTheValuesTree`, so a class uses this trait alone.
 */
trait BuildsTheFacetFixture
{
    use BuildsTheValuesTree;

    private string $fretboardWood;
    private string $finish;
    private string $listingS;
    private string $listingF;
    private string $listingP1;
    private string $listingP1b;
    private string $listingP2;

    private function buildTheFacetFixture(): void
    {
        $this->buildTheValuesTree();
        $define = self::getContainer()->get(DefineAttributeHandler::class);
        self::assertInstanceOf(DefineAttributeHandler::class, $define);
        $instruments = $this->instruments->id()->toString();
        $this->fretboardWood = $define(new DefineAttribute($instruments, 'choice', 'Fretboard wood', 'fretboard-wood', false, optionLabels: ['Rosewood', 'Maple']))->toString();
        $this->finish = $define(new DefineAttribute($instruments, 'choice', 'Finish', 'finish', false, optionLabels: ['Gloss', 'Matte']))->toString();

        $seller = UserFactory::createOne();
        $used = $this->optionIdOf($this->condition, 'Used');
        $condition = $this->condition->id()->toString();
        $fender = $this->optionIdOf($this->brand, 'Fender');
        $gibson = $this->optionIdOf($this->brand, 'Gibson');
        $rosewood = $this->wood('Rosewood');
        $maple = $this->wood('Maple');
        $gloss = $this->optionIdOf($this->finishAttribute(), 'Gloss');
        $wood = $this->fretboardWood;

        $this->listingP1 = $this->listingOf($seller, $this->electric, $this->electricValues($fender) + [$wood => $rosewood])->published()->create()->id()->toString();
        $this->listingP1b = $this->listingOf($seller, $this->electric, $this->electricValues($gibson) + [$wood => $rosewood])->published()->create()->id()->toString();
        $this->listingP2 = $this->listingOf($seller, $this->bass, [$condition => $used, $wood => $rosewood])->published()->create()->id()->toString();
        $this->listingOf($seller, $this->electric, $this->electricValues($fender) + [$wood => $rosewood])->create(); // Dr (draft)
        $this->listingOf($seller, $this->electric, $this->electricValues($fender) + [$wood => $rosewood])->withdrawn()->create(); // W
        $this->listingS = $this->listingOf($seller, $this->electric, $this->electricValues($fender) + [$wood => $maple])->published()->create()->id()->toString();
        $this->listingF = $this->listingOf($seller, $this->electric, $this->electricValues($fender) + [$this->finish => $gloss])->published()->create()->id()->toString();
    }

    /**
     * @param array<string, string> $values
     */
    private function listingOf(User $seller, Category $category, array $values): ListingFactory
    {
        return ListingFactory::new()->soldBy($seller)->inCategory($category)->withValues($values);
    }

    /**
     * @return array<string, string> Condition = Used and the given Brand option
     */
    private function electricValues(string $brandOption): array
    {
        return [$this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'), $this->brand->id()->toString() => $brandOption];
    }

    private function finishAttribute(): Attribute
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $found = $manager->find(Attribute::class, AttributeId::fromString($this->finish));
        self::assertNotNull($found);

        return $found;
    }

    private function wood(string $label): string
    {
        $id = $this->facetDb()->fetchOne('SELECT id FROM catalog_attribute_option WHERE attribute_id = ? AND label = ?', [$this->fretboardWood, $label]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function valueRowsOf(string $listingId): array
    {
        return $this->facetDb()->fetchAllAssociative('SELECT * FROM listing_attribute_value WHERE listing_id = ? ORDER BY attribute_id', [$listingId]);
    }

    private function facetDb(): Connection
    {
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);

        return $db;
    }
}
