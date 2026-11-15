<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeType;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\NumberRules;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Builds `Attribute` aggregates through the four `Attribute::define…()` constructors.
 *
 * Defaults to a text attribute. `choice([...labels])` also persists one `AttributeOption` per
 * label, in the given order, through `AttributeOptionFactory` (so through `newOption()`); the
 * attribute is the commit point and is written first here, which is fine for a fixture because
 * nothing reads between the two writes.
 *
 * @extends PersistentObjectFactory<Attribute>
 */
final class AttributeFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Attribute::class;
    }

    /**
     * @return array{id: AttributeId, categoryId: CategoryId, code: AttributeCode, label: AttributeLabel, required: bool, type: AttributeType, numberRules: NumberRules, optionLabels: list<string>}
     */
    protected function defaults(): array
    {
        $word = self::faker()->unique()->lexify('attr-????????');

        return [
            'id' => AttributeId::fromString(Uuid::v7()->toRfc4122()),
            'categoryId' => CategoryId::fromString(Uuid::v7()->toRfc4122()),
            'code' => AttributeCode::fromString($word),
            'label' => AttributeLabel::fromString(ucfirst($word)),
            'required' => false,
            'type' => AttributeType::Text,
            'numberRules' => NumberRules::of(null, null, null),
            'optionLabels' => [],
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->instantiateWith(static function (array $attributes): Attribute {
                /** @var AttributeId $id */
                $id = $attributes['id'];
                /** @var CategoryId $categoryId */
                $categoryId = $attributes['categoryId'];
                /** @var AttributeCode $code */
                $code = $attributes['code'];
                /** @var AttributeLabel $label */
                $label = $attributes['label'];
                /** @var bool $required */
                $required = $attributes['required'];
                /** @var AttributeType $type */
                $type = $attributes['type'];
                /** @var NumberRules $rules */
                $rules = $attributes['numberRules'];
                /** @var list<string> $optionLabels */
                $optionLabels = $attributes['optionLabels'];

                return match ($type) {
                    AttributeType::Choice => Attribute::defineChoice($id, $categoryId, $code, $label, $required, array_map(AttributeLabel::fromString(...), $optionLabels)),
                    AttributeType::Number => Attribute::defineNumber($id, $categoryId, $code, $label, $required, $rules),
                    AttributeType::Boolean => Attribute::defineBoolean($id, $categoryId, $code, $label, $required),
                    AttributeType::Text => Attribute::defineText($id, $categoryId, $code, $label, $required),
                };
            })
            ->afterPersist(static function (Attribute $attribute, array $attributes): void {
                if (AttributeType::Choice !== $attribute->type()) {
                    return;
                }

                /** @var list<string> $labels */
                $labels = $attributes['optionLabels'];
                foreach ($labels as $label) {
                    AttributeOptionFactory::new()->forAttribute($attribute)->labelled($label)->create();
                }
            })
        ;
    }

    public function inCategory(Category $category): static
    {
        return $this->with(['categoryId' => $category->id()]);
    }

    public function coded(string $code): static
    {
        return $this->with(['code' => AttributeCode::fromString($code)]);
    }

    public function labelled(string $label): static
    {
        return $this->with(['label' => AttributeLabel::fromString($label)]);
    }

    /**
     * @param list<string> $optionLabels at least one, in the order they should be stored
     */
    public function choice(array $optionLabels = ['Option A']): static
    {
        return $this->with(['type' => AttributeType::Choice, 'optionLabels' => $optionLabels]);
    }

    public function number(?NumberRules $rules = null): static
    {
        return $this->with(['type' => AttributeType::Number, 'numberRules' => $rules ?? NumberRules::of(null, null, null)]);
    }

    public function boolean(): static
    {
        return $this->with(['type' => AttributeType::Boolean]);
    }

    public function text(): static
    {
        return $this->with(['type' => AttributeType::Text]);
    }
}
