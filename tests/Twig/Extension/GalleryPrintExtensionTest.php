<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Twig\Extension;

use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Model\PrintOffer;
use c975L\GalleryBundle\Service\GalleryPrintService;
use c975L\GalleryBundle\Twig\Extension\GalleryPrintExtension;
use PHPUnit\Framework\TestCase;

// What a public page is allowed to know about prints, which is the least possible: a yes or no, the sizes to show, and how many are left
class GalleryPrintExtensionTest extends TestCase
{
    // Every reason to answer no - the shop is closed, the photograph was not marked, its original is gone, no format fits - comes back as the same plain false, and no template has to know which
    public function testAvailabilityIsAskedAsOneQuestion(): void
    {
        $this->assertTrue($this->extension(printable: true)->isPrintable(new GalleryMedia()));
        $this->assertFalse($this->extension(printable: false)->isPrintable(new GalleryMedia()));
    }

    // Cheapest first, so a page can state what a print of this photograph starts at
    public function testTheSizesAreHandedOverCheapestFirst(): void
    {
        $media = new GalleryMedia();
        $offers = $this->extension(offers: [
            $this->offer($media, '60x34', 24000),
            $this->offer($media, '20x20', 6000),
            $this->offer($media, '30x30', 12000),
        ])->getOffers($media);

        $this->assertSame(['20x20', '30x30', '60x34'], array_map(static fn (PrintOffer $offer): ?string => $offer->format->getSlug(), $offers));
    }

    public function testAPhotographOnSaleAtNoSizeHandsBackNothing(): void
    {
        $this->assertSame([], $this->extension()->getOffers(new GalleryMedia()));
    }

    // The difference between a page saying "3 left of 30" and one saying nothing at all
    public function testAnOpenEditionCountsNothingDown(): void
    {
        $this->assertNull($this->extension(remaining: null)->getRemaining(new GalleryMedia()));
    }

    public function testALimitedEditionStatesWhatIsLeftOfIt(): void
    {
        $this->assertSame(3, $this->extension(remaining: 3)->getRemaining(new GalleryMedia()));
    }

    /** @param list<PrintOffer> $offers */
    private function extension(bool $printable = false, array $offers = [], ?int $remaining = null): GalleryPrintExtension
    {
        $printService = $this->createStub(GalleryPrintService::class);
        $printService->method('isPrintable')->willReturn($printable);
        $printService->method('getOffers')->willReturn($offers);
        $printService->method('getRemaining')->willReturn($remaining);

        return new GalleryPrintExtension($printService);
    }

    private function offer(GalleryMedia $media, string $slug, int $price): PrintOffer
    {
        return new PrintOffer($media, new GalleryPrintFormat()->setSlug($slug)->setPrice($price));
    }
}
