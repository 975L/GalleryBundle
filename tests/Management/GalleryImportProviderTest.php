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
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Management\BlockDataExporter;
use c975L\UiBundle\Management\BlockDataImporter;
use c975L\UiBundle\Registry\FormBlockDependencyRegistry;
use c975L\UiBundle\Video\VideoPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

class GalleryImportProviderTest extends TestCase
{
    private function createCategoryRepository(?GalleryCategory $existingCategory = null): GalleryCategoryRepository
    {
        $repository = $this->createStub(GalleryCategoryRepository::class);
        $repository->method('findOneBySlug')->willReturn($existingCategory);

        return $repository;
    }

    // Builds a temp extraction dir (mirroring what ContentImportController produces) containing files/$name => $content for each entry
    private function createFilesDir(array $entries): string
    {
        $filesDir = sys_get_temp_dir() . '/gallery_import_test_' . bin2hex(random_bytes(4));
        mkdir($filesDir . '/files', 0777, true);
        foreach ($entries as $name => $content) {
            file_put_contents($filesDir . '/files/' . $name, $content);
        }

        return $filesDir;
    }

    // Plays what ContentImportController does with a zip: the archive's entries laid out under one dir, keyed by the very path the exported items point at
    private function extractArchive(array $files): string
    {
        $filesDir = sys_get_temp_dir() . '/gallery_import_test_' . bin2hex(random_bytes(4));
        foreach ($files as $archivePath => $diskPath) {
            $target = $filesDir . '/' . $archivePath;
            if (!is_dir(\dirname($target))) {
                mkdir(\dirname($target), 0777, true);
            }
            copy($diskPath, $target);
        }

        return $filesDir;
    }

    private function removeDir(string $dir): void
    {
        $paths = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }

