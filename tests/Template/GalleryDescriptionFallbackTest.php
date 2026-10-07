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

// The index and a gallery nobody described still carry a description, a sentence naming the site rather than an empty meta. Nothing renders the template here, so the contract is read where it is written
class GalleryDescriptionFallbackTest extends TestCase
{
    // The row an editor wrote for /galerie wins, the sentence being its fallback
    public function testTheIndexFallsBackOnASentenceNamingTheSite(): void
    {
        $this->assertStringContainsString(
            "{% set summarySocialNetwork = url_metadata_summary('text.meta_gallery'|trans({'%site%': config('site-name')}, 'gallery')) %}",
            $this->read('templates/gallery/index.html.twig')
        );
    }

    // A gallery's own summary wins, the automatic one, written with none, falling back on a sentence naming it
    public function testAGalleryWithoutASummaryFallsBackOnASentenceNamingIt(): void
    {
        $this->assertStringContainsString(
            "{% set summarySocialNetwork = category.summarySocialNetwork|striptags|trim is not empty ? category.summarySocialNetwork : 'text.meta_category'|trans({'%category%': category.title, '%site%': config('site-name')}, 'gallery') %}",
            $this->read('templates/gallery/category.html.twig')
        );
    }

    // Both sentences exist in every language the bundle ships
    public function testBothSentencesAreTranslated(): void
    {
        foreach (['fr', 'en', 'es'] as $locale) {
            $catalog = $this->read('translations/gallery.' . $locale . '.xlf');

            $this->assertStringContainsString('<source>text.meta_gallery</source>', $catalog, $locale);
            $this->assertStringContainsString('<source>text.meta_category</source>', $catalog, $locale);
        }
    }

    // Reads a bundle file from its path relative to the repo root
    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
