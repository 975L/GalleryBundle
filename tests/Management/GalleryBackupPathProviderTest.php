<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\ConfigBundle\Management\BackupPath;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Management\GalleryBackupPathProvider;
use PHPUnit\Framework\TestCase;

class GalleryBackupPathProviderTest extends TestCase
{
    private function paths(): array
    {
        $modes = [];
        foreach ((new GalleryBackupPathProvider())->getBackupPaths() as $path) {
            $modes[$path->path] = $path->mode;
        }

        return $modes;
    }

    // Mirrored, never archived: a gallery of several gigabytes carried in a dated .tar.bz2 on every run is a backup nobody keeps
    public function testBothMediaRootsAreMirrored(): void
    {
        $this->assertSame([
            'public/medias/gallery' => BackupPath::MODE_MIRROR,
            'private/medias/gallery' => BackupPath::MODE_MIRROR,
        ], $this->paths());
    }

    // The declared roots are what the medias are actually written under - a file stored elsewhere would be backed up nowhere, and nothing would say so
    public function testTheDeclaredRootsCoverWhereAMediaIsStored(): void
    {
        $media = (new GalleryMedia())->setSlug('cailloux');
        $media->setCategory((new GalleryCategory())->setSlug('mineraux'));

        $storedPath = 'public/' . $media->getVichMediaPath();
        $originalPath = GalleryMedia::ORIGINAL_DIRECTORY . '/' . $media->getVichMediaPath();

        foreach ([$storedPath, $originalPath] as $path) {
            $declared = array_filter(array_keys($this->paths()), static fn (string $root): bool => str_starts_with($path, $root . '/'));

            $this->assertNotEmpty($declared, sprintf('"%s" is backed up nowhere.', $path));
        }
    }
}
