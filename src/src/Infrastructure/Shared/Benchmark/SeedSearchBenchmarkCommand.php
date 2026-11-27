<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Benchmark;

use App\Application\Catalog\Command\CreateCategory;
use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Handler\CreateCategoryHandler;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Listing\Command\DraftListing;
use App\Application\Listing\Command\ReviseListing;
use App\Application\Listing\Command\WithdrawListing;
use App\Application\Listing\Handler\DraftListingHandler;
use App\Application\Listing\Handler\ReviseListingHandler;
use App\Application\Listing\Handler\WithdrawListingHandler;
use App\Domain\Catalog\Exception\CategorySlugAlreadyTaken;
use App\Domain\Search\ValueObject\FacetedQuery;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalAttributeSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seeds a disposable database with a marketplace-shaped catalogue and listings for the search
 * latency benchmark (ADR-0026), and prints the B1–B9 scenario URLs as JSON on stdout.
 *
 * A **client of the existing use cases**, nothing more (plan D1, D5): every row goes through an
 * Application handler, so every listing is on a leaf, every value is in force, every published
 * listing is complete, and the text table is filled by its trigger — the data is lawful by
 * construction. The class holds no SQL and names no table (AC-2); option ids are read back through
 * Catalog's admin read model, the way the sell form reads them.
 *
 * Deterministic for a given `--seed` (`mt_srand` + `mt_rand` only, AC-4); ids differ (UUIDv7).
 * Not idempotent: a second run is refused by C-3 on the first root, reported as "not empty".
 *
 * @phpstan-type Attr array{id: string, type: string, required: bool, options: list<string>, labelOf: array<string, string>, cumulative: list<float>|null, min: int, max: int}
 * @phpstan-type Leaf array{id: string, root: int, pos: int, name: string, deep: bool, attributes: list<Attr>}
 * @phpstan-type Tree array{roots: list<string>, leaves: list<Leaf>}
 * @phpstan-type Published array{leaf: int, currency: string, units: int, model: string, values: array<string, string>}
 */
#[AsCommand(
    name: 'muzbar:search:seed-benchmark',
    description: 'Seed a disposable *_bench database for the search latency benchmark; prints scenario URLs as JSON',
)]
final class SeedSearchBenchmarkCommand extends Command
{
    /** `_bench`, or the suite's `_test` + optional ParaTest token (doctrine.yaml `when@test`). */
    private const string DISPOSABLE = '/_(bench|test\d*)$/D';

    private const int CLEAR_EVERY = 200;
    private const int SELLERS = 500;
    private const int RARE_EVERY = 200;
    private const string RARE_WORD = 'Zephyrine';
    private const int BIG_BRAND_OPTIONS = 5000;
    private const int SMALL_BRAND_OPTIONS = 300;

    /**
     * Root → mid → leaves. One leaf, `DEEP_PARENT`, becomes a branch with one depth-4 child (B9).
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array TREE = [
        'Guitars' => [
            'Electric Guitars' => ['Solid Body', 'Semi-Hollow', 'Hollow Body'],
            'Acoustic Guitars' => ['Dreadnought', 'Concert', 'Parlor'],
            'Classical Guitars' => ['Full Size', 'Cutaway Classical', 'Flamenco'],
        ],
        'Basses' => [
            'Electric Basses' => ['Four-String', 'Five-String', 'Fretless'],
            'Acoustic Basses' => ['Acoustic Four-String', 'Acoustic Five-String', 'Bass Ukulele'],
            'Upright Basses' => ['Double Bass', 'Electric Upright', 'Travel Upright'],
        ],
        'Keys & Synths' => [
            'Synthesizers' => ['Analog Synths', 'Digital Synths', 'Modular'],
            'Pianos' => ['Digital Pianos', 'Stage Pianos', 'Acoustic Pianos'],
            'Organs' => ['Tonewheel', 'Combo Organs', 'Church Organs'],
        ],
        'Drums' => [
            'Acoustic Drums' => ['Shell Packs', 'Snares', 'Kick Drums'],
            'Cymbals' => ['Crash', 'Ride', 'Hi-Hats'],
            'Electronic Drums' => ['E-Kits', 'Drum Pads', 'Drum Modules'],
        ],
        'Studio & PA' => [
            'Microphones' => ['Dynamic Mics', 'Condenser Mics', 'Ribbon Mics'],
            'Interfaces' => ['USB Interfaces', 'Thunderbolt Interfaces', 'Rack Interfaces'],
            'Monitors' => ['Nearfield', 'Midfield', 'Headphones'],
        ],
    ];

    private const array DEEP_PARENT = ['Guitars', 'Electric Guitars', 'Solid Body'];
    private const string DEEP_LEAF = 'Signature Series';

    /** Common search terms (B6): each lands in ~10 % of titles. Long words, so a typo stays similar (B7). */
    private const array MODELS = ['Starfinder', 'Moonraker', 'Nightwatch', 'Silverline', 'Goldcrest', 'Thunderbay', 'Riverstone', 'Ironforge', 'Skylancer', 'Wildflower'];

