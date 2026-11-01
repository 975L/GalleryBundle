<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Template;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

// What an editor writes reaches a visitor's page through the sanitizer, never as it was typed
class RawOutputTest extends TestCase
{
    // What may stay raw: the JSON-LD, which JsonLdBuilder hex-encodes
    private const array RAW_ALLOWED = ['jsonLd|raw', 'printJsonLd|raw', 'breadcrumbJsonLd|raw'];

    // Any other |raw in a template fails here, whoever writes its value
    public function testNoTemplatePrintsAnythingElseRaw(): void
    {
        $raw = [];
        foreach (new Finder()->files()->in(\dirname(__DIR__, 2) . '/templates')->name('*.twig') as $file) {
            $source = (string) preg_replace('/\{#.*?#\}/s', '', $file->getContents());
            preg_match_all('/[\w.\']+(?:\([^)]*\))?(?:\|\w+(?:\([^)]*\))?)*\|raw\b/', $source, $matches);
            foreach (array_diff($matches[0], self::RAW_ALLOWED) as $expression) {
                $raw[] = $file->getRelativePathname() . ': ' . $expression;
            }
        }

        $this->assertSame([], $raw);
    }

    // The category's summary is written in Trix, under the sanitizer CoreBundle declares for it
    public function testTheSummaryGoesThroughTheSanitizer(): void
    {
        $this->assertStringContainsString(
            "category.summarySocialNetwork|sanitize_html('c975l.rich')",
            (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/gallery/category.html.twig'),
        );
    }

    // Masking is said to be no privacy in every language, on both fields
    public function testTheHelpOfHiddenSaysFilesStayInPublic(): void
    {
        foreach (['fr', 'en', 'es'] as $locale) {
            $catalogue = (string) file_get_contents(\dirname(__DIR__, 2) . '/translations/gallery.' . $locale . '.xlf');
            foreach (['help.gallery_category_hidden', 'help.gallery_media_hidden'] as $key) {
                $this->assertMatchesRegularExpression('#<source>' . preg_quote($key, '#') . '</source>\s*<target>[^<]*public/#', $catalogue, $locale . ' ' . $key);
            }
        }
    }
}
