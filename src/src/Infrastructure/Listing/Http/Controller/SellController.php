<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Http\Controller;

use App\Application\Listing\Command\DraftListing;
use App\Application\Listing\Command\ReviseListing;
use App\Application\Listing\Handler\DraftListingHandler;
use App\Application\Listing\Handler\ReviseListingHandler;
use App\Domain\Listing\Exception\CategoryIsNotALeaf;
use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\InvalidAttributeValueReason;
use App\Domain\Listing\Exception\InvalidListingDescription;
use App\Domain\Listing\Exception\InvalidListingTitle;
use App\Domain\Listing\Exception\InvalidPrice;
use App\Domain\Listing\Exception\ListingNotFound;
use App\Domain\Listing\Exception\NotTheSeller;
use App\Domain\Listing\Exception\UnknownCategory;
use App\Domain\Listing\ValueObject\Currency;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalAttributeSchema;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalCategoryOutline;
use App\Infrastructure\Identity\Security\SecurityUser;
use App\Infrastructure\Listing\Form\LeafCategoryChoices;
use App\Infrastructure\Listing\Form\ListingFormData;
use App\Infrastructure\Listing\Form\ListingFormType;
use App\Infrastructure\Listing\Persistence\Doctrine\DbalSellerDrafts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClearableErrorsInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A seller's private listing surface: draft, list, edit (plan D13). Unstyled semantic markup (Q7).
 *
 * `#[IsGranted]` is belt and braces with `security.yaml`'s anchored `^/sell(/|$)` rule, as for
 * `/account`. THE RULES THIS CLASS KEEPS:
 * - **The seller is always `SecurityUser::id()`**, never anything from the request (plan D8).
 * - **Someone else's draft is a 404, identical to an unknown one** (AC-29): a 403 would confirm it
 *   exists. The edit page checks ownership through `DbalSellerDrafts::find()` *before* the handler
 *   runs, on GET and POST alike — the handler builds the new values before `revise()` checks L-6,
 *   so without the pre-check a non-owner posting a bad title would get a 422 that leaks existence.
 * - **No GET writes** (ADR-0015); every write is a POST with the form's CSRF token, then a 303.
 * - **Domain refusals are not logged**: they are user errors (AC-32), mapped to field errors with
 *   the failure contract's wording.
 * - **The Details section is rendered from Catalog's admin read model** (`DbalAttributeSchema`,
 *   listing-attribute-values plan D11 — the fourth cross-context crossing), while the Domain decides
 *   on `Categories::schemaOf()` read again under the lock at POST. A schema change in between only
 *   produces a refusal: fail closed.
 */
#[Route('/sell')]
#[IsGranted('ROLE_USER')]
final class SellController extends AbstractController
{
    private const string SAVED = 'Draft saved.';
    private const string NOT_A_LEAF = 'That category now has subcategories. Choose a more specific one.';
    private const string ADD_DETAILS = 'Draft saved. Add the details below.';
    private const string REFILED = 'Draft saved. The details now follow “%s”: check them below.';

    public function __construct(
        private readonly DbalCategoryOutline $outline,
        private readonly DbalSellerDrafts $drafts,
        private readonly DbalAttributeSchema $schema,
    ) {
    }

    #[Route('', name: 'app_sell', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] SecurityUser $user, DraftListingHandler $draftListing): Response
    {
        $rows = $this->outline->rows();
        $paths = LeafCategoryChoices::paths($rows);
        $leaves = LeafCategoryChoices::leaves($rows);

        // A non-empty tree always has a leaf, so no leaves means no categories (AC-24).
        if ([] === $leaves) {
            return $this->render('listing/sell.html.twig', ['form' => null]);
        }

        $data = new ListingFormData();
        $form = $this->createForm(ListingFormType::class, $data, ['category_choices' => $leaves]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->explainStaleCategory($form, $paths, $leaves);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $listingId = $draftListing(new DraftListing(
                    $user->id(),
                    (string) $data->category,
                    (string) $data->title,
                    (string) $data->description,
                    (string) $data->price,
                    (string) $data->currency,
                ));

                // The details depend on the category, unknown until now (Q4): the edit page asks.
                $this->addFlash('success', self::ADD_DETAILS);

                return $this->redirectToRoute('app_sell_draft_edit', ['id' => $listingId->toString()], Response::HTTP_SEE_OTHER);
            } catch (InvalidListingTitle|InvalidListingDescription|InvalidPrice|UnknownCategory|CategoryIsNotALeaf $e) {
                $this->addRefusal($form, $e, $data);
            }
        }

