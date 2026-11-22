<?php

declare(strict_types=1);

namespace App\Infrastructure\Search\Http\Controller;

use App\Application\Search\Handler\BrowseListingsHandler;
use App\Application\Search\Query\BrowseListings;
use App\Domain\Search\Exception\UnknownCategory;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalCategoryOutline;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The browse page (search-browse): published listings at or below one category, newest first,
 * narrowed by facets. No `#[IsGranted]` and no `access_control` line: guests browse (PRD §6).
 *
 * THE RULES THIS CLASS KEEPS:
 * - **Shapes only, from `$request->query->all()` read once** (plan D8). Never `all('f')`, `get()` or
 *   `getInt()`: each throws `BadRequestException` — a 400 — on a hostile shape (`?f=x`,
 *   `?page[]=1`), and S-5 says a hostile filter is a 200 with fewer filters. Every *meaning* (formats,
 *   caps, schema) is the Domain's.
 * - **One 404** for a category that is unknown, malformed or not a string — one `throw`, so the
 *   bodies cannot drift (AC-21). The route has **no `requirements`**: a route-level 404 would differ.
 * - **Bounded statements** (AC-23): the handler's, plus one outline read — read **after** the
 *   handler, so a malformed category issues no statement at all.
 * - **No GET writes** (ADR-0015); nothing here writes anything.
 */
final class BrowseController extends AbstractController
{
    public function __construct(
        private readonly BrowseListingsHandler $browse,
        private readonly DbalCategoryOutline $outline,
    ) {
    }

    #[Route('/browse', name: 'app_browse', methods: ['GET'])]
    public function browse(Request $request): Response
    {
        $raw = $request->query->all();

        try {
            $result = ($this->browse)(new BrowseListings(self::category($raw['category'] ?? null), self::filters($raw['f'] ?? null), self::page($raw['page'] ?? null)));
        } catch (UnknownCategory) {
            throw $this->createNotFoundException();
        }

        $query = $result->query();
        $categoryId = $query->category()?->toString();

        // The surviving selections, as the query string carries them — what every link keeps (AC-24).
        $f = [];
        foreach ($query->selections() as $selection) {
            $f[$selection->attributeId()->toString()] = [
                ...array_map(static fn ($id): string => $id->toString(), $selection->optionIds()),
                ...array_map(static fn (bool $b): string => $b ? 'yes' : 'no', $selection->booleans()),
            ];
        }

        // Tolerant lookup: a category deleted since the handler's read renders without a breadcrumb.
        $rows = [];
        foreach ($this->outline->rows() as $row) {
            $rows[$row['id']] = $row;
        }

        $breadcrumb = [];
        for ($id = $categoryId; null !== $id && isset($rows[$id]); $id = $rows[$id]['parentId']) {
            array_unshift($breadcrumb, $rows[$id]);
        }

        return $this->render('search/browse.html.twig', [
            'result' => $result,
            'category' => null === $categoryId ? null : ($rows[$categoryId] ?? null),
            'categoryId' => $categoryId,
            'breadcrumb' => $breadcrumb,
            'children' => array_values(array_filter($rows, static fn (array $row): bool => $row['parentId'] === $categoryId)),
            'f' => $f,
            'perPage' => $query::PER_PAGE,
            'maxPage' => $query::MAX_PAGE,
        ]);
    }

    /** Absent or empty → all categories; anything else but a string → a malformed id (the 404). */
    private static function category(mixed $raw): ?string
    {
        return match (true) {
            null === $raw, '' === $raw => null,
            \is_string($raw) => $raw,
            default => '',
        };
    }

    /**
     * Keeps string-keyed arrays of string values; everything else is dropped.
     *
     * @return array<string, list<string>>
     */
    private static function filters(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $filters = [];
        foreach ($raw as $key => $values) {
            if (\is_string($key) && \is_array($values)) {
                $filters[$key] = array_values(array_filter($values, \is_string(...)));
            }
        }

        return $filters;
    }

    /** The Domain clamps; here only "an integer, or 1". */
    private static function page(mixed $raw): int
    {
        $page = \is_string($raw) ? filter_var($raw, \FILTER_VALIDATE_INT) : false;

        return false === $page ? 1 : $page;
    }
}
