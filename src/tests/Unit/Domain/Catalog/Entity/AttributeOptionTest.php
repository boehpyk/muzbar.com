<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\Entity;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\AttributeOption;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeCodes;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;
use App\Domain\Catalog\ValueObject\CategoryId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AttributeOptionTest extends TestCase
{
    private static function option(string $label = 'Left'): AttributeOption
    {
        $attribute = Attribute::defineChoice(
            AttributeId::fromString(Uuid::v7()->toRfc4122()),
            CategoryId::fromString(Uuid::v7()->toRfc4122()),
            AttributeCode::fromString('orientation'),
            AttributeLabel::fromString('Orientation'),
            false,
            [AttributeLabel::fromString($label)],
            AttributeCodes::none(),
        );

        return $attribute->newOption(AttributeOptionId::fromString(Uuid::v7()->toRfc4122()), AttributeLabel::fromString($label));
    }

    public function testCreatedOptionDerivesItsLabelKey(): void
    {
        self::assertSame('left-handed', self::option('Left-HANDED')->labelKey());
    }

    public function testRelabelChangesLabelAndKeyTogetherAndKeepsIdentity(): void
    {
        $option = self::option('Left');
        $id = $option->id();
        $attributeId = $option->attributeId();

        $option->relabel(AttributeLabel::fromString('RIGHT'));

        self::assertSame('RIGHT', $option->label()->toString());
        self::assertSame('right', $option->labelKey());
        self::assertTrue($option->id()->equals($id));
        self::assertTrue($option->attributeId()->equals($attributeId));
    }

    public function testPublicSurfaceHasNoWayToChangeIdentityOrOwner(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(AttributeOption::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        self::assertSame(['attributeId', 'create', 'id', 'label', 'labelKey', 'relabel'], $methods);
    }

    public function testDoesNotRecordDomainEvents(): void
    {
        self::assertSame([], class_uses(AttributeOption::class));
    }
}
