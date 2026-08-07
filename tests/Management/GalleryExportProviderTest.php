<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Management\GalleryExportProvider;
use c975L\GalleryBundle\Management\GalleryImportProvider;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Management\BlockDataExporter;
use PHPUnit\Framework\TestCase;

class GalleryExportProviderTest extends TestCase
{
    public function testGetKindMatchesGalleryImportProvider(): void
    {
        $provider = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter(sys_get_temp_dir()), sys_get_temp_dir());

        $this->assertSame(GalleryImportProvider::KIND, $provider->getKind());
    }

    public function testExportAllSerializesEveryCategoryFromTheRepository(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages')->setTitle('Voyages')->setPosition(0);

        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('findAll')->willReturn([$category]);

        $data = (new GalleryExportProvider($categoryRepository, new BlockDataExporter(sys_get_temp_dir()), sys_get_temp_dir()))->exportAll();

        $this->assertSame([[
            'slug' => 'voyages',
            'title' => 'Voyages',
            'position' => 0,
            'uncategorized' => false,
            'coverMediaIndex' => null,
            'blocks' => [],
            'medias' => [],
        ]], $data['items']);
        $this->assertSame([], $data['files']);
    }

    public function testSerializeRegistersMediaFilesAndCoverIndex(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_provider_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.jpg', 'bytes-1');
        file_put_contents($projectDir . '/public/uploads/p2.jpg', 'bytes-2');

        $category = (new GalleryCategory())->setSlug('voyages')->setTitle('Voyages')->setPosition(0);
        $media1 = (new GalleryMedia())->setFilename('uploads/p1.jpg')->setTitle('Media 1')->setSlug('media-1')->setPosition(0);
        $media2 = (new GalleryMedia())->setFilename('uploads/p2.jpg')->setTitle('Media 2')->setSlug('media-2')->setPosition(1);
        $category->addMedia($media1);
        $category->addMedia($media2);
        $category->setCoverMedia($media2);

        $data = (new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir))
            ->serialize([$category]);

        $item = $data['items'][0];
        $this->assertCount(2, $item['medias']);
        $this->assertSame(1, $item['coverMediaIndex']);
        $this->assertArrayHasKey('file', $item['medias'][1]);
        // Both are carried so an import can put the media back at the very url it was exported from
        $this->assertSame('Media 1', $item['medias'][0]['title']);
        $this->assertSame('media-1', $item['medias'][0]['slug']);

        $this->assertCount(2, $data['files']);
        $files = array_values($data['files']);
        sort($files);
        $this->assertSame([
            $projectDir . '/public/uploads/p1.jpg',
            $projectDir . '/public/uploads/p2.jpg',
        ], $files);

        unlink($projectDir . '/public/uploads/p1.jpg');
        unlink($projectDir . '/public/uploads/p2.jpg');
        rmdir($projectDir . '/public/uploads');
        rmdir($projectDir . '/public');
        rmdir($projectDir);
    }

    // The kept original travels beside the stored file, so a round-trip doesn't leave the imported media unable to be re-processed
    public function testSerializeArchivesTheKeptOriginalBesideTheStoredFile(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_provider_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        mkdir($projectDir . '/private/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.webp', 'stored-bytes');
        file_put_contents($projectDir . '/private/uploads/p1-original.jpg', 'original-bytes');

        $category = (new GalleryCategory())->setSlug('voyages')->setTitle('Voyages');
        $media = (new GalleryMedia())->setFilename('uploads/p1.webp')->setTitle('Media 1')->setSlug('media-1');
        $media->setOriginalFilename('uploads/p1-original.jpg');
        $category->addMedia($media);

        $data = (new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir))
            ->serialize([$category]);

        $mediaData = $data['items'][0]['medias'][0];
        $this->assertArrayHasKey('originalFile', $mediaData);
        $this->assertSame($projectDir . '/private/uploads/p1-original.jpg', $data['files'][$mediaData['originalFile']]);
        $this->assertCount(2, $data['files']);

        unlink($projectDir . '/public/uploads/p1.webp');
        unlink($projectDir . '/private/uploads/p1-original.jpg');
        rmdir($projectDir . '/public/uploads');
        rmdir($projectDir . '/public');
        rmdir($projectDir . '/private/uploads');
        rmdir($projectDir . '/private');
        rmdir($projectDir);
    }

    // A media that never kept one, or whose original has since disappeared, is exported without the key rather than with a reference to a file the archive doesn't hold
    public function testSerializeSkipsAMissingOriginal(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_provider_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.webp', 'stored-bytes');

        $category = (new GalleryCategory())->setSlug('voyages')->setTitle('Voyages');
        $media = (new GalleryMedia())->setFilename('uploads/p1.webp')->setTitle('Media 1')->setSlug('media-1');
        $media->setOriginalFilename('uploads/gone-original.jpg');
        $category->addMedia($media);

        $data = (new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir))
            ->serialize([$category]);

        $this->assertArrayNotHasKey('originalFile', $data['items'][0]['medias'][0]);
        $this->assertCount(1, $data['files']);

        unlink($projectDir . '/public/uploads/p1.webp');
        rmdir($projectDir . '/public/uploads');
        rmdir($projectDir . '/public');
        rmdir($projectDir);
    }

    // The category's editorial lead-in travels with it, a round-trip otherwise wiping it on the target
    public function testSerializeCarriesTheCategorysBlocks(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages')->setTitle('Voyages');
        $category->addBlock((new Block())->setKind('text')->setPosition(0)->setData(['text' => 'Nos voyages']));

        $data = (new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter(sys_get_temp_dir()), sys_get_temp_dir()))
            ->serialize([$category]);

        $blocks = $data['items'][0]['blocks'];
        $this->assertCount(1, $blocks);
        $this->assertSame('text', $blocks[0]['kind']);
        $this->assertSame(['text' => 'Nos voyages'], $blocks[0]['data']);
    }
}
