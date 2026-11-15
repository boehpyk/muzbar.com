<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\AttributeOption;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Builds `AttributeOption` aggregates through `Attribute::newOption()`, the only door the
 * aggregate leaves open (`AttributeOption::create()` is `@internal`). The owning `Attribute` is
 * required, so the type-coherence rule (C-15) applies to fixtures too: an option for a number
 * attribute throws here as it does in production.
 *
 * @extends PersistentObjectFactory<AttributeOption>
 */
final class AttributeOptionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AttributeOption::class;
    }

    /**
     * @return array{id: AttributeOptionId, label: AttributeLabel}
     */
    protected function defaults(): array
    {
        return [
            'id' => AttributeOptionId::fromString(Uuid::v7()->toRfc4122()),
            'label' => AttributeLabel::fromString(ucfirst(self::faker()->unique()->lexify('opt-????????'))),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(static function (array $attributes): AttributeOption {
            $attribute = $attributes['attribute'] ?? throw new \LogicException('AttributeOptionFactory needs ->forAttribute($attribute).');
            \assert($attribute instanceof Attribute);
            /** @var AttributeOptionId $id */
            $id = $attributes['id'];
            /** @var AttributeLabel $label */
            $label = $attributes['label'];

            return $attribute->newOption($id, $label);
        });
    }

    public function forAttribute(Attribute $attribute): static
    {
        return $this->with(['attribute' => $attribute]);
    }

    public function labelled(string $label): static
    {
        return $this->with(['label' => AttributeLabel::fromString($label)]);
    }
}
