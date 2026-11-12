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
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Repository\GalleryPrintFormatRepository;
use c975L\GalleryBundle\Service\GalleryPrintFileBuilder;
use c975L\GalleryBundle\Service\GalleryPrintService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

// Where a print waits for the lab: in the folder the app names, under a name no other database can give out
class GalleryPrintFileBuilderTest extends TestCase
{
    private function builder(string $printDir): GalleryPrintFileBuilder
    {
        return new GalleryPrintFileBuilder($this->createStub(ConfigServiceInterface::class), $this->createStub(GalleryPrintService::class), $this->createStub(GalleryPrintFormatRepository::class), new Filesystem(), '/site', $printDir);
    }

    private function copy(?int $id, ?string $certificate): GalleryPrintCopy
    {
        $copy = new \ReflectionClass(GalleryPrintCopy::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(GalleryPrintCopy::class, 'id')->setValue($copy, $id);

        return $copy->setCertificate($certificate);
    }

    // The folder is the app's to choose, a demo keeping its prints apart from the site's
    public function testThePrintGoesWhereTheAppSays(): void
    {
        $this->assertSame('/site/var/demo/gallery-print/7-abc123.jpg', $this->builder('/site/var/demo/gallery-print/')->getPath($this->copy(7, 'abc123')));
    }

    // Copy 7 of one database and copy 7 of another are two prints: the certificate, drawn at random at the sale, tells them apart
    public function testTwoCopiesOfTheSameNumberAreTwoFiles(): void
    {
        $builder = $this->builder('/site/var/gallery-print');

        $this->assertNotSame($builder->getPath($this->copy(7, 'abc123')), $builder->getPath($this->copy(7, 'def456')));
    }

    // A copy not saved yet has no print to wait for
    public function testACopyWithoutANumberHasNoPath(): void
    {
        $this->assertNull($this->builder('/site/var/gallery-print')->getPath($this->copy(null, null)));
    }

    // A size taken off the catalogue after the sale is still owed to the customer who paid for it: the print is built from the format the copy names, published or not
    public function testAFormatUnpublishedAfterTheSaleStillBuildsThePrint(): void
    {
        $dir = sys_get_temp_dir() . '/gallery-print-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $original = $dir . '/original.jpg';
        imagejpeg(imagecreatetruecolor(40, 40), $original);

        $printService = $this->createStub(GalleryPrintService::class);
        $printService->method('getOriginalPath')->willReturn($original);

        $formatRepository = $this->createStub(GalleryPrintFormatRepository::class);
        $formatRepository->method('findBySlug')->willReturn(new GalleryPrintFormat()->setSlug('30x30')->setWidthCm(30)->setHeightCm(30)->setPublished(false));

        $copy = $this->copy(3, 'abc')->setMedia(new GalleryMedia())->setFormat('30x30');
        $builder = new GalleryPrintFileBuilder($this->createStub(ConfigServiceInterface::class), $printService, $formatRepository, new Filesystem(), $dir, $dir . '/prints');

        $path = $builder->build($copy);

        $this->assertNotNull($path);
        $this->assertFileExists($path);

        new Filesystem()->remove($dir);
    }
}
