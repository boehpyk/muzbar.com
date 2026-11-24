<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Identity\Entity\User;
use App\Domain\Listing\ValueObject\Currency;
use App\Domain\Listing\ValueObject\ListingDescription;
use App\Tests\Factory\ListingFactory;
use App\Tests\Factory\UserFactory;

/**
 * search-full-text's fixture (feature-spec §C), on the values tree (Condition and Brand are the
 * required attributes; Electric is under Guitars, Bass is a sibling leaf).
 *
 * ```
 * Published, Electric
 *   T1  "Fender Stratocaster 1962 reissue"                 Fender  EUR   10:00
 *   T2  "Strätocaster Ŝpecial — Gitarr"                    Gibson  EUR   10:01
 *   T5  "AC-DC Fender Guitars & Guitar's case, Straße"     Gibson  EUR   10:02
 *   Tc  "Гитара Фендер"                                    Gibson  EUR   10:03
 *   T4  "Gibson Les Paul", description "Not a stratocaster" Gibson USD   10:05  (published LAST)
 * Published, Bass
 *   T3  "Fender Jazz Bass V", description "five strings, left-handed"  SEK  10:04
 * Not public (Electric)
 *   Td  draft      "Fender Stratocaster prototype"
 *   Tw  withdrawn  "Stratocaster withdrawn"
 * ```
 *
 * T4 is published last on purpose: it matches `stratocaster` only through its description, so a
 * relevance order would put it below T1 and T2 while newest-first puts it on top (AC-15). Every
 * listing holds its required values. Includes `BuildsTheValuesTree`.
 */
trait BuildsTheTextFixture
{
    use BuildsTheValuesTree;

    /** @var array<string, string> label (T1…) => listing id */
    private array $listing = [];

    /** @var array<string, string> label => title, published ones only */
    private array $publishedTitles = [];

    private function buildTheTextFixture(): void
    {
        $this->buildTheValuesTree();
        $seller = UserFactory::createOne();

        $this->publishOnElectric($seller, 'T1', 'Fender Stratocaster 1962 reissue', '', 'Fender', Currency::EUR, '10:00');
        $this->publishOnElectric($seller, 'T2', 'Strätocaster Ŝpecial — Gitarr', '', 'Gibson', Currency::EUR, '10:01');
        $this->publishOnElectric($seller, 'T5', "AC-DC Fender Guitars & Guitar's case, Straße", '', 'Gibson', Currency::EUR, '10:02');
        $this->publishOnElectric($seller, 'Tc', 'Гитара Фендер', '', 'Gibson', Currency::EUR, '10:03');

        $bassValues = [$this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used')];
        $this->listing['T3'] = $this->textListing($seller, $this->bass, $bassValues, 'Fender Jazz Bass V', 'five strings, left-handed', Currency::SEK)
            ->published($this->at('10:04'))->create()->id()->toString();
        $this->publishedTitles['T3'] = 'Fender Jazz Bass V';

        $this->publishOnElectric($seller, 'T4', 'Gibson Les Paul', 'Not a stratocaster', 'Gibson', Currency::USD, '10:05');

        $this->listing['Td'] = $this->textListing($seller, $this->electric, $this->electricTextValues('Fender'), 'Fender Stratocaster prototype', '', Currency::EUR)->create()->id()->toString();
        $this->listing['Tw'] = $this->textListing($seller, $this->electric, $this->electricTextValues('Fender'), 'Stratocaster withdrawn', '', Currency::EUR)->withdrawn($this->at('10:06'))->create()->id()->toString();
    }

    private function publishOnElectric(User $seller, string $label, string $title, string $description, string $brand, Currency $currency, string $time): void
    {
        $this->listing[$label] = $this->textListing($seller, $this->electric, $this->electricTextValues($brand), $title, $description, $currency)
            ->published($this->at($time))->create()->id()->toString();
        $this->publishedTitles[$label] = $title;
    }

    /**
     * @param array<string, string> $values
     */
    private function textListing(User $seller, Category $category, array $values, string $title, string $description, Currency $currency): ListingFactory
    {
        return ListingFactory::new()->soldBy($seller)->inCategory($category)->withValues($values)->titled($title)
            ->with(['description' => ListingDescription::fromString($description)])
            ->pricedAt('100.00', $currency);
    }

    /**
     * @return array<string, string> Condition = Used and the given Brand
     */
    private function electricTextValues(string $brand): array
    {
        return [
            $this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'),
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, $brand),
        ];
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-20 '.$time.':00', new \DateTimeZone('UTC'));
    }
}