        rmdir($dir);
    }

    private function createProvider(EntityManagerInterface $em, ?GalleryCategory $existingCategory = null, ?string $projectDir = null): GalleryImportProvider
    {
        return new GalleryImportProvider(
            $em,
            $this->createCategoryRepository($existingCategory),
            new GalleryMediaSlugger(new AsciiSlugger()),
            new BlockDataImporter($em, $this->createStub(FormBlockDependencyRegistry::class)),
            $projectDir ?? sys_get_temp_dir(),
        );
    }

    public function testSupportsImportOnlyMatchesGalleryCategoryKind(): void
    {
        $provider = $this->createProvider($this->createStub(EntityManagerInterface::class));

        $this->assertTrue($provider->supportsImport('gallery_category'));
        $this->assertFalse($provider->supportsImport('site_page'));
    }

    public function testImportCreatesTheCategoryWithItsMediasWhenItIsMissing(): void
    {
        $filesDir = $this->createFilesDir(['p1.jpg' => 'bytes-1', 'p2.jpg' => 'bytes-2']);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = $this->createProvider($em);

        $result = $provider->import([[
            'slug' => 'voyages',
            'title' => 'Voyages',
            'position' => 0,
            'uncategorized' => false,
            'coverMediaIndex' => 1,
            'medias' => [
                ['title' => 'Media 1', 'slug' => 'media-1', 'position' => 0, 'file' => 'files/p1.jpg'],
                ['title' => 'Media 2', 'slug' => 'media-2', 'position' => 1, 'file' => 'files/p2.jpg'],
            ],
        ]], $filesDir);

        $this->assertSame(['created' => 1, 'updated' => 0], $result);

        $category = null;
        $medias = [];
        foreach ($persisted as $entity) {
            if ($entity instanceof GalleryCategory) {
                $category = $entity;
            } elseif ($entity instanceof GalleryMedia) {
                $medias[] = $entity;
            }
        }

        $this->assertInstanceOf(GalleryCategory::class, $category);
        $this->assertSame('voyages', $category->getSlug());
        $this->assertCount(2, $medias);
        $this->assertSame($medias[1], $category->getCoverMedia());
        $this->assertSame($filesDir . '/files/p2.jpg', $medias[1]->getFile()->getPathname());
        // The exported slug is put back as it was, so the imported medias answer at the very urls the archive came from
        $this->assertSame(['media-1', 'media-2'], array_map(static fn (GalleryMedia $media): ?string => $media->getSlug(), $medias));

        unlink($filesDir . '/files/p1.jpg');
        unlink($filesDir . '/files/p2.jpg');
        rmdir($filesDir . '/files');
        rmdir($filesDir);
    }

    // An archive exported before the "photos" -> "medias" rename still imports its entries, rather than landing an empty category
    public function testImportReadsTheLegacyPhotoKeys(): void
    {
        $filesDir = $this->createFilesDir(['p1.jpg' => 'bytes-1']);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = $this->createProvider($em);

        $provider->import([[
            'slug' => 'voyages',
            'title' => 'Voyages',
            'coverPhotoIndex' => 0,
            'photos' => [
                ['alt' => 'Photo 1', 'position' => 0, 'file' => 'files/p1.jpg'],
            ],
        ]], $filesDir);

        $medias = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof GalleryMedia));
        $category = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof GalleryCategory))[0];

        $this->assertCount(1, $medias);
        $this->assertSame('Photo 1', $medias[0]->getTitle());
        $this->assertSame('photo-1', $medias[0]->getSlug());
        $this->assertSame($medias[0], $category->getCoverMedia());

        unlink($filesDir . '/files/p1.jpg');
        rmdir($filesDir . '/files');
        rmdir($filesDir);
    }

    public function testImportReplacesAnExistingCategorysMedias(): void
    {
        $filesDir = $this->createFilesDir(['new.jpg' => 'new-bytes']);

        $oldMedia = new GalleryMedia()->setTitle('Old')->setSlug('old');
        $existingCategory = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $existingCategory->addMedia($oldMedia);

        $em = $this->createStub(EntityManagerInterface::class);

        $provider = $this->createProvider($em, $existingCategory);

        $result = $provider->import([[
            'slug' => 'voyages',
            'title' => 'Voyages',
            'medias' => [
                ['title' => 'New', 'position' => 0, 'file' => 'files/new.jpg'],
            ],
        ]], $filesDir);

        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertCount(1, $existingCategory->getMedias());
        $this->assertSame('New', $existingCategory->getMedias()->first()->getTitle());
        $this->assertNull($oldMedia->getCategory());

        unlink($filesDir . '/files/new.jpg');
        rmdir($filesDir . '/files');
        rmdir($filesDir);
    }

    // The exported lead-in is rebuilt, and the one already there replaced - Blocks have no natural key to match the imported ones against
    public function testImportReplacesTheCategorysBlocks(): void
    {
        $oldBlock = new Block()->setKind('text')->setPosition(0)->setData(['text' => 'Ancien chapô']);
        $existingCategory = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $existingCategory->addBlock($oldBlock);

        $provider = $this->createProvider($this->createStub(EntityManagerInterface::class), $existingCategory);

        $provider->import([[
            'slug' => 'voyages',
            'title' => 'Voyages',
            'blocks' => [
                ['kind' => 'text', 'position' => 0, 'data' => ['text' => 'Nouveau chapô']],
            ],
        ]], null);

        $this->assertCount(1, $existingCategory->getBlocks());
        $this->assertSame(['text' => 'Nouveau chapô'], $existingCategory->getBlocks()->first()->getData());
    }

    // The archived original is copied back under private/, named after the file Vich stored on the flush - which is why it can only happen once that flush has run
    public function testImportRestoresTheArchivedOriginalUnderPrivate(): void
    {
        $filesDir = $this->createFilesDir(['ab12_p1-original.jpg' => 'original-bytes', 'p1.webp' => 'stored-bytes']);
        $projectDir = sys_get_temp_dir() . '/gallery_import_project_' . bin2hex(random_bytes(4));

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        // Stands in for Vich, which names the stored file on flush
        $em->method('flush')->willReturnCallback(static function () use (&$persisted): void {
            foreach ($persisted as $entity) {
                if ($entity instanceof GalleryMedia && null === $entity->getFilename()) {
                    $entity->setFilename('uploads/p1.webp');
                }
            }
        });

        $provider = $this->createProvider($em, null, $projectDir);

        $provider->import([[
            'slug' => 'voyages',
            'title' => 'Voyages',
            'medias' => [
                ['title' => 'Media 1', 'slug' => 'media-1', 'file' => 'files/p1.webp', 'originalFile' => 'files/ab12_p1-original.jpg'],
            ],
        ]], $filesDir);

        $media = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryMedia))[0];
        $this->assertSame('uploads/p1-original.jpg', $media->getOriginalFilename());
        $this->assertSame('original-bytes', file_get_contents($projectDir . '/private/uploads/p1-original.jpg'));
        // The media then answers "yes" to having an original, which is what lets it be re-processed later
        $this->assertSame(GalleryMedia::ORIGINAL_DIRECTORY, $media->getOriginalDirectory());
        // Dropped before the second flush, or GalleryMediaDerivativeCleanupListener would read it as a file replacement and erase what was just written
        $this->assertNull($media->getFile());

        unlink($projectDir . '/private/uploads/p1-original.jpg');
        rmdir($projectDir . '/private/uploads');
        rmdir($projectDir . '/private');
        rmdir($projectDir);
        unlink($filesDir . '/files/ab12_p1-original.jpg');
        unlink($filesDir . '/files/p1.webp');
        rmdir($filesDir . '/files');
        rmdir($filesDir);
    }

    // The url is all a media framed from a platform carries, so a round-trip losing it loses the video itself - and the type is derived from that url on the way back in, the exported one never being read
    public function testAPlatformVideoSurvivesTheExportImportRoundTrip(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_roundtrip_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/medias/gallery/films', 0777, true);
        file_put_contents($projectDir . '/public/medias/gallery/films/nordkapp.webp', 'still-bytes');

        $exportedMedia = new GalleryMedia()
            ->setTitle('Nordkapp')
            ->setSlug('nordkapp')
            ->setFilename('medias/gallery/films/nordkapp.webp')
            ->setExternalUrl('https://www.youtube.com/watch?v=abc123');
        $category = new GalleryCategory()->setSlug('films')->setTitle('Films');
        $category->addMedia($exportedMedia);

        $exported = new GalleryExportProvider($this->createStub(GalleryCategoryRepository::class), new BlockDataExporter($projectDir), $projectDir)->serialize([$category]);
        $filesDir = $this->extractArchive($exported['files']);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createProvider($em)->import($exported['items'], $filesDir);

        $imported = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryMedia))[0];
        $this->assertSame($exportedMedia->getExternalUrl(), $imported->getExternalUrl());
        $this->assertSame(VideoPlatform::Youtube->value, $imported->getMediaType());

        $this->removeDir($filesDir);
        $this->removeDir($projectDir);
    }

    // An archive exported before the url rework stored a platform name beside a bare id - rebuilt into the url that platform gives it, rather than importing a video that plays nothing
    public function testImportRebuildsTheUrlOfAnArchiveStoringAPlatformAndAnId(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createProvider($em)->import([[
            'slug' => 'films',
            'title' => 'Films',
            'medias' => [
                ['title' => 'Nordkapp', 'mediaType' => 'youtube', 'externalId' => 'abc123'],
                // A platform nobody declares anymore has no url to rebuild, and imports as the image the entry already carried
                ['title' => 'Vine', 'mediaType' => 'vine', 'externalId' => 'xyz789'],
            ],
        ]]);

        $medias = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryMedia));
        $this->assertSame('https://www.youtube-nocookie.com/embed/abc123', $medias[0]->getExternalUrl());
        $this->assertSame(VideoPlatform::Youtube->value, $medias[0]->getMediaType());
        $this->assertNull($medias[1]->getExternalUrl());
        $this->assertSame(GalleryMedia::MEDIA_TYPE_IMAGE, $medias[1]->getMediaType());
    }

    // The exported "mediaType" is written for whoever reads an archive, never read back: what the media turns out to carry decides, so the two can't be imported out of step
    public function testImportDerivesTheTypeRatherThanReadingTheExportedOne(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createProvider($em)->import([[
            'slug' => 'films',
            'title' => 'Films',
            'medias' => [
                // Claims a video, carries no url: imports as the image it is
                ['title' => 'Sans url', 'mediaType' => 'youtube'],
                // Claims a type nobody declares, carries a url no platform recognizes: kept as pasted and framed as a plain embed
                ['title' => 'Ailleurs', 'mediaType' => 'wobbleflix', 'externalUrl' => 'https://videos.example.org/embed/42'],
            ],
        ]]);

        $medias = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryMedia));
        $this->assertNull($medias[0]->getExternalUrl());
        $this->assertSame(GalleryMedia::MEDIA_TYPE_IMAGE, $medias[0]->getMediaType());
        $this->assertSame('https://videos.example.org/embed/42', $medias[1]->getExternalUrl());
        $this->assertSame(GalleryMedia::MEDIA_TYPE_EMBED, $medias[1]->getMediaType());
    }

    // Every item of the same archive is imported, each matched on its own slug
    public function testImportCreatesEveryCategoryOfTheBatch(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = $this->createProvider($em);

        $result = $provider->import([
            ['slug' => 'paysages', 'title' => 'Paysages'],
            ['slug' => 'portraits', 'title' => 'Portraits'],
        ]);

        $this->assertSame(['created' => 2, 'updated' => 0], $result);

        $categories = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryCategory));
        $this->assertCount(2, $categories);
        $this->assertSame(['paysages', 'portraits'], array_map(static fn (GalleryCategory $c): string => (string) $c->getSlug(), $categories));
    }

    // The lead-in travels with its category, and an archive predating it imports as a category without one rather than failing on the missing key
    public function testImportCarriesTheDescriptionAndToleratesItsAbsence(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = $this->createProvider($em);

        $provider->import([
            ['slug' => 'paysages', 'title' => 'Paysages', 'description' => '<div>Nos paysages</div>'],
            ['slug' => 'portraits', 'title' => 'Portraits'],
        ]);

        $categories = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryCategory));
        $this->assertSame(['<div>Nos paysages</div>', null], array_map(static fn (GalleryCategory $c): ?string => $c->getDescription(), $categories));
    }
}
