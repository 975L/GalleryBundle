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
}
