<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Repository\GalleryPrintCopyRepository;
use c975L\GalleryBundle\Repository\GalleryPrintFormatRepository;
use c975L\GalleryBundle\Service\GalleryPrintService;
use PHPUnit\Framework\TestCase;

// The one question every public page asks - "is this photograph for sale" - and the sizes it is actually offered at. Everything that answers no has to answer no quietly: the shop is closed, the photograph was not marked, its gallery is masked, no original was kept, no format fits it
class GalleryPrintServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/gallery-print-service-' . uniqid();
        mkdir($this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/*') ?: []);
        rmdir($this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY);
        rmdir($this->projectDir);
    }

    public function testTheShopBeingOffIsAnAnswerAndNotAFailure(): void
    {
        $media = $this->printableMedia('square.webp', 4000, 4000);

        $this->assertFalse($this->service(enabled: false, formats: [$this->square()])->isPrintable($media));
    }

    // Deliberately not inferred from the api key: a key is pasted to try a lab's sandbox long before anything should be on sale
    public function testTheSwitchAloneOpensTheShop(): void
    {
        $this->assertTrue($this->service(enabled: true)->isEnabled());
        $this->assertFalse($this->service(enabled: false)->isEnabled());
    }

    public function testAPhotographNobodyMarkedIsNotForSale(): void
    {
        $media = $this->printableMedia('square.webp', 4000, 4000)->setPrintable(false);

        $this->assertFalse($this->service(formats: [$this->square()])->isPrintable($media));
    }

    public function testAHiddenPhotographIsNotForSale(): void
    {
        $media = $this->printableMedia('square.webp', 4000, 4000)->setHidden(true);

        $this->assertFalse($this->service(formats: [$this->square()])->isPrintable($media));
    }

    // The basket is reached without ever going through the page that would have said so, so the gallery has to be asked here too
    public function testAPhotographOfAMaskedGalleryIsNotForSale(): void
    {
        $media = $this->printableMedia('square.webp', 4000, 4000);
        $media->getCategory()?->setHidden(true);

        $this->assertFalse($this->service(formats: [$this->square()])->isPrintable($media));
    }

    public function testAVideoIsNotForSale(): void
    {
        $media = $this->printableMedia('square.webp', 4000, 4000)->setVideoFilename('clip.mp4');

        $this->assertFalse($this->service(formats: [$this->square()])->isPrintable($media));
    }

    // A gallery uploaded without "keep the original" has no file with the pixels a print needs
    public function testAPhotographWhoseOriginalWasNeverKeptOffersNothing(): void
    {
        $media = new GalleryMedia()->setPrintable(true)->setCategory(new GalleryCategory());

        $this->assertSame([], $this->service(formats: [$this->square()])->getOffers($media));
        $this->assertFalse($this->service(formats: [$this->square()])->isPrintable($media));
    }

    public function testAnOriginalNamedButGoneFromTheDiskOffersNothing(): void
    {
        $media = new GalleryMedia()->setPrintable(true)->setCategory(new GalleryCategory());
        $media->setOriginalFilename('vanished.webp');

        $this->assertSame([], $this->service(formats: [$this->square()])->getOffers($media));
    }

    // The catalogue proposes, the file disposes: a square photograph is never offered a 16:9 print it would have to be cropped into
    public function testOnlyTheFormatsMatchingThePhotographsProportionsAreOffered(): void
    {
        $media = $this->printableMedia('square.webp', 4000, 4000);

        $offers = $this->service(formats: [$this->square(), $this->wide()])->getOffers($media);

        $this->assertCount(1, $offers);
        $this->assertSame('30x30', $offers[0]->format->getSlug());
    }

    public function testAWidePhotographIsOfferedTheWideFormatAndNotTheSquareOne(): void
    {
        $media = $this->printableMedia('wide.webp', 3840, 2160);

        $offers = $this->service(formats: [$this->square(), $this->wide()])->getOffers($media);

        $this->assertCount(1, $offers);
        $this->assertSame('60x34', $offers[0]->format->getSlug());
    }

    // Proportions are matched within a few percent, a camera's own ratio never landing exactly on a paper size
    public function testProportionsAreMatchedWithinTheCatalogsTolerance(): void
    {
        $media = $this->printableMedia('nearly-square.webp', 4000, 3960);

        $this->assertCount(1, $this->service(formats: [$this->square()])->getOffers($media));
    }

    public function testProportionsFurtherOutThanTheToleranceAreRefused(): void
    {
        $media = $this->printableMedia('oblong.webp', 4000, 3000);

        $this->assertSame([], $this->service(formats: [$this->square()])->getOffers($media));
    }

    // Nothing is ever printed at a size the file cannot fill: 30 cm at 300 dpi wants 3543 px on the long edge
    public function testAFileWithoutThePixelsForASizeIsNotOfferedIt(): void
    {
        $media = $this->printableMedia('small.webp', 1000, 1000);

        $this->assertSame([], $this->service(formats: [$this->square()])->getOffers($media));
    }

    public function testAnOpenEditionCountsNothingDown(): void
    {
        $media = $this->printableMedia('square.webp', 4000, 4000);

        $this->assertNull($this->service()->getRemaining($media));
    }

    public function testALimitedEditionStatesWhatIsLeftOfIt(): void
    {
        $media = $this->printableMedia('square.webp', 4000, 4000)->setEditionSize(30);

        $this->assertSame(4, $this->service(available: 4)->getRemaining($media));
    }

    // What a basket line names, read back months later - either half may be gone since
    public function testABasketLineIsResolvedBackIntoThePairItWasBuiltFrom(): void
    {
        $media = $this->printableMedia('square.webp', 4000, 4000);
        $offer = $this->service(media: $media, formats: [$this->square()])->findOffer('12:30x30');

        $this->assertNotNull($offer);
        $this->assertSame($media, $offer->media);
        $this->assertSame('30x30', $offer->format->getSlug());
    }

    public function testABasketLineNamingAPhotographThatIsGoneResolvesToNothing(): void
    {
        $this->assertNull($this->service(media: null, formats: [$this->square()])->findOffer('12:30x30'));
    }

    public function testABasketLineNamingAWithdrawnFormatResolvesToNothing(): void
    {
        $media = $this->printableMedia('square.webp', 4000, 4000);

        $this->assertNull($this->service(media: $media, formats: [])->findOffer('12:withdrawn'));
    }

    public function testAnIdNothingEverWroteResolvesToNothing(): void
    {
        $this->assertNull($this->service()->findOffer('nonsense'));
    }

    /** @param list<GalleryPrintFormat> $formats */
    private function service(
        bool $enabled = true,
        array $formats = [],
        int $available = 0,
        GalleryMedia | false | null $media = false,
    ): GalleryPrintService {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $slug): mixed => 'gallery-print-enabled' === $slug ? $enabled : null,
        );

        $formatRepository = $this->createStub(GalleryPrintFormatRepository::class);
        $formatRepository->method('findPublished')->willReturn($formats);
        $formatRepository->method('findBySlug')->willReturnCallback(
            static function (string $slug) use ($formats): ?GalleryPrintFormat {
                foreach ($formats as $format) {
                    if ($format->getSlug() === $slug) {
                        return $format;
                    }
                }

                return null;
            },
        );

        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('find')->willReturn(false === $media ? null : $media);

        $copyRepository = $this->createStub(GalleryPrintCopyRepository::class);
        $copyRepository->method('countAvailable')->willReturn($available);

        return new GalleryPrintService($configService, $formatRepository, $mediaRepository, $copyRepository, $this->projectDir);
    }

    // A photograph marked for sale, filed in a gallery that is shown, with its untouched original really on the disk
    private function printableMedia(string $filename, int $width, int $height): GalleryMedia
    {
        imagewebp(imagecreatetruecolor($width, $height), $this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/' . $filename);

        $media = new GalleryMedia()->setPrintable(true)->setCategory(new GalleryCategory());
        $media->setOriginalFilename($filename);

        return $media;
    }

    // 30 x 30 cm at 300 dpi - 3543 px on the long edge
    private function square(): GalleryPrintFormat
    {
        return new GalleryPrintFormat()->setSlug('30x30')->setLabel('30 x 30 cm')->setWidthCm(30)->setHeightCm(30)->setPrice(12000);
    }

    // 60 x 34 cm, the 16:9 of a gallery shot for the screen - at 150 dpi, a large print being read from further away
    private function wide(): GalleryPrintFormat
    {
        return new GalleryPrintFormat()->setSlug('60x34')->setLabel('60 x 34 cm')->setWidthCm(60)->setHeightCm(34)->setDpi(150)->setPrice(24000);
    }
}
