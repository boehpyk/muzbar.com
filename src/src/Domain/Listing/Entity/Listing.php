<?php

declare(strict_types=1);

namespace App\Domain\Listing\Entity;

use App\Domain\Listing\Event\ListingPublished;
use App\Domain\Listing\Exception\CategoryIsNotALeaf;
use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\ListingIncomplete;
use App\Domain\Listing\Exception\ListingNotPublished;
use App\Domain\Listing\Exception\NotTheSeller;
use App\Domain\Listing\Exception\PublishedListingCannotBeRefiled;
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
use App\Domain\Shared\Event\RecordsEvents;

/**
 * A seller's advertisement for one piece of gear (ADR-0019). It refers to its seller and its category **by id** (ADR-0009 decision 4), each in
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
 * THE LIFECYCLE (L-12, ADR-0021). `Draft → Published` and `Withdrawn → Published` by `publish()`;
 * `Published → Withdrawn` by `withdraw()`; nothing returns to `Draft`. A draft and a withdrawn
 * listing are private to their seller; a published one is public. `publishedAt` is null exactly when
 * the listing was never published (L-16), kept on withdraw, moved forward on publish-again.
 *
 * THE L-13 CONTRACT (completeness) — an invariant whose force depends on the state. Every attribute
 * marked `required` in the category's effective schema **current at the write** must have a value
 * when the listing becomes public (`publish()`) and whenever it is revised while public. Never on a
 * draft or a withdrawn listing (L-11). And **never retroactively**: the rule is checked on writes,
 * so an admin who later marks an attribute required, or narrows a bound, makes a published listing
 * incomplete only when its seller next touches it. That is the design (roadmap D11.2), not a gap —
 * do not add a sweep.
 *
 * Events: `publish()` records `ListingPublished` (not on a no-op); nothing else records. The handler
 * releases them inside `Categories::holdSteady()` and dispatches after it returns — after commit,
 * not merely after `save()` (ADR-0008 amendment, plan D5).
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
    use RecordsEvents;

    private function __construct(
        private ListingId $id,
        private SellerId $sellerId,
        private CategoryId $categoryId,
        private ListingTitle $title,
        private ListingDescription $description,
        private Price $price,
        private ListingStatus $status,
        private AttributeValues $attributeValues,
        private ?\DateTimeImmutable $publishedAt,
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

        return new self($id, $sellerId, $categoryId, $title, $description, $price, ListingStatus::Draft, AttributeValues::none(), null);
    }

    /**
     * Replaces everything the edit form submits at once, details included. Every check happens
     * before any assignment, so a refused revise mutates nothing.
     *
     * Order: L-6 (the seller) → L-15, only when the category changes → L-2, only when the category
     * changes → L-10, only when the category changes → L-9 → L-13, only while published → assign. Keeping the current category skips L-2 — the
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
     * L-15: a published listing cannot be re-filed — its form was rendered for the old category, so
     * the new one's required details could not have been filled. Withdraw, re-file, publish again.
     *
     * L-13 bites only while published (L-11): a draft or a withdrawn listing saves with required
     * details empty. Narrowed bounds are never retroactive — a stored value outside them stays until
     * this listing's next write, which reads it against the current rule and refuses it.
     *
     * @throws NotTheSeller
     * @throws PublishedListingCannotBeRefiled
     * @throws UnknownCategory
     * @throws CategoryIsNotALeaf
     * @throws InvalidAttributeValue
     * @throws ListingIncomplete
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
            if (ListingStatus::Published === $this->status) {
                throw PublishedListingCannotBeRefiled::for($this->id);
            }

            self::assertFileableUnder($categoryId, $categoryStatus);
            $input = $input->onlyFor($schema);
        }

        $attributeValues = $schema->read($input);

        if (ListingStatus::Published === $this->status) {
            self::assertComplete($schema, $attributeValues);
        }

        $this->categoryId = $categoryId;
        $this->title = $title;
        $this->description = $description;
        $this->price = $price;
        $this->attributeValues = $attributeValues;
    }

    /**
     * Makes the listing public. From `Draft` or `Withdrawn`; on a published listing a no-op that
     * records nothing (ADR-0008 decision 7: idempotency lives here, so a double-submit needs no
     * handler branch). Every check happens before any assignment or event.
     *
     * Order: L-6 → no-op → L-2 → L-9 on the stored values → L-13 → assign + record.
     *
     * L-14: what the listing claims about its category is re-checked **unconditionally**, even if
     * nothing changed since the last save — `$categoryStatus` and `$schema` describe the listing's
     * own category, read inside `holdSteady()`. The stored values are re-read through the one parser
     * (`AttributeValue::toRaw()` → `AttributeSchema::read()`), so a refusal is the same
     * `InvalidAttributeValue` as on edit. It does not trust that a `revise()` just ran (ADR-0012
     * d.2: the Domain owns the part that can be wrong — the next caller may be an import).
     *
     * @throws NotTheSeller
     * @throws UnknownCategory
     * @throws CategoryIsNotALeaf
     * @throws InvalidAttributeValue
     * @throws ListingIncomplete
     */
    public function publish(SellerId $by, CategoryStatus $categoryStatus, AttributeSchema $schema, \DateTimeImmutable $now): void
    {
        if (!$this->isSoldBy($by)) {
            throw NotTheSeller::of($this->id);
        }

        if (ListingStatus::Published === $this->status) {
            return;
        }

        self::assertFileableUnder($this->categoryId, $categoryStatus);
        $schema->read(self::asInput($this->attributeValues));
        self::assertComplete($schema, $this->attributeValues);

        $this->status = ListingStatus::Published;
        $this->publishedAt = $now;
        $this->recordThat(new ListingPublished($this->id, $this->sellerId, $this->categoryId, $now));
    }

    /**
     * Takes a published listing down; its data, `publishedAt` included, is kept. On a withdrawn
     * listing a no-op; on a draft refused — it was never public (L-12). No event: nothing consumes
     * one (plan Q3).
     *
     * @throws NotTheSeller
     * @throws ListingNotPublished
     */
    public function withdraw(SellerId $by): void
    {
        if (!$this->isSoldBy($by)) {
            throw NotTheSeller::of($this->id);
        }

        if (ListingStatus::Draft === $this->status) {
            throw ListingNotPublished::cannotWithdraw($this->id);
        }

        $this->status = ListingStatus::Withdrawn;
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

    /** The moment the listing last became public; null exactly when it never was (L-16). */
    public function publishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * L-13.
     *
     * @throws ListingIncomplete
     */
    private static function assertComplete(AttributeSchema $schema, AttributeValues $values): void
    {
        $missing = $schema->missingFrom($values);

        if ([] !== $missing) {
            throw ListingIncomplete::missing($missing);
        }
    }

    /** The stored values as the seller's text again — the round trip `AttributeValue::toRaw()` promises. */
    private static function asInput(AttributeValues $values): AttributeInput
    {
        $raw = [];

        foreach ($values->toList() as $value) {
            $raw[$value->attributeId()->toString()] = $value->toRaw();
        }

        return AttributeInput::fromArray($raw);
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
