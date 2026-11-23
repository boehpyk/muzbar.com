<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Search;

use App\Domain\Search\ValueObject\SearchTerms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * search-full-text AC-1 (S-15, the sanitizer), AC-2 (S-16's PHP half: only letters, marks, digits
 * and single spaces reach SQL) and AC-3 (S-18's policy: fall back only on nothing).
 */
final class SearchTermsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function termsOf(string $raw): array
    {
        $terms = SearchTerms::parse($raw);

        if (null === $terms) {
            self::fail(\sprintf('"%s" kept no terms', $raw));
        }

        return $terms->terms();
    }

    public function testTermsAreRunsKeptAsTypedAndSingleSpaced(): void
    {
        $terms = SearchTerms::parse("  Fender  Strat\t");

        self::assertSame(['Fender', 'Strat'], $terms?->terms());
        self::assertSame('Fender Strat', $terms->toString());
    }

    public function testPhpNeitherLowerCasesNorStripsAccents(): void
    {
        self::assertSame(['Strätocaster', 'ÅÄÖ'], self::termsOf('Strätocaster ÅÄÖ'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function separators(): iterable
    {
        foreach (['&', '|', '!', '(', ')', ':', '*', '<', '>', '-', "'", '"', '\\', ',', '.', '/', '@', '#', '%', '+', '=', '~', '^', '`', '{', '}', '[', ']', ';', '?', "\t", "\r", "\n", "\0", "\u{00A0}", "\u{2028}"] as $separator) {
            yield \sprintf('U+%04X', mb_ord($separator, 'UTF-8')) => [$separator];
        }
    }

    #[DataProvider('separators')]
    public function testEveryNonWordCharacterIsASeparator(string $separator): void
    {
        self::assertSame(['a', 'b'], self::termsOf('a'.$separator.'b'));
    }

    public function testTsquerySyntaxIsNeverKept(): void
    {
        self::assertSame(['a', 'b', 'c', 'd', 'e', 'f'], self::termsOf("a&b|!c(d):*<->'e'\\f"));
        self::assertSame(['fender'], self::termsOf('fender:*'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function scripts(): iterable
    {
        yield 'Cyrillic' => ['гитара', ['гитара']];
        yield 'Katakana' => ['ギター', ['ギター']];
        yield 'a letter with a combining mark' => ["e\u{0301}", ["e\u{0301}"]];
        yield 'a year' => ['1962', ['1962']];
        yield 'a single digit' => ['Les Paul 5', ['Les', 'Paul', '5']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('scripts')]
    public function testLettersOfAnyScriptMarksAndDigitsAreKept(string $raw, array $expected): void
    {
        self::assertSame($expected, self::termsOf($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nothing(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'operators' => ['&|!'];
        yield 'quotes' => ["'''"];
        yield 'a prefix operator' => [':*'];
        yield 'a combining mark alone' => ["\u{0301}"];
        yield 'marks between separators' => ["\u{0301}\u{0302} - \u{0301}"];
        yield 'invalid UTF-8' => ["\xC3\x28"];
        yield 'invalid UTF-8 beside a word' => ["fender \xC3\x28"];
        yield 'NUL' => ["\0"];
    }

    #[DataProvider('nothing')]
    public function testNoTermIsNoTextFilter(string $raw): void
    {
        self::assertNull(SearchTerms::parse($raw));
    }

    public function testOnlyTheFirstHundredCharactersAreRead(): void
    {
        // 95 characters, then a term that crosses character 100: it is cut there.
        $head = str_repeat('é', 94).' ';
        self::assertSame(95, mb_strlen($head));

        self::assertSame([str_repeat('é', 94), 'abcde'], self::termsOf($head.'abcdefgh'.str_repeat('z', 10_000)));
        self::assertSame(100, SearchTerms::MAX_LENGTH);
    }

    public function testATenThousandCharacterWordIsCutToTheCap(): void
    {
        self::assertSame([str_repeat('ä', SearchTerms::MAX_LENGTH)], self::termsOf(str_repeat('ä', 10_000)));
    }

    public function testOnlyTheFirstEightTermsAreKept(): void
    {
        self::assertSame(8, SearchTerms::MAX_TERMS);
        self::assertSame(
            ['t1', 't2', 't3', 't4', 't5', 't6', 't7', 't8'],
            self::termsOf('t1 t2 t3 t4 t5 t6 t7 t8 t9 t10 t11 t12'),
        );
    }

    public function testTheTermCapCountsTermsNotDroppedRuns(): void
    {
        self::assertSame(
            ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'],
            self::termsOf("\u{0301} \u{0301} a b c d e f g h i"),
        );
    }

    public function testNothingThrowsAndOnlyWordCharactersSurviveAnyInput(): void
    {
        mt_srand(20261123);
        $survivors = 0;

        for ($i = 0; $i < 1_000; ++$i) {
            $raw = 0 === $i % 2 ? self::randomBytes() : self::randomCodePoints();
            $terms = SearchTerms::parse($raw);

            if (null === $terms) {
                continue;
            }

            ++$survivors;
            self::assertLessThanOrEqual(SearchTerms::MAX_TERMS, \count($terms->terms()));

            foreach ($terms->terms() as $term) {
                self::assertMatchesRegularExpression('/^[\p{L}\p{M}\p{N}]+$/uD', $term);
                self::assertMatchesRegularExpression('/[\p{L}\p{N}]/u', $term);
            }

            self::assertMatchesRegularExpression('/^[\p{L}\p{M}\p{N}]+( [\p{L}\p{M}\p{N}]+)*$/uD', $terms->toString());
        }

        // The loop must have exercised the surviving path, or its assertions proved nothing.
        self::assertGreaterThan(100, $survivors);
    }

    private static function randomBytes(): string
    {
        $bytes = '';

        for ($n = mt_rand(0, 300); $n > 0; --$n) {
            $bytes .= \chr(mt_rand(0, 255));
        }

        return $bytes;
    }

    /** Valid UTF-8 mixing ASCII (operators included), Latin-1, combining marks and the rest of the BMP. */
    private static function randomCodePoints(): string
    {
        $text = '';

        for ($n = mt_rand(0, 150); $n > 0; --$n) {
            $codePoint = match (mt_rand(0, 3)) {
                0 => mt_rand(0, 0x7F),
                1 => mt_rand(0x80, 0xFF),
                2 => mt_rand(0x300, 0x36F),
                default => mt_rand(0x100, 0xD7FF),
            };
            $text .= mb_chr($codePoint, 'UTF-8');
        }

        return $text;
    }

    public function testFallBackOnlyOnNothing(): void
    {
        $terms = SearchTerms::parse('fender');

        self::assertSame(1, SearchTerms::FALLBACK_BELOW);
        self::assertTrue($terms?->fallsBackAt(0));
        self::assertFalse($terms->fallsBackAt(1));
        self::assertFalse($terms->fallsBackAt(500));
    }
}
