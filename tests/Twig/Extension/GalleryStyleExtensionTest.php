<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Twig\Extension;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Twig\Extension\GalleryStyleExtension;
use PHPUnit\Framework\TestCase;
use Twig\Extension\AttributeExtension;

class GalleryStyleExtensionTest extends TestCase
{
    public function testGetFunctionsExposesTheBodyClassFunction(): void
    {
        $names = array_map(
            static fn ($function): string => $function->getName(),
            new AttributeExtension(GalleryStyleExtension::class)->getFunctions()
        );

        $this->assertSame(['gallery_body_class'], $names);
    }

    public function testAPickedStyleIsWrittenBesideTheGalleryClass(): void
    {
        $this->assertSame('gallery-page gallery-page--dark', $this->extension('dark', null)->getBodyClass());
    }

    // The frame is picked apart from the style, so either is written without the other, and the value is prefixed - "gallery-page--none" would say nothing about what is none
    public function testTheFrameIsWrittenBesideTheStyle(): void
    {
        $this->assertSame('gallery-page gallery-page--frame-wide', $this->extension(null, 'wide')->getBodyClass());
        $this->assertSame('gallery-page gallery-page--light gallery-page--frame-none', $this->extension('light', 'none')->getBodyClass());
    }

    // The state of a site that never picked either, and the one every gallery was in before the configs existed
    public function testNothingPickedLeavesTheGalleryClassAlone(): void
    {
        $this->assertSame('gallery-page', $this->extension(null, null)->getBodyClass());
        $this->assertSame('gallery-page', $this->extension('', '')->getBodyClass());
        $this->assertSame('gallery-page', $this->extension('   ', '   ')->getBodyClass());
    }

    // A value the stylesheet paints nothing for - a style since renamed, or one arriving from an import - is dropped rather than written out as a class matching no rule
    public function testAnUnknownValueIsDropped(): void
    {
        $this->assertSame('gallery-page', $this->extension('flashy', 'huge')->getBodyClass());
    }

    private function extension(?string $style, ?string $frame): GalleryStyleExtension
    {
        // A stub and not a mock: what the class does with the values is the whole subject, how many times it reads them is not
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([['gallery-style', $style], ['gallery-frame', $frame]]);

        return new GalleryStyleExtension($configService);
    }
}
