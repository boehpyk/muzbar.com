<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Listing;

use PHPUnit\Framework\TestCase;

/**
 * listing-wizard AC-22 and the source half of AC-9 (ADR-0027): the Live Component renders and never
 * writes, and only the category select asks for a morph. A rendered-DOM test cannot prove that no
 * `#[LiveAction]` exists, so these read the source, with comments stripped (a docblock that names
 * `LiveAction` to forbid it is documentation, not a door). Every scan asserts it read more than zero
 * files, so a wrong path cannot pass vacuously.
 */
final class ListingFormIsolationTest extends TestCase
{
    private const string COMPONENT_DIR = 'src/Infrastructure/Listing/Http/Component';
    private const string TEMPLATE = 'templates/listing/components/ListingForm.html.twig';

    private static function root(): string
    {
        return \dirname(__DIR__, 4);
    }

    /** @return list<\SplFileInfo> every `.php` file in the component directory */
    private function componentFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/'.self::COMPONENT_DIR, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function codeOf(\SplFileInfo $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (\is_array($token)) {
                $code .= \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true) ? '' : $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    public function testTheComponentDeclaresNoLiveActionSoTheLiveEndpointCannotWrite(): void
    {
        $files = $this->componentFiles();
        $offences = [];

        foreach ($files as $file) {
            if (1 === preg_match('/\bLiveAction\b/', $this->codeOf($file))) {
                $offences[] = $file->getFilename();
            }
        }

        self::assertGreaterThan(0, \count($files), 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $offences, 'A #[LiveAction] is a second door to the aggregate (ADR-0027).');
    }

    public function testTheComponentReferencesNoHandlerRepositoryOrCategoriesPort(): void
    {
        $files = $this->componentFiles();
        $offences = [];

        foreach ($files as $file) {
            preg_match_all('/App\\\\Application\\\\\w+\\\\|\bApp\\\\Domain\\\\\w+\\\\Port\\\\|\w*Handler\b|\w*Repository\b|\bCategories\b/', $this->codeOf($file), $m);
            foreach ($m[0] as $hit) {
                $offences[] = $file->getFilename().': '.$hit;
            }
        }

        self::assertGreaterThan(0, \count($files), 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $offences, 'The component injects read models and the form builder only.');
    }

    public function testEveryFieldSyncsWithoutRenderingBecauseTheFormWideBindingIsNorender(): void
    {
        $files = $this->componentFiles();
        $code = implode("\n", array_map($this->codeOf(...), $files));

        self::assertGreaterThan(0, \count($files), 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertMatchesRegularExpression('/function getDataModelValue\(\)\s*:\s*string\s*\{\s*return \'norender\|\*\';\s*\}/', $code);
    }

    public function testOnlyTheCategoryRowCarriesTheRenderAction(): void
    {
        $template = (string) file_get_contents(self::root().'/'.self::TEMPLATE);
        // Twig comments may name the action in prose.
        $template = preg_replace('/\{#.*?#\}/s', '', $template) ?? '';

        self::assertNotSame('', $template, 'The template was not read.');
        self::assertSame(1, substr_count($template, 'live#$render'), 'Exactly one element asks for a morph.');
        self::assertMatchesRegularExpression('/form_row\(form\.category,\s*\{attr:\s*\{\'data-action\':\s*\'change->live#\$render\'\}\}\)/', $template);
        self::assertStringNotContainsString('data-model', $template, 'A per-field data-model would override the form-wide norender.');
        self::assertStringNotContainsString('on(change)', $template);
    }

    public function testTheDetailsFieldsetIsDimmedByTheRootsAriaBusyNotByADataLoadingAttribute(): void
    {
        $template = (string) file_get_contents(self::root().'/'.self::TEMPLATE);
        $template = preg_replace('/\{#.*?#\}/s', '', $template) ?? '';

        self::assertNotSame('', $template, 'The template was not read.');
        self::assertStringContainsString('<fieldset class="listing-details">', $template);
        self::assertStringNotContainsString('data-loading', $template, 'addAttribute(aria-busy) renders aria-busy="", which ARIA reads as false.');
    }
}
