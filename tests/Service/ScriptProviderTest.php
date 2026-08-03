<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Service\ScriptProvider;
use PHPUnit\Framework\TestCase;

class ScriptProviderTest extends TestCase
{
    // The front-office (previous/next preload) Stimulus controller must be advertised under its AssetMapper import name
    public function testGetScriptsReturnsFrontControllersAsset(): void
    {
        $provider = new ScriptProvider();

        $this->assertSame(['@c975l/gallery-bundle/controllers.js'], $provider->getScripts());
    }
}
