<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Service\StylesheetProvider;
use PHPUnit\Framework\TestCase;

class StylesheetProviderTest extends TestCase
{
    public function testGetStylesheetsReturnsTheCompiledSheet(): void
    {
        $provider = new StylesheetProvider();

        $this->assertSame(['bundles/c975lgallery/css/styles.min.css'], $provider->getStylesheets());
    }

    // The path is served by the symlink "assets:install" writes, so a sheet advertised but never compiled is a 404 on every page
    public function testTheAdvertisedSheetIsActuallyShipped(): void
    {
        foreach ((new StylesheetProvider())->getStylesheets() as $stylesheet) {
            $path = \dirname(__DIR__, 2) . '/public/' . preg_replace('#^bundles/c975lgallery/#', '', $stylesheet);

            $this->assertFileExists($path, sprintf('"%s" is advertised but the sass has not been compiled.', $stylesheet));
        }
    }
}
