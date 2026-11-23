<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Application\Search\Handler\BrowseListingsHandler;
use App\Application\Search\Query\BrowseListings;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Search\ValueObject\Facet;
use App\Domain\Search\ValueObject\SearchResult;
use App\Domain\Search\ValueObject\TextMatch;
use App\Tests\Support\BuildsTheTextFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * search-full-text AC-9 … AC-15, through `BrowseListingsHandler` over `BuildsTheTextFixture`.
 * Real database, DAMA; the trigger (not PHP) wrote every `search_listing_text` row.
 *
 * Published: T1, T2, T4, T5, Tc on Electric and T3 on Bass — six listings, so a search text that
 * yields no terms (no filter) totals 6. Td (draft) and Tw (withdrawn) match `stratocaster` by title
 * and must never be found.
 */
final class FullTextSearchTest extends KernelTestCase
{
    use BuildsTheTextFixture;

    private const int ALL_PUBLISHED = 6;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->buildTheTextFixture();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, list<string>>                          $filters
     * @param array{currency: ?string, min: ?string, max: ?string} $price
     */
    private function search(?string $text, ?Category $category = null, array $filters = [], ?array $price = null): SearchResult
    {
        $handler = self::getContainer()->get(BrowseListingsHandler::class);
        self::assertInstanceOf(BrowseListingsHandler::class, $handler);

        return $handler(new BrowseListings($category?->id()->toString(), $filters, [], $price ?? ['currency' => null, 'min' => null, 'max' => null], $text, 1));
    }

    /**
     * @return list<string> hit ids, in page order
     */
    private function ids(SearchResult $result): array
    {
        return array_map(static fn ($hit): string => $hit->id(), $result->hits());
    }

    /**
     * @param list<string> $labels fixture labels (T1 …)
     *
     * @return list<string>
     */
    private function idsOf(array $labels): array
    {
        return array_map(fn (string $label): string => $this->listing[$label], $labels);
    }

    /**
     * @return array<string, int> bucket label => count of the facet called `$label`
     */
    private function buckets(SearchResult $result, string $label): array
    {
        $counts = [];
        foreach ($result->facets() as $facet) {
            if ($facet instanceof Facet && $label === $facet->attribute()->label()) {
                foreach ($facet->buckets() as $bucket) {
                    $counts[$bucket->label()] = $bucket->count();
                }
            }
        }

        return $counts;
    }

    private function brandOption(string $label): string
    {
        return $this->optionIdOf($this->brand, $label);
    }

    // ---------------------------------------------------------------- AC-9

