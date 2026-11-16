<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Domain\Catalog\ValueObject\CategoryName;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * T13 (plan D10, Q3): the one-off pre-deploy query that lists attribute codes already shared by an
 * ancestor and a descendant, i.e. data written under slice 2 that C-21 would have refused.
 *
 * The query is the technical plan's, verbatim. It lives in a test so that "it finds what it claims"
 * is known rather than hoped: a detection query that silently returns nothing would green-light a
 * deploy over data only an admin can fix. `AttributeFactory` bypasses C-21, so it plants the
 * violations.
 */
final class PreDeployDuplicateCodeQueryTest extends KernelTestCase
{
    private const string QUERY = <<<'SQL'
        WITH RECURSIVE anc (category_id, ancestor_id, n) AS (
            SELECT id, parent_id, 1 FROM catalog_category WHERE parent_id IS NOT NULL
            UNION ALL
            SELECT anc.category_id, c.parent_id, anc.n + 1
            FROM anc JOIN catalog_category c ON c.id = anc.ancestor_id
            WHERE c.parent_id IS NOT NULL AND anc.n < 5
        )
        SELECT lower_a.code, upper_a.category_id AS ancestor, lower_a.category_id AS descendant
        FROM anc
        JOIN catalog_attribute upper_a ON upper_a.category_id = anc.ancestor_id
        JOIN catalog_attribute lower_a ON lower_a.category_id = anc.category_id AND lower_a.code = upper_a.code
        SQL;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function violations(): array
    {
        return $this->db->fetchAllAssociative(self::QUERY);
    }

    public function testItReturnsExactlyThePlantedAncestorDescendantPair(): void
    {
        $instruments = CategoryFactory::new(['name' => CategoryName::fromString('Instruments')])->create();
        $guitars = CategoryFactory::new()->childOf($instruments)->create();
        $electric = CategoryFactory::new()->childOf($guitars)->create();
        AttributeFactory::new()->inCategory($instruments)->coded('strings')->create();
        AttributeFactory::new()->inCategory($guitars)->coded('frets')->create();
        // Two levels apart, so a query that only joined direct parents would miss it.
        AttributeFactory::new()->inCategory($electric)->coded('strings')->create();

        $rows = $this->violations();

        self::assertSame(
            [['code' => 'strings', 'ancestor' => $instruments->id()->toString(), 'descendant' => $electric->id()->toString()]],
            $rows,
        );
    }

    public function testItFindsADirectParentChildPairToo(): void
    {
        $parent = CategoryFactory::createOne();
        $child = CategoryFactory::new()->childOf($parent)->create();
        AttributeFactory::new()->inCategory($parent)->coded('condition')->create();
        AttributeFactory::new()->inCategory($child)->coded('condition')->create();

        self::assertSame(
            [['code' => 'condition', 'ancestor' => $parent->id()->toString(), 'descendant' => $child->id()->toString()]],
            $this->violations(),
        );
    }

    public function testItReturnsNothingForTheSameCodeOnSiblingBranches(): void
    {
        $instruments = CategoryFactory::createOne();
        $guitars = CategoryFactory::new()->childOf($instruments)->create();
        $electric = CategoryFactory::new()->childOf($guitars)->create();
        $bass = CategoryFactory::new()->childOf($instruments)->create();
        AttributeFactory::new()->inCategory($electric)->coded('pickups')->create();
        AttributeFactory::new()->inCategory($bass)->coded('pickups')->create();

        self::assertSame([], $this->violations());
    }

    public function testItReturnsNothingForALawfulTreeWithInheritedAttributes(): void
    {
        $instruments = CategoryFactory::createOne();
        $guitars = CategoryFactory::new()->childOf($instruments)->create();
        AttributeFactory::new()->inCategory($instruments)->coded('condition')->create();
        AttributeFactory::new()->inCategory($guitars)->coded('strings')->create();

        self::assertSame([], $this->violations());
    }
}
