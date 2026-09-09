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

// What this bundle still owns of the zoom, now that the gesture is UiBundle's: the five props handed to c975LUi:Image:Zoom. Nothing renders the template here - a component tag needs the whole UX runtime - so the contract is read where it is written
class GalleryLightboxTest extends TestCase
{
    private const string LIGHTBOX = 'templates/components/Gallery/Lightbox.html.twig';

    // The two files, which is the whole reason this wrapper exists: the page shows the stored one and the zoom opens the high resolution beside it
    public function testTheTwoFilesAreNamed(): void
    {
        $template = $this->read(self::LIGHTBOX);

        $this->assertStringContainsString('src="{{ media.filename }}"', $template, 'The zoom is handed no file to display, and the media page shows nothing.');
        $this->assertStringContainsString('highres="{{ media.highresFilename }}"', $template, 'The zoom is handed no high resolution, so it renders the picture alone and the link that opened it is gone.');
    }

    // A media whose title is empty falls back on its category's, and the alternative is what a screen reader has of the photo
    public function testThePhotoIsNamed(): void
    {
        $this->assertStringContainsString('alt="{{ mediaAlt }}"', $this->read(self::LIGHTBOX), 'The photo is rendered without an alternative, and read as decorative.');
    }

    // UiBundle's own label is a generic one: what announces this link is the gallery's wording, in the gallery catalogue
    public function testTheLinkIsAnnouncedWithThisBundlesOwnWords(): void
    {
        $this->assertStringContainsString(
            "label=\"{{ 'label.gallery_see_high_resolution'|trans({}, 'gallery') }}\"",
            $this->read(self::LIGHTBOX),
            "The link falls back on UiBundle's generic label instead of the gallery's own."
        );
    }

    // The class carries the passe-partout and the right-click blocking, and is what tests/Assets/GalleryViewingBehaviourTest queries the displayed photo by
    public function testTheDisplayedPhotoKeepsThisGallerysClass(): void
    {
        $this->assertStringContainsString('class="media gallery-media-display"', $this->read(self::LIGHTBOX), 'The photo loses its mount, its drag blocking and the selector the behaviour tests find it by.');
    }

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