        return $this->render('listing/sell.html.twig', ['form' => $form], $this->statusFor($form));
    }

    #[Route('/drafts', name: 'app_sell_drafts', methods: ['GET'])]
    public function drafts(#[CurrentUser] SecurityUser $user): Response
    {
        // Two queries for the page's data (AC-27): the drafts, and one outline for every path.
        return $this->render('listing/drafts.html.twig', [
            'drafts' => $this->drafts->forSeller($user->id()),
            'paths' => LeafCategoryChoices::paths($this->outline->rows()),
        ]);
    }

    #[Route('/drafts/{id}/edit', name: 'app_sell_draft_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, #[CurrentUser] SecurityUser $user, ReviseListingHandler $reviseListing): Response
    {
        // Ownership first, on GET and POST alike (see the class docblock).
        $draft = $this->drafts->find($id, $user->id()) ?? throw $this->createNotFoundException();

        $rows = $this->outline->rows();
        $paths = LeafCategoryChoices::paths($rows);
        $leaves = LeafCategoryChoices::leaves($rows);

        // A draft found on a non-leaf (legacy data; impossible through the app) keeps its category
        // on offer, so it can be edited without re-filing (failure contract, AC-5).
        $choices = $leaves;

        if (isset($paths[$draft['categoryId']])) {
            $choices[$draft['categoryId']] = $paths[$draft['categoryId']];
        }

        $data = new ListingFormData();
        $data->title = $draft['title'];
        $data->description = $draft['description'];
        $data->price = $draft['price'];
        $data->currency = $draft['currency'];
        $data->category = $draft['categoryId'];

        // The page's data is five queries however many choice attributes there are (AC-25): the
        // draft, the outline, the effective schema, every option of every choice, the stored values.
        $schema = $this->detailsSchema($draft['categoryId']);
        // Only values for fields on the form: a stray key (a legacy row for an attribute no longer in
        // force) would be submitted back and refused as "no longer applies".
        $data->attributes = [] === $schema ? [] : array_intersect_key(
            $this->drafts->valuesOf($draft['id']),
            array_flip(array_column($schema, 'id')),
        );

        $form = $this->createForm(ListingFormType::class, $data, ['category_choices' => $choices, 'schema' => $schema]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->explainStaleCategory($form, $paths, $choices);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $reviseListing(new ReviseListing(
                    $draft['id'],
                    $user->id(),
                    (string) $data->category,
                    (string) $data->title,
                    (string) $data->description,
                    (string) $data->price,
                    (string) $data->currency,
                    // An empty field is no value (L-11); omitting it also keeps an empty field whose
                    // attribute left the schema from being refused for nothing.
                    array_filter($data->attributes, static fn (?string $value): bool => null !== $value && '' !== $value),
                    publish: false, // until listing-publish T13 reads the clicked button
                ));

                if ($data->category !== $draft['categoryId']) {
                    // Re-filed: the aggregate dropped what the new category does not ask (L-10), and
                    // the new category may ask more — so back to the form, not the list (AC-21).
                    $this->addFlash('success', \sprintf(self::REFILED, self::nameOf($rows, (string) $data->category)));

                    return $this->redirectToRoute('app_sell_draft_edit', ['id' => $draft['id']], Response::HTTP_SEE_OTHER);
                }

                $this->addFlash('success', self::SAVED);

                return $this->redirectToRoute('app_sell_drafts', [], Response::HTTP_SEE_OTHER);
            } catch (NotTheSeller|ListingNotFound) {
                // Unreachable after `find()` unless the row changed hands or vanished mid-request;
                // the same 404 either way.
                throw $this->createNotFoundException();
            } catch (InvalidListingTitle|InvalidListingDescription|InvalidPrice|UnknownCategory|CategoryIsNotALeaf $e) {
                $this->addRefusal($form, $e, $data);
            } catch (InvalidAttributeValue $e) {
                $this->addDetailRefusal($form, $e);
            }
        }

        return $this->render('listing/edit.html.twig', ['form' => $form, 'draft' => $draft], $this->statusFor($form));
    }

    /**
     * The Details sub-form's rows: the effective schema (root first, then label) with each choice
     * attribute's options, fetched for all of them in one query.
     *
     * @return list<array{id: string, label: string, type: string, required: bool, unit: ?string, options: list<array{id: string, label: string}>}>
     */
    private function detailsSchema(string $categoryId): array
    {
        $rows = $this->schema->effectiveFor($categoryId);
        $options = $this->schema->optionsFor(array_column(
            array_filter($rows, static fn (array $row): bool => 'choice' === $row['type']),
            'id',
        ));

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'label' => $row['label'],
            'type' => $row['type'],
            'required' => $row['required'],
            'unit' => $row['unit'],
            'options' => $options[$row['id']] ?? [],
        ], $rows);
    }

    /**
     * @param list<array{id: string, parentId: ?string, name: string, slug: string, depth: int}> $rows
     */
    private static function nameOf(array $rows, string $categoryId): string
    {
        return array_column($rows, 'name', 'id')[$categoryId] ?? 'the new category';
    }

    /**
     * L-9's refusals, on the attribute's field in the failure contract's words. A key with no field
     * (a tampered or since-deleted attribute) lands on the form itself.
     *
     * @param FormInterface<ListingFormData> $form
     */
    private function addDetailRefusal(FormInterface $form, InvalidAttributeValue $refusal): void
    {
        $message = match ($refusal->reason()) {
            InvalidAttributeValueReason::NotAnOption => ListingFormType::NOT_AN_OPTION,
            InvalidAttributeValueReason::NotAWholeNumber => 'Enter a whole number, like 6.',
            InvalidAttributeValueReason::OutOfRange => match (true) {
                null !== $refusal->min() && null !== $refusal->max() => \sprintf('Enter a whole number from %d to %d.', $refusal->min(), $refusal->max()),
                null !== $refusal->min() => \sprintf('Enter a whole number of at least %d.', $refusal->min()),
                null !== $refusal->max() => \sprintf('Enter a whole number of at most %d.', $refusal->max()),
                default => 'Enter a whole number, like 6.',
            },
            InvalidAttributeValueReason::NotYesOrNo => ListingFormType::NOT_YES_OR_NO,
            InvalidAttributeValueReason::BadText => 'Keep this to one line of up to 200 characters.',
            InvalidAttributeValueReason::NotInForce => 'This detail no longer applies. Reload the page.',
        };

        $details = $form->has('attributes') ? $form->get('attributes') : null;
        $field = null !== $details && $details->has($refusal->attributeKey()) ? $details->get($refusal->attributeKey()) : $form;
        $field->addError(new FormError($message));
    }

    /**
     * ChoiceType refuses every value it does not offer with one message. One of those values
     * deserves another: a category that exists but is no longer a leaf — it gained a subcategory
     * since the form loaded. Same wording as the Domain's `CategoryIsNotALeaf`, which still decides
     * in the race window between this read and the handler's lock.
     *
     * @param FormInterface<ListingFormData> $form
     * @param array<string, string>          $paths   every category
     * @param array<string, string>          $offered the categories the form offered
     */
    private function explainStaleCategory(FormInterface $form, array $paths, array $offered): void
    {
        $field = $form->get('category');
        $submitted = $field->getViewData();

        if ($field instanceof ClearableErrorsInterface && !$field->isValid() && \is_string($submitted) && isset($paths[$submitted]) && !isset($offered[$submitted])) {
            $field->clearErrors();
            $field->addError(new FormError(self::NOT_A_LEAF));
        }
    }

    /**
     * The failure contract (feature spec): each refusal on its field, in the user's words.
     *
     * @param FormInterface<ListingFormData> $form
     */
    private function addRefusal(FormInterface $form, \DomainException $refusal, ListingFormData $data): void
    {
        [$field, $message] = match (true) {
            $refusal instanceof InvalidListingTitle => ['title', 'Give the listing a title of 3 to 120 characters, on one line.'],
            $refusal instanceof InvalidListingDescription => ['description', 'Keep the description under 5000 characters, in plain text.'],
            // `InvalidPrice` covers both parts of a price; an unknown currency code is the currency's
            // fault, anything else the amount's. (ChoiceType normally stops a bad code first.)
            $refusal instanceof InvalidPrice && null === Currency::tryFrom((string) $data->currency) => ['currency', ListingFormType::UNSUPPORTED_CURRENCY],
            $refusal instanceof InvalidPrice => ['price', 'Enter a price like 1250 or 1250.50, up to 9 999 999.99.'],
            $refusal instanceof UnknownCategory => ['category', ListingFormType::UNKNOWN_CATEGORY],
            $refusal instanceof CategoryIsNotALeaf => ['category', self::NOT_A_LEAF],
            default => throw $refusal,
        };

        $form->get($field)->addError(new FormError($message));
    }

    /** @param FormInterface<ListingFormData> $form */
    private function statusFor(FormInterface $form): Response
    {
        // Reaching the render after a submit means it was refused, by the form or by the domain.
        return new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
