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
use App\Domain\Catalog\ValueObject\AttributeCodes;
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
 * check and the save. The same lock makes the branch's codes true for C-21 (ADR-0018): no define,
 * recode or move can change them between this read and the save.
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
     * @throws AttributeCodeAlreadyTaken                              in force on the branch (C-21)
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

            // C-21: the aggregate decides on the codes in force along the branch, read here under
            // the lock — no index can see a branch, so the lock is the whole guarantee. The
            // `(category_id, code)` unique index remains the backstop for the same-category case (C-11).
            $codesInBranch = $this->codesInBranchOf($categoryId);
            $id = $this->attributes->nextIdentity();
            $type = AttributeType::from($command->type);

            if (AttributeType::Choice === $type) {
                $initialLabels = array_map(AttributeLabel::fromString(...), $command->optionLabels);
                $this->defineChoice(
                    Attribute::defineChoice($id, $categoryId, $code, $label, $command->required, $initialLabels, $codesInBranch),
                    $initialLabels,
                );

                return $id;
            }

            $this->attributes->save(match ($type) {
                AttributeType::Number => Attribute::defineNumber($id, $categoryId, $code, $label, $command->required, NumberRules::of(
                    null === $command->unit ? null : Unit::fromString($command->unit),
                    $command->minimum,
                    $command->maximum,
                ), $codesInBranch),
                AttributeType::Boolean => Attribute::defineBoolean($id, $categoryId, $code, $label, $command->required, $codesInBranch),
                AttributeType::Text => Attribute::defineText($id, $categoryId, $code, $label, $command->required, $codesInBranch),
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

    /**
     * The codes in force along `$categoryId`'s branch: its lineage (itself and every ancestor) plus
     * its descendants. Copied in `EditAttributeHandler`; extract when a third caller appears.
     */
    private function codesInBranchOf(CategoryId $categoryId): AttributeCodes
    {
        return $this->attributes->codesDefinedOn([
            ...$this->categories->lineageOf($categoryId)->ids(),
            ...$this->categories->descendantsOf($categoryId),
        ]);
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
