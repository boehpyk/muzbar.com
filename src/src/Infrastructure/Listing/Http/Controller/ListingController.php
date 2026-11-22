<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Http\Controller;

use App\Infrastructure\Catalog\Persistence\Doctrine\DbalAttributeSchema;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalCategoryOutline;
use App\Infrastructure\Listing\Form\LeafCategoryChoices;
use App\Infrastructure\Listing\Persistence\Doctrine\DbalSellerDrafts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public listing page — the first page of muzbar anyone but its author can see (listing-publish
 * D10, D11). No `#[IsGranted]` and no `access_control` line: public by design.
 *
 * THE RULES THIS CLASS KEEPS:
 * - **One 404 for everything that is not published** — a draft, a withdrawn listing, an unknown id,
 *   a malformed id — for every viewer, the seller included (Q5). `findPublished()` decides in SQL.
 *   The route has **no `requirements`**: a route-level 404 and this controller's 404 would differ.
 * - **No seller data is ever read**, so none can be rendered (Constitution §8).
 * - **At most five queries** whatever the schema: the listing, the outline, the effective schema,
 *   the stored values, and the labels of exactly the stored options (`optionLabels()`, never a whole
 *   option list). Labels come from Catalog's read model — the fifth cross-context crossing.
 * - **No GET writes** (ADR-0015).
 */
final class ListingController extends AbstractController
{
    public function __construct(
        private readonly DbalSellerDrafts $listings,
        private readonly DbalCategoryOutline $outline,
        private readonly DbalAttributeSchema $schema,
    ) {
    }

    #[Route('/listings/{id}', name: 'app_listing_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $listing = $this->listings->findPublished($id) ?? throw $this->createNotFoundException();

        return $this->render('listing/show.html.twig', [
            'listing' => $listing,
            'path' => LeafCategoryChoices::paths($this->outline->rows())[$listing['categoryId']] ?? null,
            'details' => $this->details($listing['id'], $listing['categoryId']),
        ]);
    }

    /**
     * One label/value pair per stored value, in the effective schema's order (root first, then
     * label). A dormant value — its attribute archived or moved out of the lineage (L-17) — is not
     * shown, on purpose: `effectiveFor()` is active-only. A stale value whose option was archived
     * *is* shown, because `optionLabels()` labels archived options too (ADR-0022, Q5). A value whose
     * option has no row at all is not shown (legacy data only: C-26 refuses hard-deleting one in use).
     *
     * @return list<array{label: string, value: string}>
     */
    private function details(string $listingId, string $categoryId): array
    {
        $values = $this->listings->valuesOf($listingId);

        if ([] === $values) {
            return [];
        }

        $schema = $this->schema->effectiveFor($categoryId);
        $labels = $this->schema->optionLabels(array_values(array_map(
            static fn (array $row): string => $values[$row['id']],
            array_filter($schema, static fn (array $row): bool => 'choice' === $row['type'] && isset($values[$row['id']])),
        )));

        $details = [];

        foreach ($schema as $attribute) {
            $value = $values[$attribute['id']] ?? null;

            $shown = match (true) {
                null === $value => null,
                'choice' === $attribute['type'] => $labels[$value] ?? null,
                'number' === $attribute['type'] && null !== $attribute['unit'] => $value.' '.$attribute['unit'],
                'boolean' === $attribute['type'] => 'yes' === $value ? 'Yes' : 'No',
                default => $value,
            };

            if (null !== $shown) {
                $details[] = ['label' => $attribute['label'], 'value' => $shown];
            }
        }

        return $details;
    }
}