    private const array WORDS = ['vintage', 'tone', 'warm', 'bright', 'player', 'gig', 'studio', 'stage', 'played', 'serviced',
        'setup', 'new', 'strings', 'original', 'case', 'minor', 'wear', 'scratches', 'great', 'sound', 'sustain', 'pickup',
        'neck', 'body', 'finish', 'rare', 'collector', 'condition', 'tested', 'works', 'perfectly', 'shipping', 'pickup only',
        'trade', 'considered', 'smoke', 'free', 'home', 'owner', 'since', 'upgrade', 'stock', 'parts', 'included', 'manual',
        'box', 'cable', 'power', 'supply', 'clean', 'quiet', 'punchy', 'deep', 'rich', 'crisp', 'smooth', 'action', 'low'];

    private const array SYLLABLES = ['ka', 'ro', 'mi', 'ze', 'tu', 'la', 'no', 'vi', 'be', 'so', 'da', 'fe', 'gu', 'ha', 'ji', 'lo', 'me', 'ni', 'po', 'ru'];

    /** @var list<string> */
    private array $sellers = [];

    private int $codeCounter = 0;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly DbalAttributeSchema $schema,
        private readonly CreateCategoryHandler $createCategory,
        private readonly DefineAttributeHandler $defineAttribute,
        private readonly DraftListingHandler $draftListing,
        private readonly ReviseListingHandler $reviseListing,
        private readonly WithdrawListingHandler $withdrawListing,
    ) {
        parent::__construct();
    }

    public static function isDisposable(string $database): bool
    {
        return 1 === preg_match(self::DISPOSABLE, $database);
    }

    protected function configure(): void
    {
        $this
            ->addOption('listings', null, InputOption::VALUE_REQUIRED, 'Published listings (plus N/10 drafts, N/20 withdrawn)', '10000')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Random seed', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $log = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $listings = filter_var($input->getOption('listings'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1_000_000]]);
        $seed = filter_var($input->getOption('seed'), \FILTER_VALIDATE_INT);
        if (false === $listings || false === $seed) {
            $log->writeln('<error>--listings must be an integer 1…1000000 and --seed an integer.</error>');

            return Command::FAILURE;
        }

        $database = (string) $this->connection->getDatabase();
        if (!self::isDisposable($database)) {
            $log->writeln(\sprintf('<error>Refusing to seed "%s": the database name must end in _bench or _test<n>.</error>', $database));

            return Command::FAILURE;
        }

        mt_srand($seed);
        $started = hrtime(true);

        $tree = $this->seedCatalog();
        if (null === $tree) {
            $log->writeln(\sprintf('<error>"%s" is not empty — drop and re-create it, then migrate.</error>', $database));

            return Command::FAILURE;
        }
        $this->entityManager->clear();
        $log->writeln(\sprintf('catalog: %d leaves, %ds', \count($tree['leaves']), self::elapsed($started)));

        $published = $this->seedListings($tree, $listings, $log, $started);
        $log->writeln(\sprintf('listings: %d published, %d drafts, %d withdrawn, %ds', $listings, intdiv($listings, 10), intdiv($listings, 20), self::elapsed($started)));

        $output->writeln(json_encode($this->scenarios($tree, $published), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------- catalog (plan D5.2)

    /**
     * Null when the **first** root is refused by C-3: the database is not empty. Any later
     * refusal is a seeder bug and propagates — after partial writes it must not read "not empty".
     *
     * @return Tree|null
     */
    private function seedCatalog(): ?array
    {
        $roots = [];
        $leaves = [];
        $rootIndex = 0;

        foreach (self::TREE as $rootName => $mids) {
            try {
                $rootId = $this->category($rootName, null);
            } catch (CategorySlugAlreadyTaken $refused) {
                if (0 === $rootIndex) {
                    return null;
                }

                throw $refused;
            }
            $roots[] = $rootId;
            $brandOptions = $rootIndex < 2 ? self::BIG_BRAND_OPTIONS : self::SMALL_BRAND_OPTIONS;
            $rootAttributes = [
                $this->choice($rootId, 'Brand', true, self::brandLabels($brandOptions), zipf: true),
                $this->choice($rootId, 'Condition', true, ['Mint', 'Excellent', 'Very Good', 'Good', 'Fair']),
                $this->number($rootId, 'Year', true, null, 1950, 2026),
            ];

            $position = 0;
            foreach ($mids as $midName => $leafNames) {
                $midId = $this->category($midName, $rootId);
                $midAttributes = [
                    $this->boolean($midId, 'Includes Case', true),
                    $this->choice($midId, 'Finish', false, ['Sunburst', 'Black', 'White', 'Natural', 'Red', 'Blue', 'Green', 'Silver', 'Gold', 'Relic']),
                ];

                foreach ($leafNames as $leafName) {
                    $leafId = $this->category($leafName, $midId);
                    $lineage = [
                        ...$rootAttributes,
                        ...$midAttributes,
                        $this->number($leafId, 'Weight', false, 'kg', 1, 40),
                        $this->choice($leafId, 'Colour', false, ['Black', 'White', 'Grey', 'Brown', 'Red', 'Blue', 'Yellow', 'Pink']),
                    ];

                    if (self::DEEP_PARENT === [$rootName, $midName, $leafName]) {
                        $deepId = $this->category(self::DEEP_LEAF, $leafId);
                        $leaves[] = ['id' => $deepId, 'root' => $rootIndex, 'pos' => $position++, 'name' => self::DEEP_LEAF, 'deep' => true, 'attributes' => [...$lineage, ...$this->deepAttributes($deepId)]];
                        continue;
                    }

                    $leaves[] = ['id' => $leafId, 'root' => $rootIndex, 'pos' => $position++, 'name' => $leafName, 'deep' => false, 'attributes' => $lineage];
                }
            }
            ++$rootIndex;
        }

        // Zipf rank order (plan D5.3): the deep leaf first (so B9 always has listings), then
        // round-robin across roots, so every root's subtree is large enough to reach page 50 (B8).
        usort($leaves, static fn (array $a, array $b): int => [!$a['deep'], $a['pos'], $a['root']] <=> [!$b['deep'], $b['pos'], $b['root']]);

        return ['roots' => $roots, 'leaves' => $leaves];
    }

    /**
     * Thirteen more attributes on the depth-4 leaf: with the lineage's seven that is exactly
     * `FilterRequest::MAX_ATTRIBUTES` filterable ones (B9).
     *
     * @return list<Attr>
     */
    private function deepAttributes(string $categoryId): array
    {
        return [
            $this->choice($categoryId, 'Pickups', false, ['SSS', 'HSS', 'HH', 'P90', 'HSH', 'Single H']),
            $this->choice($categoryId, 'Bridge', false, ['Tremolo', 'Hardtail', 'Floyd', 'Bigsby', 'Wraparound']),
            $this->choice($categoryId, 'Neck Profile', false, ['C', 'D', 'U', 'V']),
            $this->choice($categoryId, 'Fretboard', false, ['Rosewood', 'Maple', 'Ebony', 'Pau Ferro', 'Richlite']),
            $this->choice($categoryId, 'Body Wood', false, ['Alder', 'Ash', 'Mahogany', 'Basswood', 'Korina', 'Walnut']),
            $this->choice($categoryId, 'Made In', false, ['USA', 'Mexico', 'Japan', 'Korea', 'Indonesia', 'China', 'Germany', 'Czechia']),
            $this->boolean($categoryId, 'Locking Tuners', false),
            $this->boolean($categoryId, 'Coil Split', false),
            $this->boolean($categoryId, 'Signed', false),
            $this->boolean($categoryId, 'Original Case', false),
            $this->number($categoryId, 'Frets', false, null, 19, 24),
            $this->number($categoryId, 'Scale Length', false, 'mm', 600, 700),
            $this->number($categoryId, 'Nut Width', false, 'mm', 38, 48),
        ];
    }

    private function category(string $name, ?string $parentId): string
    {
        return ($this->createCategory)(new CreateCategory($name, self::slug($name), $parentId))->toString();
    }

    /**
     * @param list<string> $labels
     *
     * @return Attr
     */
    private function choice(string $categoryId, string $label, bool $required, array $labels, bool $zipf = false): array
    {
        $id = ($this->defineAttribute)(new DefineAttribute($categoryId, 'choice', $label, $this->code($label), $required, optionLabels: $labels))->toString();
        // Read back in insertion order (C-19): the same order as `$labels`, so a Zipf rank is a label's position.
        $options = array_column($this->schema->optionsOf($id), 'id');

        return self::attr($id, 'choice', $required, $options, array_combine($options, $labels), $zipf ? self::zipf(\count($options), 1.1) : null);
    }

    /** @return Attr */
    private function boolean(string $categoryId, string $label, bool $required): array
    {
        $id = ($this->defineAttribute)(new DefineAttribute($categoryId, 'boolean', $label, $this->code($label), $required))->toString();

        return self::attr($id, 'boolean', $required);
    }

    /** @return Attr */
    private function number(string $categoryId, string $label, bool $required, ?string $unit, int $min, int $max): array
    {
        $id = ($this->defineAttribute)(new DefineAttribute($categoryId, 'number', $label, $this->code($label), $required, $unit, $min, $max))->toString();

        return self::attr($id, 'number', $required, min: $min, max: $max);
    }

    /**
     * One shape for every kind, so nothing reads a key that is not there.
     *
     * @param list<string>          $options
     * @param array<string, string> $labelOf    option id → label
     * @param list<float>|null      $cumulative
     *
     * @return Attr
     */
    private static function attr(string $id, string $type, bool $required, array $options = [], array $labelOf = [], ?array $cumulative = null, int $min = 0, int $max = 0): array
    {
        return ['id' => $id, 'type' => $type, 'required' => $required, 'options' => $options, 'labelOf' => $labelOf, 'cumulative' => $cumulative, 'min' => $min, 'max' => $max];
    }

    /** Unique across the whole tree, so C-21 (one code per branch) can never refuse a seed. */
    private function code(string $label): string
    {
        return self::slug($label).'-'.++$this->codeCounter;
    }

    // ---------------------------------------------------------------- listings (plan D5.3)

    /**
     * @param Tree $tree
     *
     * @return list<Published>
     */
    private function seedListings(array $tree, int $count, OutputInterface $log, int $started): array
    {
        for ($i = 0; $i < self::SELLERS; ++$i) {
            $this->sellers[] = \sprintf('%08x-%04x-4%03x-a%03x-%012x', mt_rand(0, 0x7FFFFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFF), mt_rand(0, 0xFFF), mt_rand(0, 0xFFFFFFFF));
        }

        $leafCumulative = self::zipf(\count($tree['leaves']), 1.0);
        $total = $count + intdiv($count, 10) + intdiv($count, 20);
        $published = [];

        for ($i = 0; $i < $total; ++$i) {
            $leafIndex = self::draw($leafCumulative);
            $leaf = $tree['leaves'][$leafIndex];
            $seller = $this->sellers[mt_rand(0, self::SELLERS - 1)];
            $model = self::MODELS[mt_rand(0, \count(self::MODELS) - 1)];
            $currency = self::currency();
            $units = (int) round(50 * 200 ** (mt_rand() / mt_getrandmax()));
            $price = \sprintf('%d.%02d', $units, mt_rand(0, 99));
            $values = $this->values($leaf);
            $brand = $leaf['attributes'][0];
            $title = $brand['labelOf'][$values[$brand['id']]].' '.$model.' '.$leaf['name'].(0 === $i % self::RARE_EVERY ? ' '.self::RARE_WORD : '');
            $description = self::description();

            $id = ($this->draftListing)(new DraftListing($seller, $leaf['id'], $title, $description, $price, $currency, [], false))->toString();
            $publish = $i < $count || $i >= $count + intdiv($count, 10);
            ($this->reviseListing)(new ReviseListing($id, $seller, $leaf['id'], $title, $description, $price, $currency, $values, $publish));

            if ($i >= $count + intdiv($count, 10)) {
                ($this->withdrawListing)(new WithdrawListing($id, $seller));
            } elseif ($i < $count) {
                $published[] = ['leaf' => $leafIndex, 'currency' => $currency, 'units' => $units, 'model' => strtolower($model), 'values' => $values];
            }

            if (0 === ($i + 1) % self::CLEAR_EVERY) {
                $this->entityManager->clear();
            }
            if (0 === ($i + 1) % 1000) {
                $log->writeln(\sprintf('listings: %d/%d, %ds, %d MB', $i + 1, $total, self::elapsed($started), intdiv(memory_get_usage(true), 1 << 20)));
            }
        }
        $this->entityManager->clear();

        return $published;
    }

    /**
     * Every required attribute, ~70 % of the rest — all of them on the deep leaf (B9 selects 20).
     *
     * @param Leaf $leaf
     *
     * @return array<string, string> attribute id → raw text, as the sell form posts it
     */
    private function values(array $leaf): array
    {
        $values = [];
        foreach ($leaf['attributes'] as $attribute) {
            if (!$attribute['required'] && !$leaf['deep'] && mt_rand(1, 10) > 7) {
                continue;
            }
            $values[$attribute['id']] = match ($attribute['type']) {
                'choice' => $attribute['options'][null === $attribute['cumulative'] ? mt_rand(0, \count($attribute['options']) - 1) : self::draw($attribute['cumulative'])],
                'boolean' => 1 === mt_rand(0, 1) ? 'yes' : 'no',
                default => (string) mt_rand($attribute['min'], $attribute['max']),
            };
        }

        return $values;
    }

    // ---------------------------------------------------------------- scenarios (plan D5.4)

    /**
     * Built from what was just written — ids held in memory, never re-read. Every URL is chosen so
     * that at least one published listing matches it (AC-5): selections are taken from a real
     * listing's own values.
     *
     * @param Tree            $tree
     * @param list<Published> $published
     *
     * @return array<string, list<string>>
     */
    private function scenarios(array $tree, array $published): array
    {
        $byLeaf = [];
        $rootCount = array_fill(0, \count($tree['roots']), 0);
        $models = [];
        foreach ($published as $listing) {
            $byLeaf[$listing['leaf']][] = $listing;
            ++$rootCount[$tree['leaves'][$listing['leaf']]['root']];
            $models[$listing['model']] = true;
        }
        // Biggest first; equal counts keep leaf order (stable sort), so the list is deterministic.
        uksort($byLeaf, static fn (int $a, int $b): int => [\count($byLeaf[$b]), $a] <=> [\count($byLeaf[$a]), $b]);
        $topLeaves = \array_slice($byLeaf, 0, 10, true);

        $s = ['B1' => ['/browse'], 'B2' => [], 'B3' => [], 'B4' => [], 'B5' => [], 'B6' => [], 'B7' => [], 'B8' => [], 'B9' => []];

        foreach ($tree['roots'] as $root => $rootId) {
            if (0 === $rootCount[$root]) {
                continue;
            }
            $s['B2'][] = self::url(['category' => $rootId]);
            $lastPage = min(FacetedQuery::MAX_PAGE, intdiv($rootCount[$root] + FacetedQuery::PER_PAGE - 1, FacetedQuery::PER_PAGE));
            $s['B8'][] = self::url(['category' => $rootId, 'page' => (string) $lastPage]);

            foreach ($published as $listing) {
                if ('EUR' === $listing['currency'] && $tree['leaves'][$listing['leaf']]['root'] === $root) {
                    $u = $listing['units'];
                    $s['B5'][] = self::url(['category' => $rootId, 'price[currency]' => 'EUR', 'price[min]' => (string) intdiv($u, 2), 'price[max]' => (string) ($u * 2)]);
                    $s['B5'][] = self::url(['category' => $rootId, 'price[currency]' => 'EUR', 'price[min]' => (string) $u, 'price[max]' => (string) ($u + 500)]);
                    break;
                }
            }
        }

        foreach ($topLeaves as $leafIndex => $listings) {
            $leaf = $tree['leaves'][$leafIndex];
            [$brand, $condition, $year, $case] = array_column(\array_slice($leaf['attributes'], 0, 4), 'id');

            $brandCounts = array_count_values(array_map(static fn (array $l): string => $l['values'][$brand], $listings));
            arsort($brandCounts);
            $s['B3'][] = self::url(['category' => $leaf['id'], "f[$brand][]" => (string) array_key_first($brandCounts)]);

            $first = $listings[0]['values'];
            $s['B4'][] = self::url(['category' => $leaf['id'], "f[$condition][]" => $first[$condition], "f[$case][]" => $first[$case], "r[$year][min]" => (string) ((int) $first[$year] - 5), "r[$year][max]" => (string) ((int) $first[$year] + 5)]);
        }

        foreach (self::MODELS as $model) {
            $model = strtolower($model);
            if (isset($models[$model])) {
                $s['B6'][] = self::url(['q' => $model]);
                // The last two letters swapped: no word in any bank starts with it, so the exact
                // match finds nothing and the trigram fallback (S-18) answers.
                $s['B7'][] = self::url(['q' => substr($model, 0, -2).$model[-1].$model[-2]]);
            }
        }
        $s['B6'][] = self::url(['q' => strtolower(self::RARE_WORD)]);

        foreach ($published as $listing) {
            $leaf = $tree['leaves'][$listing['leaf']];
            if ($leaf['deep']) {
                $pairs = ['category' => $leaf['id']];
                foreach ($leaf['attributes'] as $attribute) {
                    $value = $listing['values'][$attribute['id']];
                    if ('number' === $attribute['type']) {
                        $pairs["r[{$attribute['id']}][min]"] = $value;
                        $pairs["r[{$attribute['id']}][max]"] = $value;
                    } else {
                        $pairs["f[{$attribute['id']}][]"] = $value;
                    }
                }
                $pairs += ['q' => $listing['model'], 'price[currency]' => $listing['currency'], 'price[min]' => (string) $listing['units'], 'price[max]' => (string) ($listing['units'] + 1)];
                $s['B9'][] = self::url($pairs);
                break;
            }
        }

        return $s;
    }

    /** @param array<string, string> $pairs */
    private static function url(array $pairs): string
    {
        $query = [];
        foreach ($pairs as $key => $value) {
            $query[] = rawurlencode($key).'='.rawurlencode($value);
        }

        return '/browse?'.implode('&', $query);
    }

    // ---------------------------------------------------------------- randomness, words

    /**
     * Cumulative Zipf weights for ranks 1…n: weight(k) = 1 / k^s.
     *
     * @return list<float>
     */
    private static function zipf(int $n, float $s): array
    {
        $cumulative = [];
        $sum = 0.0;
        for ($k = 1; $k <= $n; ++$k) {
            $sum += 1 / $k ** $s;
            $cumulative[] = $sum;
        }

        return $cumulative;
    }

    /** @param list<float> $cumulative */
    private static function draw(array $cumulative): int
    {
        $target = mt_rand() / mt_getrandmax() * $cumulative[\count($cumulative) - 1];
        $low = 0;
        $high = \count($cumulative) - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($cumulative[$mid] < $target) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    private static function currency(): string
    {
        $r = mt_rand(1, 100);

        return match (true) {
            $r <= 60 => 'EUR',
            $r <= 85 => 'USD',
            default => 'SEK',
        };
    }

    private static function description(): string
    {
        $words = [];
        for ($n = mt_rand(40, 200); $n > 0; --$n) {
            $words[] = self::WORDS[mt_rand(0, \count(self::WORDS) - 1)];
        }

        return ucfirst(implode(' ', $words)).'.';
    }

    /**
     * `$count` distinct labels, three fixed-length syllables each (8 000 possible): "Karomi".
     *
     * @return list<string>
     */
    private static function brandLabels(int $count): array
    {
        $base = \count(self::SYLLABLES);
        $labels = [];
        for ($i = 0; $i < $count; ++$i) {
            $labels[] = ucfirst(self::SYLLABLES[intdiv($i, $base * $base) % $base].self::SYLLABLES[intdiv($i, $base) % $base].self::SYLLABLES[$i % $base]);
        }

        return $labels;
    }

    private static function slug(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }

    private static function elapsed(int $started): int
    {
        return intdiv(hrtime(true) - $started, 1_000_000_000);
    }
}
