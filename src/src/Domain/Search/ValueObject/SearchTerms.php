<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * What survives of the text a visitor typed (S-15) — the PHP half of the sanitizer, and the
 * security-critical class of the full-text slice. Format only, and never throws: hostile text is a
 * 200 with no text filter, never an error.
 *
 * - invalid UTF-8 is no text at all;
 * - only the first `MAX_LENGTH` characters are read (a term crossing the cut is cut there);
 * - a term is a maximal run of letters, marks and digits (`[\p{L}\p{M}\p{N}]`) holding at least one
 *   letter or digit — a run of combining marks alone is no term. **Every other character is a
 *   separator**: all tsquery syntax (`& | ! ( ) : * < > -`), quotes, backslash, whitespace, control
 *   characters (NUL included), punctuation and symbols;
 * - the first `MAX_TERMS` terms are kept; none at all is `null` — no text filter.
 *
 * So `toString()`, the one string bound into SQL, holds only letters, marks, digits and single
 * spaces: no operator, quote, backslash, NUL (pdo_pgsql truncates at it) or invalid byte (SQLSTATE
 * 22021) can reach the database, whatever was typed.
 *
 * **Why PHP does not fold, lower-case or append `:*`.** The mangling — `search_fold()` (unaccent),
 * the `simple` configuration's lower-casing, `:*` on the last lexeme — has exactly one definition, in
 * SQL (`search_tsquery()`, ADR-0025 d.3), shared by the trigger that writes the document and the
 * read that queries it. Doing any of it here would be a second definition that can drift from the
 * first; so case and accents are kept (`Strätocaster` stays `Strätocaster`).
 *
 * **Why the parser, not this class, builds the query.** Stripping operators here is not enough on
 * its own: `unaccent` maps some *letters* to tsquery syntax (`ʼ`→`'`, `ː`→`:`, `ŉ`→`'n`), so a term
 * this class approves becomes an operator only after the fold. Measured: `to_tsquery('simple',
 * unaccent('ʼfoo'))` is `syntax error in tsquery`, and `donʼt:*` silently becomes a phrase query.
 * Hence `search_tsquery()` uses `plainto_tsquery`, whose text parser never reads its input as
 * operators, and never `to_tsquery` on anything derived from this string.
 *
 * S-18 is here too: `fallsBackAt()` decides when the exact match found too little and the similar
 * match answers the request instead.
 */
final readonly class SearchTerms
{
    public const int MAX_LENGTH = 100;
    public const int MAX_TERMS = 8;

    /** An exact total below this falls back to the similar match (S-18): only on nothing. */
    public const int FALLBACK_BELOW = 1;

    /**
     * @param non-empty-list<string> $terms
     */
    private function __construct(
        private array $terms,
    ) {
    }

    public static function parse(string $raw): ?self
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        $runs = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_substr($raw, 0, self::MAX_LENGTH, 'UTF-8'), -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $runs) {
            return null;
        }

        $terms = \array_slice(
            array_values(array_filter($runs, static fn (string $run): bool => 1 === preg_match('/[\p{L}\p{N}]/u', $run))),
            0,
            self::MAX_TERMS,
        );

        return [] === $terms ? null : new self($terms);
    }

    /** @return non-empty-list<string> in the order typed, as typed */
    public function terms(): array
    {
        return $this->terms;
    }

    /** The terms, single-spaced — the one string bound into SQL. */
    public function toString(): string
    {
        return implode(' ', $this->terms);
    }

    /** S-18: the exact match answers the request unless its total, every selection applied, is below `FALLBACK_BELOW`. */
    public function fallsBackAt(int $exactTotal): bool
    {
        return $exactTotal < self::FALLBACK_BELOW;
    }
}
