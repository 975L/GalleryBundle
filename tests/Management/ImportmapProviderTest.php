<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\GalleryBundle\Management\ImportmapProvider;
use PHPUnit\Framework\TestCase;

class ImportmapProviderTest extends TestCase
{
    public function testGetAdminImportmapEntriesReturnsNone(): void
    {
        $this->assertSame([], (new ImportmapProvider())->getAdminImportmapEntries());
    }

    public function testGetImportmapEntriesReturnsControllersEntrypoint(): void
    {
        $entries = (new ImportmapProvider())->getImportmapEntries();

        $this->assertSame([
            '@c975l/gallery-bundle/controllers.js' => [
                'path' => 'assets/controllers.js',
                'entrypoint' => true,
            ],
        ], $entries);
    }
}
