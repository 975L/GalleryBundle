<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Management\GalleryUrlMetadataProvider;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use PHPUnit\Framework\TestCase;

class GalleryUrlMetadataProviderTest extends TestCase
{
    private function createProvider(?string $routePrefix): GalleryUrlMetadataProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($routePrefix);

        return new GalleryUrlMetadataProvider(new GalleryRoutePrefix($configService));
    }

    // The gallery index is the one page no entity speaks for, so it is the only path declared
    public function testOnlyTheIndexIsDeclared(): void
    {
        $this->assertSame(['/gallery'], $this->createProvider(GalleryRoutePrefix::DEFAULT)->getUrlMetadataPaths());
    }

    // The declared path follows the configured prefix, the routes themselves being mounted on it
    public function testTheDeclaredPathFollowsTheConfiguredRoutePrefix(): void
    {
        $this->assertSame(['/galerie'], $this->createProvider('galerie')->getUrlMetadataPaths());
    }

    // Before the entry is loaded at all, the prefix falls back on its default rather than declaring the site root
    public function testTheDeclaredPathFallsBackOnTheDefaultPrefix(): void
    {
        $this->assertSame(['/gallery'], $this->createProvider(null)->getUrlMetadataPaths());
    }
}
