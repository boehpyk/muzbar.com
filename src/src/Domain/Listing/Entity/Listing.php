<?php

declare(strict_types=1);

namespace App\Domain\Listing\Entity;

use App\Domain\Listing\Exception\CategoryIsNotALeaf;
use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\NotTheSeller;
use App\Domain\Listing\Exception\UnknownCategory;
use App\Domain\Listing\ValueObject\AttributeInput;
use App\Domain\Listing\ValueObject\AttributeSchema;
use App\Domain\Listing\ValueObject\AttributeValues;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\CategoryStatus;
use App\Domain\Listing\ValueObject\ListingDescription;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\ListingStatus;
use App\Domain\Listing\ValueObject\ListingTitle;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Listing\ValueObject\SellerId;

/**
 * A seller's advertisement for one piece of gear — in this slice only ever a private draft
 * (ADR-0019). It refers to its seller and its category **by id** (ADR-0009 decision 4), each in
 * Listing's own words (`SellerId`, Listing's `CategoryId`), never another context's type.
 *
 * THE L-2 CONTRACT (leaf-only, ADR-0019 decision 5). Whether the category exists and is a leaf is a
 * fact about the catalog, which this object cannot see. So it is handed a `CategoryStatus` and
 * **it decides**, as pure logic a unit test reaches without a database. What makes that status
 * current is `Categories::holdSteady()`: every Listing write reads the status and saves inside it,
 * holding the catalog lock in shared mode. And because a listing can stop being on a leaf without
 * being touched — an admin adds a subcategory under it — Catalog keeps the other half: C-23 and
 * C-24 refuse to delete, or give a child to, a category with listings filed under it. The
 * aggregate owns the rule, the lock owns the truth of its input, Catalog guards the doors this
 * object never sees.
 *
 * L-6 (only the seller revises) is decided here too, **first**: someone else's revise is refused
 * before anything about the new values is even looked at (ADR-0019 decision 8).
 *
 * No domain events (plan D9): a draft is private and nothing consumes one; `listing-publish`'s
 * consumer wants `ListingPublished`.
 *
 * Properties are plain `private`, not `readonly` (ADR-0007): Doctrine hydrates them.
 *
 * Except one: `$attributeValues` — the listing's "Details", its parts (ADR-0020 decision 1) — is
 * **not** Doctrine-mapped. The Domain may not hold a Doctrine collection, and the values belong in a
 * typed table for Phase 2's facets, so the repository writes them and a `postLoad` listener sets
 * them (decision 2). A load path that skipped the listener leaves the property uninitialised and
 * the first read throws — loud, never a silent empty set.
 */
final class Listing
{
    private function __construct(
        private ListingId $id,
        private SellerId $sellerId,
        private CategoryId $categoryId,
        private ListingTitle $title,
        private ListingDescription $description,
        private Price $price,
        private ListingStatus $status,
        private AttributeValues $attributeValues,
    ) {
    }

    /**
     * L-1: the seller is fixed here and never changes. L-7: a new listing is a draft, with no
     * details yet — the create form has none, because the category is not known until it is submitted.
     *
     * @throws UnknownCategory
     * @throws CategoryIsNotALeaf
     */
    public static function draft(
        ListingId $id,
        SellerId $sellerId,
        CategoryId $categoryId,
        CategoryStatus $categoryStatus,
        ListingTitle $title,
        ListingDescription $description,
        Price $price,
    ): self {
        self::assertFileableUnder($categoryId, $categoryStatus);

        return new self($id, $sellerId, $categoryId, $title, $description, $price, ListingStatus::Draft, AttributeValues::none());
    }

    /**
     * Replaces everything the edit form submits at once, details included. Every check happens
     * before any assignment, so a refused revise mutates nothing.
     *
     * Order: L-6 (the seller) → L-2, only when the category changes → L-10, only when the category
     * changes → L-9 → assign. Keeping the current category skips L-2 — the
     * `Category::moveUnder()`-to-current-parent shape — so a listing found on a non-leaf (legacy
     * data) can still be edited without being re-filed.
     *
     * THE L-9 CONTRACT (ADR-0020 decisions 3, 4, 6). `$schema` is the effective schema of the
     * **target** category (the new one on a re-file), a fact about the catalog this object cannot
     * see. The aggregate decides: every value is read against its attribute's rule, current at this
     * write. The shared lock (`Categories::holdSteady()`) makes the schema true until commit, and
     * Catalog's C-25/C-26/C-27 refuse the deletes and moves that would strand a stored value later.
     *
     * L-10: on a re-file the form was rendered for the *old* category, so inputs for attributes not
     * in force on the new one are **dropped**; with the category unchanged such an input is
     * **refused** — nothing a seller sees should vanish without a re-file to explain it.
     *
     * Deliberately not rules here: `required` is not enforced on a draft (L-11, `listing-publish`),
     * and narrowed bounds are never retroactive — a stored value outside them stays until this
     * listing's next write, which reads it against the current rule and refuses it.
     *
     * @throws NotTheSeller
     * @throws UnknownCategory
     * @throws CategoryIsNotALeaf
     * @throws InvalidAttributeValue
     */
    public function revise(
        SellerId $by,
        CategoryId $categoryId,
        CategoryStatus $categoryStatus,
        AttributeSchema $schema,
        ListingTitle $title,
        ListingDescription $description,
        Price $price,
        AttributeInput $input,
    ): void {
        if (!$this->isSoldBy($by)) {
            throw NotTheSeller::of($this->id);
        }

        if (!$categoryId->equals($this->categoryId)) {
            self::assertFileableUnder($categoryId, $categoryStatus);
            $input = $input->onlyFor($schema);
        }

        $attributeValues = $schema->read($input);

        $this->categoryId = $categoryId;
        $this->title = $title;
        $this->description = $description;
        $this->price = $price;
        $this->attributeValues = $attributeValues;
    }

    public function isSoldBy(SellerId $sellerId): bool
    {
        return $this->sellerId->equals($sellerId);
    }

    public function id(): ListingId
    {
        return $this->id;
    }

    public function sellerId(): SellerId
    {
        return $this->sellerId;
    }

    public function categoryId(): CategoryId
    {
        return $this->categoryId;
    }

    public function title(): ListingTitle
    {
        return $this->title;
    }

    public function description(): ListingDescription
    {
        return $this->description;
    }

    public function price(): Price
    {
        return $this->price;
    }

    public function status(): ListingStatus
    {
        return $this->status;
    }

    public function attributeValues(): AttributeValues
    {
        return $this->attributeValues;
    }

    /**
     * L-2.
     *
     * @throws UnknownCategory
     * @throws CategoryIsNotALeaf
     */
    private static function assertFileableUnder(CategoryId $categoryId, CategoryStatus $status): void
    {
        match ($status) {
            CategoryStatus::Missing => throw UnknownCategory::for($categoryId),
            CategoryStatus::Branch => throw CategoryIsNotALeaf::for($categoryId),
            CategoryStatus::Leaf => null,
        };
    }
}
