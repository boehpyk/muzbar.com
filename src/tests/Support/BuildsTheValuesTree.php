<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Catalog\ValueObject\Unit;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use Doctrine\DBAL\Connection;

/**
 * listing-attribute-values' fixture tree (feature-spec, Acceptance criteria), for a `KernelTestCase`:
 *
 * ```
 * Instruments                       condition  (choice: New, Used)          required
 * ├── Guitars                       brand      (choice: Fender, Gibson)     required
 * │   └── Electric (leaf)           strings    (number, 4…12, unit "pcs")
 * │                                 has_case   (boolean)
 * │                                 serial     (text)
 * └── Bass (leaf)                   bass_strings (number, no bounds)
 * ```
 *
 * Built top-down, every attribute `->inCategory()`, so the tree respects C-21 even though
 * `AttributeFactory` itself does not check it (every code here is unique anyway).
 */
trait BuildsTheValuesTree
{
    private Category $instruments;
    private Category $guitars;
    private Category $electric;
    private Category $bass;

    private Attribute $condition;
    private Attribute $brand;
    private Attribute $strings;
    private Attribute $hasCase;
    private Attribute $serial;
    private Attribute $bassStrings;

    private function buildTheCategories(): void
    {
        $this->instruments = CategoryFactory::createOne(['name' => CategoryName::fromString('Instruments'), 'slug' => CategorySlug::fromString('instruments')]);
        $this->guitars = CategoryFactory::new(['name' => CategoryName::fromString('Guitars'), 'slug' => CategorySlug::fromString('guitars')])->childOf($this->instruments)->create();
        $this->electric = CategoryFactory::new(['name' => CategoryName::fromString('Electric'), 'slug' => CategorySlug::fromString('electric')])->childOf($this->guitars)->create();
        $this->bass = CategoryFactory::new(['name' => CategoryName::fromString('Bass'), 'slug' => CategorySlug::fromString('bass')])->childOf($this->instruments)->create();
    }

    private function buildTheAttributes(): void
    {
        $this->condition = AttributeFactory::new(['required' => true])->inCategory($this->instruments)->coded('condition')->labelled('Condition')->choice(['New', 'Used'])->create();
        $this->brand = AttributeFactory::new(['required' => true])->inCategory($this->guitars)->coded('brand')->labelled('Brand')->choice(['Fender', 'Gibson'])->create();
        $this->strings = AttributeFactory::new()->inCategory($this->electric)->coded('strings')->labelled('Strings')->number(NumberRules::of(Unit::fromString('pcs'), 4, 12))->create();
        $this->hasCase = AttributeFactory::new()->inCategory($this->electric)->coded('has-case')->labelled('Has case')->boolean()->create();
        $this->serial = AttributeFactory::new()->inCategory($this->electric)->coded('serial')->labelled('Serial')->text()->create();
        $this->bassStrings = AttributeFactory::new()->inCategory($this->bass)->coded('bass-strings')->labelled('Bass strings')->number()->create();
    }

    private function buildTheValuesTree(): void
    {
        $this->buildTheCategories();
        $this->buildTheAttributes();
    }

    private function optionIdOf(Attribute $attribute, string $label): string
    {
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $id = $db->fetchOne('SELECT id FROM catalog_attribute_option WHERE attribute_id = ? AND label = ?', [$attribute->id()->toString(), $label]);
        self::assertIsString($id, \sprintf('No option "%s" on %s.', $label, $attribute->code()->toString()));

        return $id;
    }

    /**
     * What a seller's form posts for Electric when every field is filled: the input of AC-7.
     *
     * @return array<string, string>
     */
    private function allFiveElectricInputs(): array
    {
        return [
            $this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'),
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Fender'),
            $this->strings->id()->toString() => '6',
            $this->hasCase->id()->toString() => 'yes',
            $this->serial->id()->toString() => 'SN-1',
        ];
    }
}
