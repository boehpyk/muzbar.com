<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Search;

use PHPUnit\Framework\TestCase;

/**
 * search-ui AC-1, AC-2, AC-9 and AC-12: source scans. Comments are stripped first (a docblock that
 * names a forbidden token to forbid it is documentation) and every scan asserts it read something,
 * so a wrong path cannot pass vacuously.
 */
final class SearchUiScanTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * @return array<string, string> path => PHP source without comments
     */
    private function phpUnder(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/src/'.$dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                $code .= \is_array($token) ? (\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
            }
            $files[$file->getPathname()] = $code;
        }

        return $files;
    }

    private function twig(string $path): string
    {
        return preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents(self::root().'/templates/'.$path)) ?? '';
    }

    private function js(): string
    {
        $source = (string) file_get_contents(self::root().'/assets/controllers/search_controller.js');

        return preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source) ?? '';
    }

    public function testNoSearchHttpClassBranchesOnRequestHeaders(): void
    {
        $files = $this->phpUnder('Infrastructure/Search/Http');

        self::assertNotEmpty($files, 'The scan found no files: the path is wrong, so it would pass vacuously.');
        foreach ($files as $path => $code) {
            self::assertSame(0, preg_match('/->headers\b|\bisXmlHttpRequest\b|\bgetPreferredFormat\b|\bHTTP_[A-Z_]+\b/', $code), basename($path).' reads the request headers: one response for every client (AC-1).');
        }
    }

    public function testNoSearchClassIsAComponent(): void
    {
        $files = $this->phpUnder('Infrastructure/Search');

        self::assertNotEmpty($files);
        foreach ($files as $path => $code) {
            self::assertSame(0, preg_match('/\bAsLiveComponent\b|\bAsTwigComponent\b/', $code), basename($path).' is a component: a second door to the search (AC-2).');
        }
    }

    public function testTheBrowseTemplateFillsTheAlertSlotThroughTheOnePartialWithTheSurvivingQuery(): void
    {
        $template = $this->twig('search/browse.html.twig');

        self::assertSame(1, substr_count($template, "{% include 'search/_alert_slot.html.twig' with {search: searchParams} only %}"));
        self::assertStringContainsString('data-search-url="{{ path(\'app_browse\', searchParams) }}"', $template);
        self::assertSame(0, preg_match('/app\.request\.query/', $template), 'The raw query string echoes hostile parameters (AC-3).');

        $partial = (string) file_get_contents(self::root().'/templates/search/_alert_slot.html.twig');
        self::assertSame('', trim($this->twig('search/_alert_slot.html.twig')), 'The slot renders nothing until search-alert-subscribe.');
        self::assertStringContainsString('search-alert-subscribe', $partial);
    }

    public function testTheTemplateBindsNoKeystrokeEvent(): void
    {
        self::assertSame(0, preg_match('/\binput->/', $this->twig('search/browse.html.twig')), 'Typing never searches (AC-12).');
    }

    public function testTheControllerIsLazyHasOneFetchAndTouchesNothingForbidden(): void
    {
        $raw = (string) file_get_contents(self::root().'/assets/controllers/search_controller.js');
        $code = $this->js();

        self::assertGreaterThan(500, \strlen($code), 'The scan read (almost) nothing.');
        self::assertStringContainsString("/* stimulusFetch: 'lazy' */", $raw);
        self::assertSame(1, preg_match("#^/\\* stimulusFetch: 'lazy' \\*/#", $raw), 'The marker is the first thing in the file, where the loader reads it.');
        self::assertSame(1, substr_count($code, 'fetch('), 'One request path.');
        self::assertSame(0, preg_match('#/_components|XMLHttpRequest|setInterval|localStorage|sessionStorage|umami#i', $code));
        self::assertSame(0, preg_match('/[\'"]input[\'"]/', $code), 'No listener for the input event.');
        self::assertSame(0, preg_match('/\binput->/', $code));
    }
}
