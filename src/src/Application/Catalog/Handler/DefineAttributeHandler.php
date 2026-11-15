<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\DefineAttribute;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\CategoryNotFound;
use App\Domain\Catalog\Exception\InvalidCategoryId;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeType;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Catalog\ValueObject\Unit;

/**
 * Defines an attribute on a category — and, for a choice attribute, its initial options: the first
 * use case that creates several aggregates in one transaction (plan D13).
 *
 * Everything runs inside one `exclusively()` (plan D5): "the category exists" (C-12) is the mirror
 * of `DeleteCategoryHandler`'s C-13 guard, and only the lock stops a delete slipping in between the
 * check and the save.
 */
final readonly class DefineAttributeHandler
{
    public function __construct(
        private CategoryRepository $categories,
        private AttributeRepository $attributes,
        private AttributeOptionRepository $options,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws CategoryNotFound                                       the category, or a malformed id (C-12)
     * @throws \App\Domain\Catalog\Exception\InvalidAttributeLabel    the attribute's or an option's (C-9)
     * @throws \App\Domain\Catalog\Exception\InvalidAttributeCode     (C-10)
     * @throws AttributeCodeAlreadyTaken                              (C-11)
     * @throws \App\Domain\Catalog\Exception\InvalidUnit              (C-16)
     * @throws \App\Domain\Catalog\Exception\InvalidNumberRules       (C-16)
     * @throws \App\Domain\Catalog\Exception\InvalidAttributeOptions  a choice without options (C-18)
     * @throws \App\Domain\Catalog\Exception\DuplicateAttributeOption (C-17)
     */
    public function __invoke(DefineAttribute $command): AttributeId
    {
        return $this->lock->exclusively(function () use ($command): AttributeId {
            // C-12: `get()` throws `CategoryNotFound`. The attribute stores this id, never a slug.
            $categoryId = $this->categories->get($this->categoryId($command->categoryId))->id();
            $label = AttributeLabel::fromString($command->label);
            $code = AttributeCode::fromString($command->code);

            // C-11 pre-check, for the error message; the `(category_id, code)` unique index is the
            // guarantee against anything that bypasses the lock.
            if ($this->attributes->isCodeTaken($categoryId, $code, null)) {
                throw AttributeCodeAlreadyTaken::inCategory($code);
            }

            $id = $this->attributes->nextIdentity();
            $type = AttributeType::from($command->type);

            if (AttributeType::Choice === $type) {
                $initialLabels = array_map(AttributeLabel::fromString(...), $command->optionLabels);
                $this->defineChoice(
                    Attribute::defineChoice($id, $categoryId, $code, $label, $command->required, $initialLabels),
                    $initialLabels,
                );

                return $id;
            }

            $this->attributes->save(match ($type) {
                AttributeType::Number => Attribute::defineNumber($id, $categoryId, $code, $label, $command->required, NumberRules::of(
                    null === $command->unit ? null : Unit::fromString($command->unit),
                    $command->minimum,
                    $command->maximum,
                )),
                AttributeType::Boolean => Attribute::defineBoolean($id, $categoryId, $code, $label, $command->required),
                AttributeType::Text => Attribute::defineText($id, $categoryId, $code, $label, $command->required),
            });

            return $id;
        });
    }

    /**
     * Builds the initial options through the attribute (C-15) and saves both kinds of aggregate.
     *
     * @param list<AttributeLabel> $initialLabels the labels `defineChoice()` already decided C-17/C-18 on
     */
    private function defineChoice(Attribute $attribute, array $initialLabels): void
    {
        // In input order, one id each: ids are UUIDv7 minted under the lock, so the stored order
        // (`ORDER BY id`) is this order (C-19, plan D3).
        $options = array_map(
            fn (AttributeLabel $optionLabel) => $attribute->newOption($this->options->nextIdentity(), $optionLabel),
            $initialLabels,
        );

        // THE ORDER IS DELIBERATE (plan D13, ADR-0017 decision 8): options first, attribute last.
        // The attribute row is the commit point of the whole definition. Should this ever be split
        // out of one transaction, the only possible leftover is an option without its attribute —
        // inert, since every option route goes through its attribute — whereas the reverse order
        // could leave a choice attribute with no options, violating C-18. `DeleteAttributeHandler`
        // does the mirror image on purpose (plan D7): attribute removed FIRST, options swept after.
        // Do not "align" the two.
        $this->options->save(...$options);
        $this->attributes->save($attribute);
    }

    /** As in `CreateCategoryHandler`: a malformed category id is a category that does not exist (AC-14). */
    private function categoryId(string $value): CategoryId
    {
        try {
            return CategoryId::fromString($value);
        } catch (InvalidCategoryId $e) {
            throw CategoryNotFound::withMalformedId($value, $e);
        }
    }
}
