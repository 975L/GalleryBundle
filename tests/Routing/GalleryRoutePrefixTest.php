<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Routing;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use PHPUnit\Framework\TestCase;

class GalleryRoutePrefixTest extends TestCase
{
    private function createRoutePrefix(mixed $configuredValue): GalleryRoutePrefix
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($configuredValue);

        return new GalleryRoutePrefix($configService);
    }

    public function testGetReturnsTheConfiguredPrefix(): void
    {
        $this->assertSame('galerie', $this->createRoutePrefix('galerie')->get());
    }

    public function testGetTrimsTheSurroundingSlashes(): void
    {
        $this->assertSame('galerie', $this->createRoutePrefix(' /galerie/ ')->get());
    }

    // An empty prefix would mount the category route at the site root, catching every single-segment url of the site
    public function testGetFallsBackToTheDefaultOnAnEmptyValue(): void
    {
        $this->assertSame('gallery', $this->createRoutePrefix(' / ')->get());
    }

    // Nothing is configured before c975l:config:load-all has run
    public function testGetFallsBackToTheDefaultWithoutAnyValue(): void
    {
        $this->assertSame('gallery', $this->createRoutePrefix(null)->get());
    }

    public function testMatchesTheConfiguredSegment(): void
    {
        $this->assertTrue($this->createRoutePrefix('galerie')->matches('galerie'));
    }

    // What keeps the routes from swallowing every two-segment url of the site
    public function testDoesNotMatchAnotherSegment(): void
    {
        $routePrefix = $this->createRoutePrefix('galerie');

        $this->assertFalse($routePrefix->matches('boutique'));
        $this->assertFalse($routePrefix->matches(null));
    }

    // The listener and every route condition ask for it on the same request: the configuration is read once
    public function testGetReadsTheConfigurationOnce(): void
    {
        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->once())->method('get')->willReturn('galerie');
        $routePrefix = new GalleryRoutePrefix($configService);

        $routePrefix->get();
        $routePrefix->matches('galerie');

        $this->assertSame('galerie', $routePrefix->get());
    }

    // A worker runtime keeps the service between requests: a prefix changed in the dashboard must still apply on the next one
    public function testResetReadsTheConfigurationAgain(): void
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('galerie', 'fotos');
        $routePrefix = new GalleryRoutePrefix($configService);

        $routePrefix->get();
        $routePrefix->reset();

        $this->assertSame('fotos', $routePrefix->get());
    }
}
