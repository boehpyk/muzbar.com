<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Http\Controller;

use App\Application\Listing\Command\DraftListing;
use App\Application\Listing\Command\ReviseListing;
use App\Application\Listing\Command\WithdrawListing;
use App\Application\Listing\Handler\DraftListingHandler;
use App\Application\Listing\Handler\ReviseListingHandler;
use App\Application\Listing\Handler\WithdrawListingHandler;
use App\Domain\Listing\Exception\CategoryIsNotALeaf;
use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\InvalidAttributeValueReason;
use App\Domain\Listing\Exception\InvalidListingDescription;
use App\Domain\Listing\Exception\InvalidListingTitle;
use App\Domain\Listing\Exception\InvalidPrice;
use App\Domain\Listing\Exception\ListingIncomplete;
use App\Domain\Listing\Exception\ListingNotFound;
use App\Domain\Listing\Exception\ListingNotPublished;
use App\Domain\Listing\Exception\NotTheSeller;
use App\Domain\Listing\Exception\PublishedListingCannotBeRefiled;
use App\Domain\Listing\Exception\UnknownCategory;
use App\Domain\Listing\ValueObject\Currency;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalCategoryOutline;
use App\Infrastructure\Identity\Security\SecurityUser;
use App\Infrastructure\Listing\Form\LeafCategoryChoices;
use App\Infrastructure\Listing\Form\ListingFormBuilder;
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
 * A seller's private listing surface: draft, list, edit, publish, withdraw (plan D13, listing-publish). Unstyled semantic markup (Q7).
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
 * - **The form's categories and Details come from `ListingFormBuilder`** (listing-wizard D2), which
 *   reads Catalog's admin read model; the Domain decides on `Categories::schemaOf()` read again
 *   under the lock at POST. A schema change in between only produces a refusal: fail closed.
 */
#[Route('/sell')]
#[IsGranted('ROLE_USER')]
final class SellController extends AbstractController
{
    private const string NO_LONGER_OFFERED = '%s is no longer offered. Choose another.';
    private const string SAVED = 'Draft saved.';
    private const string NOT_A_LEAF = 'That category now has subcategories. Choose a more specific one.';
    private const string ADD_DETAILS = 'Draft saved. Add the details below.';
    private const string REFILED = 'Draft saved. The details now follow “%s”: check them below.';
    private const string PUBLISHED = 'Published. This is what buyers see.';
    private const string UPDATED = 'Saved. Your listing is updated.';
    private const string REFILED_NOT_PUBLISHED = 'Saved, not published yet. The details now follow “%s”: check them, then publish.';
    private const string FILL_TO_PUBLISH = 'Fill this in to publish.';
    private const string NEW_REQUIRED_DETAIL = 'A new detail is now required. Reload the page.';
    private const string WITHDRAWN = 'Withdrawn. It is no longer public.';
    private const string NOT_PUBLISHED = 'Only a published listing can be withdrawn.';

    public function __construct(
        private readonly DbalCategoryOutline $outline,
        private readonly DbalSellerDrafts $drafts,
        private readonly ListingFormBuilder $forms,
    ) {
    }