    /**
     * @return iterable<string, array{string}>
     */
    public static function publishedLabels(): iterable
    {
        foreach (['T1', 'T2', 'T3', 'T4', 'T5', 'Tc'] as $label) {
            yield $label => [$label];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publishedLabels')]
    public function testSearchingAPublishedListingsOwnTitleFindsItExactly(string $label): void
    {
        $result = $this->search($this->publishedTitles[$label]);

        self::assertContains($this->listing[$label], $this->ids($result), \sprintf('"%s" was not found by its own title.', $this->publishedTitles[$label]));
        self::assertSame(TextMatch::Exact, $result->textMatch(), 'The round trip must be an exact match, not the typo fallback.');
    }

    public function testAPluralInANonLastTermIsFoundByTheSamePlural(): void
    {
        $result = $this->search('guitars fender');

        self::assertSame($this->idsOf(['T5']), $this->ids($result));
        self::assertSame(TextMatch::Exact, $result->textMatch());
    }

    // ---------------------------------------------------------------- AC-10

    /**
     * @return iterable<string, array{string, int}> search text => expected total
     */
    public static function hostileTexts(): iterable
    {
        // No surviving term: no filter at all, so every published listing.
        yield 'lone quote' => ["'", self::ALL_PUBLISHED];
        yield 'lone backslash' => ['\\', self::ALL_PUBLISHED];
        yield 'quotes' => ["''''", self::ALL_PUBLISHED];
        yield 'prefix operator only' => [':*', self::ALL_PUBLISHED];
        yield 'NUL only' => ["\0", self::ALL_PUBLISHED];
        yield 'invalid UTF-8' => ["\xC3\x28", self::ALL_PUBLISHED];
        // Terms that fold to tsquery syntax: parsed by the text parser, never as operators; nothing matches.
        yield 'modifier letter apostrophe' => ['ʼfoo', 0];
        yield 'modifier letter colon' => ['fooː1', 0];
        yield 'colon letter between terms' => ['x ː y', 0];
        yield 'ligature letters' => ['ŉ x¼', 0];
        yield 'apostrophe inside a word' => ['donʼt', 0];
        yield 'every operator' => ["a & b | !c <-> (d):* 'e' \\f", 0];
        yield 'NUL inside a word' => ["Fen\0der", 0];
        yield 'one 10 000 character word' => [str_repeat('x', 10000), 0];
        // A term that folds to no lexeme at all: an empty tsquery matches nothing (and the similar match finds nothing either).
        yield 'one 3 000 character word of letters that fold to nothing' => [str_repeat('ʼ', 3000), 0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileTexts')]
    public function testHostileSearchTextAnswersWithoutAnException(string $text, int $expectedTotal): void
    {
        $result = $this->search($text);

        self::assertSame($expectedTotal, $result->total());
    }

    public function testATermThatFoldsToNothingConstrainsNothingBesideAnotherTerm(): void
    {
        self::assertSame($this->ids($this->search('fender')), $this->ids($this->search('ʼ fender')));
        self::assertSame(3, $this->search('ʼ fender')->total());
    }

    public function testANegationOperatorIsNotANegation(): void
    {
        self::assertSame($this->ids($this->search('fender')), $this->ids($this->search('!fender')));
        self::assertSame(3, $this->search('!fender')->total());
    }

    public function testAnOrOperatorIsAnAnd(): void
    {
        $or = $this->search('fender | gibson');
        $and = $this->search('fender gibson');

        self::assertSame($this->ids($and), $this->ids($or));
        self::assertSame(0, $or->total(), 'No fixture title holds both words; an OR would find several.');
        self::assertSame(TextMatch::Similar, $or->textMatch());
    }

    public function testAPrefixOperatorTypedByTheVisitorAddsNothing(): void
    {
        $typed = $this->search('fender:*A');
        $plain = $this->search('fender A');

        self::assertSame($this->ids($plain), $this->ids($typed));
        self::assertSame($this->idsOf(['T5']), $this->ids($typed), '"a" is a prefix of T5\'s "ac" only; the "*" is not a wildcard.');
    }

    // ---------------------------------------------------------------- AC-11

    public function testWithoutACategoryAMatchInTheTitleOrTheDescriptionIsFoundButNeverADraftOrAWithdrawn(): void
    {
        $result = $this->search('stratocaster');

        self::assertEqualsCanonicalizing($this->idsOf(['T1', 'T2', 'T4']), $this->ids($result));
        self::assertSame(3, $result->total());
        self::assertNotContains($this->listing['Td'], $this->ids($result));
        self::assertNotContains($this->listing['Tw'], $this->ids($result));
    }

    public function testTheTextOnlyFindsListingsInTheBrowsedSubtree(): void
    {
        $result = $this->search('stratocaster', $this->bass);

        self::assertSame(0, $result->total());
        self::assertSame([], $result->hits());
        self::assertSame(TextMatch::Similar, $result->textMatch());
    }

    public function testTheTextNarrowsAFacetSelection(): void
    {
        $result = $this->search('fender', $this->electric, [$this->brand->id()->toString() => [$this->brandOption('Fender')]]);

        self::assertSame($this->idsOf(['T1']), $this->ids($result));
        self::assertSame(1, $result->total());
    }

    public function testTheTextNarrowsThePriceFilter(): void
    {
        $result = $this->search('stratocaster', null, [], ['currency' => 'USD', 'min' => null, 'max' => null]);

        self::assertSame($this->idsOf(['T4']), $this->ids($result));
        self::assertSame(1, $result->total());
    }

    // ---------------------------------------------------------------- AC-12

    public function testTheTextNarrowsEveryCountOfEveryFacet(): void
    {
        $result = $this->search('stratocaster', $this->electric);

        self::assertSame(['Fender' => 1, 'Gibson' => 2], $this->buckets($result, 'Brand'));
        self::assertSame(['Used' => 3], $this->buckets($result, 'Condition'), 'Condition is unselected: its count must apply the text too.');
        self::assertSame(3, $result->total());
    }

    public function testASelectedFacetKeepsItsOwnBucketsDisjunctivelyWhileTheTextStillApplies(): void
    {
        $result = $this->search('stratocaster', $this->electric, [$this->brand->id()->toString() => [$this->brandOption('Gibson')]]);

        self::assertSame(2, $result->total());
        self::assertEqualsCanonicalizing($this->idsOf(['T2', 'T4']), $this->ids($result));
        self::assertSame(['Fender' => 1, 'Gibson' => 2], $this->buckets($result, 'Brand'), "Brand's own count omits its own selection but never the text.");
        self::assertSame(['Used' => 2], $this->buckets($result, 'Condition'));
    }

    // ---------------------------------------------------------------- AC-13

    public function testThePrefixIsOnTheLastTermOnly(): void
    {
        $forward = $this->search('fender strat');
        $backward = $this->search('strat fender');

        self::assertSame($this->idsOf(['T1']), $this->ids($forward));
        self::assertSame(TextMatch::Exact, $forward->textMatch());
        self::assertSame(TextMatch::Similar, $backward->textMatch(), '"strat" is not a prefix when it is not last, so nothing matches exactly.');
    }

    public function testAPrefixOfTheLastWordIsFound(): void
    {
        $result = $this->search('jaz');

        self::assertSame($this->idsOf(['T3']), $this->ids($result));
        self::assertSame(TextMatch::Exact, $result->textMatch());
    }

    public function testCaseDoesNotMatter(): void
    {
        self::assertSame($this->ids($this->search('stratocaster')), $this->ids($this->search('STRATOCASTER')));
        self::assertSame(3, $this->search('STRATOCASTER')->total());
    }

    public function testTheReadSideIsFolded(): void
    {
        $accented = $this->search('Stratocäster');

        self::assertSame($this->ids($this->search('stratocaster')), $this->ids($accented));
        self::assertSame(TextMatch::Exact, $accented->textMatch());
    }

    public function testTheWriteSideIsFolded(): void
    {
        $result = $this->search('stratocaster');

        self::assertContains($this->listing['T2'], $this->ids($result), 'T2 is titled "Strätocaster"; the plain spelling must find it.');
    }

    // ---------------------------------------------------------------- AC-14

    public function testATypoWithNoExactMatchIsAnsweredSimilarOnTheTitleOnly(): void
    {
        $result = $this->search('stratocster');

        self::assertSame(TextMatch::Similar, $result->textMatch());
        self::assertEqualsCanonicalizing($this->idsOf(['T1', 'T2']), $this->ids($result));
        self::assertSame(2, $result->total());
        self::assertNotContains($this->listing['T4'], $this->ids($result), 'T4 says "stratocaster" in its description only; trigram is title only.');
    }

    public function testTheCountsOfASimilarPageUseTheSimilarMatch(): void
    {
        $result = $this->search('stratocster', $this->electric);

        self::assertSame(TextMatch::Similar, $result->textMatch());
        self::assertSame(['Fender' => 1, 'Gibson' => 1], $this->buckets($result, 'Brand'));
        self::assertSame(['Used' => 2], $this->buckets($result, 'Condition'));
    }

    public function testASimilarMatchFindsATitleWordTypedWithoutItsLetter(): void
    {
        $result = $this->search('gibsn');

        self::assertSame(TextMatch::Similar, $result->textMatch());
        self::assertContains($this->listing['T4'], $this->ids($result));
        self::assertNotContains($this->listing['Td'], $this->ids($result));
        self::assertNotContains($this->listing['Tw'], $this->ids($result));
    }

    public function testAnExactMatchIsNeverPaddedWithSimilarOnes(): void
    {
        $result = $this->search('fender', $this->electric);

        self::assertSame(TextMatch::Exact, $result->textMatch());
        self::assertSame(2, $result->total(), 'Two exact matches (T1, T5): fewer than three must not trigger the fallback.');
        self::assertEqualsCanonicalizing($this->idsOf(['T1', 'T5']), $this->ids($result));
    }

    public function testNothingSimilarEitherIsAnEmptyPage(): void
    {
        $result = $this->search('zzzzqx');

        self::assertSame(TextMatch::Similar, $result->textMatch());
        self::assertSame(0, $result->total());
        self::assertSame([], $result->hits());
    }

    public function testWithoutSearchTextThereIsNoTextMatch(): void
    {
        self::assertNull($this->search(null)->textMatch());
        self::assertNull($this->search('&&')->textMatch(), 'Text with no surviving term is no text.');
        self::assertSame(self::ALL_PUBLISHED, $this->search(null)->total());
    }

    // ---------------------------------------------------------------- AC-15

    public function testHitsStayNewestFirstWithText(): void
    {
        $withText = $this->ids($this->search('stratocaster'));
        $without = $this->ids($this->search(null));
        $expected = array_values(array_filter($without, static fn (string $id): bool => \in_array($id, $withText, true)));

        self::assertSame($expected, $withText, 'With text the order is the one without it, restricted to the matches.');
        self::assertSame($this->idsOf(['T4', 'T2', 'T1']), $withText, 'T4 was published last; a relevance order would put its description-only match below the title matches.');
    }
}
