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

// The caption an admin wrote is read in two places on the media page - under the photograph, and in the metas a share of it carries. Nothing renders the template here, so the contract is read where it is written
class GalleryMediaDescriptionTest extends TestCase
{
    private const string MEDIA_PAGE = 'templates/gallery/media.html.twig';

    // Most of a batch carries no caption, so the paragraph itself is conditional rather than rendered empty, and the line breaks typed in the textarea are kept
    public function testTheCaptionIsPrintedOnlyByAMediaCarryingOne(): void
    {
        $page = $this->read(self::MEDIA_PAGE);

        $this->assertStringContainsString('{% if media.description is not empty %}', $page);
        $this->assertStringContainsString('<p class="gallery-media-description">{{ media.description|nl2br }}</p>', $page);
    }

    // A legend is read before its credit: the caption sits under the media and above the credits line, as under a print
    public function testTheCaptionIsReadBeforeTheCredits(): void
    {
        $page = $this->read(self::MEDIA_PAGE);

        $caption = strpos($page, 'gallery-media-description');
        $credits = strpos($page, '<twig:c975LGallery:Gallery:Credits');

        $this->assertIsInt($caption);
        $this->assertIsInt($credits);
        $this->assertLessThan($credits, $caption);
    }

    // The page's own description metas, nobody summarising a photograph better than whoever filed it
    public function testTheCaptionIsWhatAShareOfThePageSays(): void
    {
        $this->assertStringContainsString(
            "{% set summarySocialNetwork = media.description is not empty\n    ? media.description",
            $this->read(self::MEDIA_PAGE)
        );
    }

    // Without one, the sentence composed from the site, the gallery, the title and the credits still stands
    public function testAMediaWithoutACaptionFallsBackOnTheComposedSentence(): void
    {
        $this->assertStringContainsString(
            ": [config('site-name'), category.title, media.title, media.credits]|filter(v => v is not empty)|join(' - ') %}",
            $this->read(self::MEDIA_PAGE)
        );
    }

    // The paragraph is styled by the bundle's own stylesheet rather than left to the site's paragraph rules
    public function testTheCaptionCarriesItsOwnRule(): void
    {
        $this->assertStringContainsString('.gallery-media-description {', $this->read('public/css/styles.css'));
    }

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
