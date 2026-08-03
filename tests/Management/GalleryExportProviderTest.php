<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Management\GalleryExportProvider;
use c975L\GalleryBundle\Management\GalleryImportProvider;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use PHPUnit\Framework\TestCase;

class GalleryExportProviderTest extends TestCase
{
    public function testGetKindMatchesGalleryImportProvider(): void
    {
        $provider = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), sys_get_temp_dir());

        $this->assertSame(GalleryImportProvider::KIND, $provider->getKind());
    }

    public function testExportAllSerializesEveryCategoryFromTheRepository(): void
    {
        $gallery = (new Gallery())->setSlug('main')->setTitle('Galerie')->setPosition(0)->setDefault(true);
        $category = (new GalleryCategory())->setGallery($gallery)->setSlug('voyages')->setTitle('Voyages')->setPosition(0);

        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('findAll')->willReturn([$category]);

        $data = (new GalleryExportProvider($categoryRepository, sys_get_temp_dir()))->exportAll();

        $this->assertSame([[
            'gallerySlug' => 'main',
            'galleryTitle' => 'Galerie',
            'galleryPosition' => 0,
            'galleryDefault' => true,
            'slug' => 'voyages',
            'title' => 'Voyages',
            'position' => 0,
            'uncategorized' => false,
            'coverPhotoIndex' => null,
            'photos' => [],
        ]], $data['items']);
        $this->assertSame([], $data['files']);
    }

    public function testSerializeRegistersPhotoFilesAndCoverIndex(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_provider_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.jpg', 'bytes-1');
        file_put_contents($projectDir . '/public/uploads/p2.jpg', 'bytes-2');

        $gallery = (new Gallery())->setSlug('main')->setTitle('Galerie')->setPosition(0)->setDefault(true);
        $category = (new GalleryCategory())->setGallery($gallery)->setSlug('voyages')->setTitle('Voyages')->setPosition(0);
        $photo1 = (new GalleryPhoto())->setFilename('uploads/p1.jpg')->setAlt('Photo 1')->setPosition(0);
        $photo2 = (new GalleryPhoto())->setFilename('uploads/p2.jpg')->setAlt('Photo 2')->setPosition(1);
        $category->addPhoto($photo1);
        $category->addPhoto($photo2);
        $category->setCoverPhoto($photo2);

        $data = (new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), $projectDir))
            ->serialize([$category]);

        $item = $data['items'][0];
        $this->assertCount(2, $item['photos']);
        $this->assertSame(1, $item['coverPhotoIndex']);
        $this->assertArrayHasKey('file', $item['photos'][1]);

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
}