    #[Route('', name: 'app_sell', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] SecurityUser $user, DraftListingHandler $draftListing): Response
    {
        $rows = $this->outline->rows();
        $paths = LeafCategoryChoices::paths($rows);
        $leaves = $this->forms->choices($rows);

        // A non-empty tree always has a leaf, so no leaves means no categories (AC-24).
        if ([] === $leaves) {
            return $this->render('listing/sell.html.twig', ['form' => null]);
        }

        $data = new ListingFormData();
        // The category the page's Details were built for (D4): none on a GET or a JS-free first
        // submit, so the form binds no `attributes` and an `attributes` posted then is an extra field.
        $body = $request->request->all();
        $detailsFor = self::detailsFor($body, $leaves);
        [$schema, $noLongerOffered] = $this->forms->details($leaves, $detailsFor);
        $form = $this->createForm(ListingFormType::class, $data, [
            'category_choices' => $leaves,
            'schema' => $schema,
            'details_for' => $detailsFor ?? '',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->explainStaleCategory($form, $paths, $leaves);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // The seller saw the Details of the category they are filing under: forward them and
            // honour "Save and publish". Otherwise (JS-free, a race, a tamper) a plain draft (D4).
            $withDetails = null !== $detailsFor && $detailsFor === $data->category;
            $publish = $withDetails && self::publishClicked($body);

            try {
                $listingId = $draftListing(new DraftListing(
                    $user->id(),
                    (string) $data->category,
                    (string) $data->title,
                    (string) $data->description,
                    (string) $data->price,
                    (string) $data->currency,
                    attributes: $withDetails ? self::filled($data->attributes) : [],
                    publish: $publish,
                ));

                if ($publish) {
                    $this->addFlash('success', self::PUBLISHED);

                    return $this->redirectToRoute('app_listing_show', ['id' => $listingId->toString()], Response::HTTP_SEE_OTHER);
                }

                // Without the Details the category's fields were never on the page (Q4): the edit page asks.
                $this->addFlash('success', $withDetails ? self::SAVED : self::ADD_DETAILS);

                return $this->redirectToRoute('app_sell_draft_edit', ['id' => $listingId->toString()], Response::HTTP_SEE_OTHER);
            } catch (InvalidListingTitle|InvalidListingDescription|InvalidPrice|UnknownCategory|CategoryIsNotALeaf $e) {
                $this->addRefusal($form, $e, $data);
            } catch (InvalidAttributeValue $e) {
                $this->addDetailRefusal($form, $e, $data->attributes, $noLongerOffered);
            } catch (ListingIncomplete $e) {
                $this->addIncompleteRefusal($form, $e);
            }
        }

        return $this->render('listing/sell.html.twig', [
            'form' => $form,
            // "Save and publish" only beside Details the seller can see (an empty schema counts).
            'with_details' => null !== $detailsFor,
        ], $this->statusFor($form));
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
        $choices = $this->forms->choices($rows, $draft['categoryId']);

        $data = new ListingFormData();
        $data->title = $draft['title'];
        $data->description = $draft['description'];
        $data->price = $draft['price'];
        $data->currency = $draft['currency'];
        $data->category = $draft['categoryId'];

        // The page's data is five queries however many choice attributes there are (AC-25): the
        // draft, the outline, the stored values, the effective schema, every option of every choice.
        // The values come first so a stored choice whose option was archived is still among the
        // options (`keep`, ADR-0022 D10): it renders selected as "… (no longer offered)" instead of
        // an empty select that "blank = clear" would silently drop on save.
        $stored = $this->drafts->valuesOf($draft['id']);
        // The POST binds the Details the page showed (D4): a page morphed to category B posts B's
        // fields. A missing or tampered `details_for` means the stored category's, as on the GET.
        $body = $request->request->all();
        $detailsFor = self::detailsFor($body, $choices) ?? (isset($choices[$draft['categoryId']]) ? $draft['categoryId'] : null);
        [$schema, $noLongerOffered] = $this->forms->details($choices, $detailsFor, $stored);
        // Only values for fields on the form: a dormant value (its attribute archived, or moved out
        // of the lineage) would be submitted back and refused as "no longer applies". It is not lost:
        // revise carries it over while the category is unchanged (L-17).
        $data->attributes = array_intersect_key($stored, array_flip(array_column($schema, 'id')));

        $form = $this->createForm(ListingFormType::class, $data, [
            'category_choices' => $choices,
            'schema' => $schema,
            'published' => 'published' === $draft['status'],
            // The category `details()` built for; `''` when it was not offered (nothing built).
            'details_for' => $detailsFor ?? '',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->explainStaleCategory($form, $paths, $choices);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $wasPublished = 'published' === $draft['status'];
            $refiled = $data->category !== $draft['categoryId'];
            // "Save and publish" (D12). Ignored on a published listing (already public) and when the
            // page did not show the Details of the category being filed under — a JS-free re-file
            // (listing-wizard D5, amending listing-publish Q2). Any other `action` value is a plain save.
            $publishClicked = self::publishClicked($body) && !$wasPublished;
            $publish = $publishClicked && $detailsFor === $data->category;

            try {
                $reviseListing(new ReviseListing(
                    $draft['id'],
                    $user->id(),
                    (string) $data->category,
                    (string) $data->title,
                    (string) $data->description,
                    (string) $data->price,
                    (string) $data->currency,
                    self::filled($data->attributes),
                    $publish,
                ));

                if ($publish) {
                    $this->addFlash('success', self::PUBLISHED);

                    return $this->redirectToRoute('app_listing_show', ['id' => $draft['id']], Response::HTTP_SEE_OTHER);
                }

                if ($refiled) {
                    // Re-filed: the aggregate dropped what the new category does not ask (L-10), and
                    // the new category may ask more — so back to the form, not the list (AC-21).
                    $this->addFlash('success', \sprintf($publishClicked ? self::REFILED_NOT_PUBLISHED : self::REFILED, $this->forms->nameOf($rows, (string) $data->category)));

                    return $this->redirectToRoute('app_sell_draft_edit', ['id' => $draft['id']], Response::HTTP_SEE_OTHER);
                }

                $this->addFlash('success', $wasPublished ? self::UPDATED : self::SAVED);

                return $this->redirectToRoute('app_sell_drafts', [], Response::HTTP_SEE_OTHER);
            } catch (NotTheSeller|ListingNotFound) {
                // Unreachable after `find()` unless the row changed hands or vanished mid-request;
                // the same 404 either way.
                throw $this->createNotFoundException();
            } catch (InvalidListingTitle|InvalidListingDescription|InvalidPrice|UnknownCategory|CategoryIsNotALeaf $e) {
                $this->addRefusal($form, $e, $data);
            } catch (InvalidAttributeValue $e) {
                $this->addDetailRefusal($form, $e, $data->attributes, $noLongerOffered);
            } catch (ListingIncomplete $e) {
                $this->addIncompleteRefusal($form, $e);
            } catch (PublishedListingCannotBeRefiled) {
                // L-15. Unreachable through the form (the field is disabled); the Domain's backstop.
                $form->get('category')->addError(new FormError(ListingFormType::PUBLISHED_CATEGORY));
            }
        }

        return $this->render('listing/edit.html.twig', ['form' => $form, 'draft' => $draft], $this->statusFor($form));
    }

    #[Route('/drafts/{id}/withdraw', name: 'app_sell_draft_withdraw', methods: ['POST'])]
    public function withdraw(string $id, Request $request, #[CurrentUser] SecurityUser $user, WithdrawListingHandler $withdrawListing): Response
    {
        // The token before the lookup (D13, the `CategoryAdminController::delete()` precedent): a bad
        // token is the same 403 for every id, so it reveals nothing about which ids exist.
        if (!$this->isCsrfTokenValid('withdraw-listing-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $listing = $this->drafts->find($id, $user->id()) ?? throw $this->createNotFoundException();

        try {
            // A withdrawn listing is a no-op, so a double-submit lands on the same success (L-12).
            $withdrawListing(new WithdrawListing($listing['id'], $user->id()));
            $this->addFlash('success', self::WITHDRAWN);
        } catch (NotTheSeller|ListingNotFound) {
            throw $this->createNotFoundException();
        } catch (ListingNotPublished) {
            // A draft: a stale tab or a tampered POST. A user error, not logged (AC-27).
            $this->addFlash('error', self::NOT_PUBLISHED);
        }

        return $this->redirectToRoute('app_sell_drafts', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * L-9's refusals, on the attribute's field in the failure contract's words. A key with no field
     * (a tampered or since-deleted attribute) lands on the form itself.
     *
     * `NotAnOption` on an archived option the page itself rendered (a stale stored choice) gets its
     * own wording; the Domain cannot tell "archived" from "never existed" and need not (ADR-0022 D10).
     *
     * @param FormInterface<ListingFormData> $form
     * @param array<string, ?string>         $submitted       the Details values, by attribute id
     * @param array<string, string>          $noLongerOffered archived option id => label, as rendered
     */
    private function addDetailRefusal(FormInterface $form, InvalidAttributeValue $refusal, array $submitted, array $noLongerOffered): void
    {
        $value = $submitted[$refusal->attributeKey()] ?? null;

        $message = match ($refusal->reason()) {
            InvalidAttributeValueReason::NotAnOption => null !== $value && isset($noLongerOffered[$value])
                ? \sprintf(self::NO_LONGER_OFFERED, $noLongerOffered[$value])
                : ListingFormType::NOT_AN_OPTION,
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
     * L-13: every missing required detail on its own field, all at once. An id with no field (an
     * attribute made required after the page loaded) is reported once, on the form itself.
     *
     * @param FormInterface<ListingFormData> $form
     */
    private function addIncompleteRefusal(FormInterface $form, ListingIncomplete $refusal): void
    {
        $details = $form->has('attributes') ? $form->get('attributes') : null;
        $unseen = false;

        foreach ($refusal->attributeIds() as $id) {
            if (null !== $details && $details->has($id->toString())) {
                $details->get($id->toString())->addError(new FormError(self::FILL_TO_PUBLISH));
            } else {
                $unseen = true;
            }
        }

        if ($unseen) {
            $form->addError(new FormError(self::NEW_REQUIRED_DETAIL));
        }
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

    /**
     * `details_for` from the raw body, kept only when it is an offered category (D4, AC-17). The
     * body's shape is the browser's: `listing_form` may be a string and `details_for` an array, so
     * nothing here goes through `InputBag`'s typed getters (which would 400). The raw value is
     * compared against the offered ids and never reaches a statement.
     *
     * @param array<mixed>          $body    `$request->request->all()`
     * @param array<string, string> $offered id => path label
     */
    private static function detailsFor(array $body, array $offered): ?string
    {
        $form = $body['listing_form'] ?? null;
        $value = \is_array($form) ? ($form['details_for'] ?? null) : null;

        return \is_string($value) && isset($offered[$value]) ? $value : null;
    }

    /** @param array<mixed> $body `$request->request->all()` */
    private static function publishClicked(array $body): bool
    {
        return 'publish' === ($body['action'] ?? null);
    }

    /**
     * An empty field is no value (L-11); omitting it also keeps an empty field whose attribute left
     * the schema from being refused for nothing.
     *
     * @param array<string, ?string> $attributes
     *
     * @return array<string, string>
     */
    private static function filled(array $attributes): array
    {
        return array_filter($attributes, static fn (?string $value): bool => null !== $value && '' !== $value);
    }

    /** @param FormInterface<ListingFormData> $form */
    private function statusFor(FormInterface $form): Response
    {
        // Reaching the render after a submit means it was refused, by the form or by the domain.
        return new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
