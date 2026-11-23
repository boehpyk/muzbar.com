<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Identity\Entity\User;
use App\Domain\Listing\ValueObject\Currency;
use App\Tests\Factory\ListingFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;

/**
 * search-range-facets' fixture (feature-spec §B), on the values tree. Every listing holds the
 * required Condition (and Brand on Electric).
 *
 * ```
 * N6a  published, Electric, Strings 6,  Fender, EUR 300.00     Dn  draft,     Electric, Strings 7
 * N6b  published, Electric, Strings 6,  Gibson, EUR 1200.00    Wn  withdrawn, Electric, Strings 8
 * N12  published, Electric, Strings 12, Fender, USD 300.00     Bb  published, Bass, Bass strings 5 000 000 000, SEK 300.00
 * N0   published, Electric, no Strings, Gibson, EUR 300.00
 * ```
 */
trait BuildsTheRangeFixture
{
    use BuildsTheValuesTree;

    private string $listingN6a;
    private string $listingN6b;
    private string $listingN12;
    private string $listingN0;
    private string $listingBb;

    private function buildTheRangeFixture(): void
    {
        $this->buildTheValuesTree();
        $seller = UserFactory::createOne();
        $used = $this->optionIdOf($this->condition, 'Used');
        $condition = $this->condition->id()->toString();
        $strings = $this->strings->id()->toString();
        $fender = $this->optionIdOf($this->brand, 'Fender');
        $gibson = $this->optionIdOf($this->brand, 'Gibson');
        $this->listingN6a = $this->rangeListing($seller, $this->electric, $this->electricValues($fender) + [$strings => '6'])->pricedAt('300.00')->published()->create()->id()->toString();
        $this->listingN6b = $this->rangeListing($seller, $this->electric, $this->electricValues($gibson) + [$strings => '6'])->pricedAt('1200.00')->published()->create()->id()->toString();
        $this->listingN12 = $this->rangeListing($seller, $this->electric, $this->electricValues($fender) + [$strings => '12'])->pricedAt('300.00', Currency::USD)->published()->create()->id()->toString();
        $this->listingN0 = $this->rangeListing($seller, $this->electric, $this->electricValues($gibson))->pricedAt('300.00')->published()->create()->id()->toString();
        $this->rangeListing($seller, $this->electric, $this->electricValues($fender) + [$strings => '7'])->create(); // Dn
        $this->rangeListing($seller, $this->electric, $this->electricValues($fender) + [$strings => '8'])->withdrawn()->create(); // Wn
        $this->listingBb = $this->rangeListing($seller, $this->bass, [$condition => $used, $this->bassStrings->id()->toString() => '5000000000'])->pricedAt('300.00', Currency::SEK)->published()->create()->id()->toString();
    }

    /**
     * @param array<string, string> $values
     */
    private function rangeListing(User $seller, Category $category, array $values): ListingFactory
    {
        return ListingFactory::new()->soldBy($seller)->inCategory($category)->withValues($values);
    }

    /**
     * @return array<string, string>
     */
    private function electricValues(string $brandOption): array
    {
        return [$this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'), $this->brand->id()->toString() => $brandOption];
    }

    private function rangeDb(): Connection
    {
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);

        return $db;
    }
}
