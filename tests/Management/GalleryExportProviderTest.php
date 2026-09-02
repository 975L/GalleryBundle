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
        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages')->setSummarySocialNetwork('<div>Nos voyages</div>');

        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('findAll')->willReturn([$category]);

        $data = new GalleryExportProvider($categoryRepository, new BlockDataExporter(sys_get_temp_dir()), sys_get_temp_dir())->exportAll();

        $this->assertSame([[
            'slug' => 'voyages',
            'title' => 'Voyages',
            'summarySocialNetwork' => '<div>Nos voyages</div>',
            'data' => [],
            'uncategorized' => false,
            'automaticKind' => null,
            'isDeleted' => false,
            'hidden' => false,
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

        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $media1 = new GalleryMedia()->setFilename('uploads/p1.jpg')->setTitle('Media 1')->setSlug('media-1')->setDescription('Le port au petit matin')->setData(['photographer' => 'Laurent'])->setPosition(0);
        $media2 = new GalleryMedia()->setFilename('uploads/p2.jpg')->setTitle('Media 2')->setSlug('media-2')->setPosition(1);
        $category->addMedia($media1);
        $category->addMedia($media2);
        $category->setCoverMedia($media2);

        $data = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir)
            ->serialize([$category]);

        $item = $data['items'][0];
        $this->assertCount(2, $item['medias']);
        $this->assertSame(1, $item['coverMediaIndex']);
        $this->assertArrayHasKey('file', $item['medias'][1]);
        // A media whose derivatives have left the disk is exported without the keys, rather than pointing at bytes the archive doesn't hold
        $this->assertArrayNotHasKey('thumbFile', $item['medias'][1]);
        // Both are carried so an import can put the media back at the very url it was exported from
        $this->assertSame('Media 1', $item['medias'][0]['title']);
        $this->assertSame('media-1', $item['medias'][0]['slug']);
        // And the name of the file itself, so the images answer at the very same urls too
        $this->assertSame('uploads/p1.jpg', $item['medias'][0]['filename']);
        // The caption is exported alongside them, a photograph losing on the way out what says what it shows being the one thing an archive cannot rebuild
        $this->assertSame('Le port au petit matin', $item['medias'][0]['description']);
        $this->assertNull($item['medias'][1]['description']);
        // And what the site added of its own, carried whole without the archive knowing its shape
        $this->assertSame(['photographer' => 'Laurent'], $item['medias'][0]['data']);
        $this->assertSame([], $item['medias'][1]['data']);

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

    // Kept off the public pages here, kept off them there: an archive that republished what an admin had masked would defeat what the trash flag beside it is for
    public function testSerializeCarriesTheMaskAndTheSaleFlagOfEachMedia(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_provider_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.jpg', 'bytes-1');
        file_put_contents($projectDir . '/public/uploads/p2.jpg', 'bytes-2');

        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $shown = new GalleryMedia()->setFilename('uploads/p1.jpg')->setTitle('Media 1')->setSlug('media-1')->setPrintable(true);
        $masked = new GalleryMedia()->setFilename('uploads/p2.jpg')->setTitle('Media 2')->setSlug('media-2')->setHidden(true);
        $category->addMedia($shown)->addMedia($masked);

        $data = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir)
            ->serialize([$category]);

        $medias = $data['items'][0]['medias'];
        // A gallery shown here is shown there, its own mask travelling apart from its medias'
        $this->assertFalse($data['items'][0]['hidden']);
        $this->assertFalse($medias[0]['hidden']);
        $this->assertTrue($medias[0]['printable']);
        $this->assertTrue($medias[1]['hidden']);
        $this->assertFalse($medias[1]['printable']);

        unlink($projectDir . '/public/uploads/p1.jpg');
        unlink($projectDir . '/public/uploads/p2.jpg');
        rmdir($projectDir . '/public/uploads');
        rmdir($projectDir . '/public');
        rmdir($projectDir);
    }

    // A whole gallery taken off the site travels masked, so a sync mirrors the source rather than publishing what it had taken down
    public function testSerializeCarriesTheMaskOfTheCategory(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_provider_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.jpg', 'bytes-1');

        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages')->setHidden(true);
        $category->addMedia(new GalleryMedia()->setFilename('uploads/p1.jpg')->setTitle('Media 1')->setSlug('media-1'));

        $data = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir)
            ->serialize([$category]);

        $item = $data['items'][0];
        $this->assertTrue($item['hidden']);
        // Masking a gallery marks none of its medias, exactly as trashing one marks none of them
        $this->assertFalse($item['medias'][0]['hidden']);

        unlink($projectDir . '/public/uploads/p1.jpg');
        rmdir($projectDir . '/public/uploads');
        rmdir($projectDir . '/public');
        rmdir($projectDir);
    }

    // An archive kept aside before a permanent deletion comes back exactly as it left: the category and each of its medias travel with their trash flag, rather than being republished on the site the import runs on
    public function testSerializeCarriesTheTrashFlagOfTheCategoryAndOfItsMedias(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_provider_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.jpg', 'bytes-1');
        file_put_contents($projectDir . '/public/uploads/p2.jpg', 'bytes-2');

        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $category->setIsDeleted(true);
        $shown = new GalleryMedia()->setFilename('uploads/p1.jpg')->setTitle('Media 1')->setSlug('media-1');
        $trashed = new GalleryMedia()->setFilename('uploads/p2.jpg')->setTitle('Media 2')->setSlug('media-2');
        $trashed->setIsDeleted(true);
        $category->addMedia($shown)->addMedia($trashed);

        $data = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir)
            ->serialize([$category]);

        $item = $data['items'][0];
        $this->assertTrue($item['isDeleted']);
        // A category put in the trash marks none of its medias, so the two flags travel apart
        $this->assertFalse($item['medias'][0]['isDeleted']);
        $this->assertTrue($item['medias'][1]['isDeleted']);

        unlink($projectDir . '/public/uploads/p1.jpg');
        unlink($projectDir . '/public/uploads/p2.jpg');
        rmdir($projectDir . '/public/uploads');
        rmdir($projectDir . '/public');
        rmdir($projectDir);
    }

    // The thumbnail and the high resolution travel beside the stored file: recomputed on the way back in, the high resolution would come back at the stored file's own width
    public function testSerializeArchivesTheDerivativesBesideTheStoredFile(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_provider_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.webp', 'stored-bytes');
        file_put_contents($projectDir . '/public/uploads/p1-thumb.webp', 'thumb-bytes');
        file_put_contents($projectDir . '/public/uploads/p1-highres.webp', 'highres-bytes');

        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $media = new GalleryMedia()->setFilename('uploads/p1.webp')->setTitle('Media 1')->setSlug('media-1');
        $category->addMedia($media);

        $data = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir)
            ->serialize([$category]);

        $mediaData = $data['items'][0]['medias'][0];
        $this->assertSame($projectDir . '/public/uploads/p1-thumb.webp', $data['files'][$mediaData['thumbFile']]);
        $this->assertSame($projectDir . '/public/uploads/p1-highres.webp', $data['files'][$mediaData['highresFile']]);
        $this->assertCount(3, $data['files']);

        unlink($projectDir . '/public/uploads/p1.webp');
        unlink($projectDir . '/public/uploads/p1-thumb.webp');
        unlink($projectDir . '/public/uploads/p1-highres.webp');
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

        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $media = new GalleryMedia()->setFilename('uploads/p1.webp')->setTitle('Media 1')->setSlug('media-1');
        $media->setOriginalFilename('uploads/p1-original.jpg');
        $category->addMedia($media);

        $data = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir)
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

    // The one file of the set nothing could get back from elsewhere: a media framed from a platform still carries the url that finds it again, a self-hosted video exists only here
    public function testSerializeArchivesTheSelfHostedVideoBesideItsStill(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_provider_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.webp', 'stored-bytes');
        file_put_contents($projectDir . '/public/uploads/p1-a1b2c3.mp4', 'video-bytes');

        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $media = new GalleryMedia()->setFilename('uploads/p1.webp')->setTitle('Media 1')->setSlug('media-1');
        $media->setVideoFilename('uploads/p1-a1b2c3.mp4');
        $category->addMedia($media);

        $data = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir)
            ->serialize([$category]);

        $mediaData = $data['items'][0]['medias'][0];
        $this->assertArrayHasKey('videoFile', $mediaData);
        $this->assertSame($projectDir . '/public/uploads/p1-a1b2c3.mp4', $data['files'][$mediaData['videoFile']]);
        // The name travels beside the bytes, so the video is played from the very same url on the site it lands on
        $this->assertSame('uploads/p1-a1b2c3.mp4', $mediaData['videoFilename']);
        $this->assertSame(GalleryMedia::MEDIA_TYPE_VIDEO, $mediaData['mediaType']);
        $this->assertCount(2, $data['files']);

        unlink($projectDir . '/public/uploads/p1.webp');
        unlink($projectDir . '/public/uploads/p1-a1b2c3.mp4');
        rmdir($projectDir . '/public/uploads');
        rmdir($projectDir . '/public');
        rmdir($projectDir);
    }

    // A media that never kept one, or whose original has since disappeared, is exported without the key rather than with a reference to a file the archive doesn't hold
    public function testSerializeSkipsAMissingOriginal(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_provider_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.webp', 'stored-bytes');

        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $media = new GalleryMedia()->setFilename('uploads/p1.webp')->setTitle('Media 1')->setSlug('media-1');
        $media->setOriginalFilename('uploads/gone-original.jpg');
        $category->addMedia($media);

        $data = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir)
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
        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $category->addBlock(new Block()->setKind('text')->setPosition(0)->setData(['text' => 'Nos voyages']));

        $data = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter(sys_get_temp_dir()), sys_get_temp_dir())
            ->serialize([$category]);

        $blocks = $data['items'][0]['blocks'];
        $this->assertCount(1, $blocks);
        $this->assertSame('text', $blocks[0]['kind']);
        $this->assertSame(['text' => 'Nos voyages'], $blocks[0]['data']);
    }
}
