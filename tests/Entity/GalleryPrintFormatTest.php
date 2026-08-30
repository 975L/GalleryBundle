<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Entity;

use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use PHPUnit\Framework\TestCase;

// The arithmetic that decides which photographs are offered which sizes - the one place a wrong number sells a print that comes out pixellated or a print of something other than the photograph
class GalleryPrintFormatTest extends TestCase
{
    private function format(int $width, int $height, int $dpi = GalleryPrintFormat::DEFAULT_DPI): GalleryPrintFormat
    {
        return new GalleryPrintFormat()
            ->setWidthCm($width)
            ->setHeightCm($height)
            ->setDpi($dpi)
        ;
    }

    // Always at or above 1, whichever way round the sheet is: what is compared is proportions, and a lab prints a 30x45 the way the file comes
    public function testRatioIgnoresOrientation(): void
    {
        $this->assertSame(1.5, $this->format(30, 45)->getRatio());
        $this->assertSame(1.5, $this->format(45, 30)->getRatio());
    }

    public function testSquareFormatHasRatioOfOne(): void
    {
        $this->assertSame(1.0, $this->format(30, 30)->getRatio());
    }

    // 45 cm at 300 dpi is 5314.96 pixels, and the ceiling is what is asked for: a file one pixel short is a print one pixel short
    public function testRequiredPixelsFollowTheLongEdgeAndTheResolution(): void
    {
        $this->assertSame(5315, $this->format(30, 45)->getRequiredPixels());
        $this->assertSame(3544, $this->format(30, 45, 200)->getRequiredPixels());
    }

    // A 3:2 photograph belongs on a 30x45 sheet and nowhere near a square one
    public function testExactRatioIsAccepted(): void
    {
        $this->assertTrue($this->format(30, 45)->acceptsRatio(1.5));
        $this->assertFalse($this->format(30, 30)->acceptsRatio(1.5));
    }

    // 24x30 is 1.25 and a 4:3 sensor is 1.333: eight per cent apart, which the tolerance refuses - offering it would mean cropping the photograph without saying so
    public function testRatioBeyondToleranceIsRefused(): void
    {
        $this->assertFalse($this->format(24, 30)->acceptsRatio(4 / 3));
    }

    // Half a per cent apart, which is arithmetic and not a crop
    public function testRatioWithinToleranceIsAccepted(): void
    {
        $this->assertTrue($this->format(30, 40)->acceptsRatio(4 / 3));
    }

    // A format saved with no dimensions yet answers no to everything rather than dividing by zero
    public function testFormatWithoutDimensionsAcceptsNothing(): void
    {
        $format = new GalleryPrintFormat();

        $this->assertSame(0.0, $format->getRatio());
        $this->assertFalse($format->acceptsRatio(1.5));
    }
}
