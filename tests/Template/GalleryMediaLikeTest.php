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

// A photo is liked or it is not: UiBundle's widget serves scales elsewhere, and what this gallery asks it for is one heart. Nothing renders the template here, so the contract is read where it is written
class GalleryMediaLikeTest extends TestCase
{
    private const string MEDIA_PAGE = 'templates/gallery/media.html.twig';

    // A single icon is what turns the widget into a "like": the count replaces the average, and clicking the heart again takes the like back
    public function testThePhotoIsLikedAndNeverScored(): void
    {
        $page = $this->read(self::MEDIA_PAGE);

        $this->assertStringContainsString('scale="1"', $page);
        $this->assertStringContainsString('icon="heart"', $page);
    }

    // The thing liked is named the way c975L\UiBundle\Entity\Rating stores it, and the id is the media's own
    public function testTheLikeNamesTheMediaItBelongsTo(): void
    {
        $this->assertStringContainsString(
            '<twig:c975LUi:Rating:Rating ownerType="gallery_media" ownerId="{{ media.id }}"',
            $this->read(self::MEDIA_PAGE)
        );
    }

    // On unless the site turned it off: a photo is there to be liked, and the setting is there for the gallery that would rather it was not
    public function testTheHeartOnlyShowsWhereTheSiteLeftItOn(): void
    {
        $this->assertStringContainsString("{% if config('gallery-rating')|to_bool %}", $this->read(self::MEDIA_PAGE));
    }

    // The setting the condition above reads has to be one ConfigBundle seeds, and on by default
    public function testTheSettingIsDeclaredAndOnByDefault(): void
    {
        $configs = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/config/configs.json'), true, 512, \JSON_THROW_ON_ERROR);

        $entry = array_values(array_filter($configs, static fn (array $config): bool => 'gallery-rating' === $config['slug']));

        $this->assertCount(1, $entry, 'No config declares the slug "gallery-rating"');
        $this->assertSame('bool', $entry[0]['kind']);
        $this->assertSame('true', $entry[0]['value']);
    }

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
