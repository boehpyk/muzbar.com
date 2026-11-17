<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Http\Controller;

use App\Application\Listing\Command\DraftListing;
use App\Application\Listing\Command\ReviseListing;
use App\Application\Listing\Handler\DraftListingHandler;
use App\Application\Listing\Handler\ReviseListingHandler;
use App\Domain\Listing\Exception\CategoryIsNotALeaf;
use App\Domain\Listing\Exception\InvalidListingDescription;
use App\Domain\Listing\Exception\InvalidListingTitle;
use App\Domain\Listing\Exception\InvalidPrice;
use App\Domain\Listing\Exception\ListingNotFound;
use App\Domain\Listing\Exception\NotTheSeller;
use App\Domain\Listing\Exception\UnknownCategory;
use App\Domain\Listing\ValueObject\Currency;
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
 */
#[Route('/sell')]
#[IsGranted('ROLE_USER')]
final class SellController extends AbstractController
{
    private const string SAVED = 'Draft saved.';
    private const string NOT_A_LEAF = 'That category now has subcategories. Choose a more specific one.';

    public function __construct(
        private readonly DbalCategoryOutline $outline,
        private readonly DbalSellerDrafts $drafts,
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
                $draftListing(new DraftListing(
                    $user->id(),
                    (string) $data->category,
                    (string) $data->title,
                    (string) $data->description,
                    (string) $data->price,
                    (string) $data->currency,
                ));

                $this->addFlash('success', self::SAVED);

                return $this->redirectToRoute('app_sell_drafts', [], Response::HTTP_SEE_OTHER);
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

        $form = $this->createForm(ListingFormType::class, $data, ['category_choices' => $choices]);
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
                ));

                $this->addFlash('success', self::SAVED);

                return $this->redirectToRoute('app_sell_drafts', [], Response::HTTP_SEE_OTHER);
            } catch (NotTheSeller|ListingNotFound) {
                // Unreachable after `find()` unless the row changed hands or vanished mid-request;
                // the same 404 either way.
                throw $this->createNotFoundException();
            } catch (InvalidListingTitle|InvalidListingDescription|InvalidPrice|UnknownCategory|CategoryIsNotALeaf $e) {
                $this->addRefusal($form, $e, $data);
            }
        }

        return $this->render('listing/edit.html.twig', ['form' => $form, 'draft' => $draft], $this->statusFor($form));
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
