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
use c975L\UiBundle\Repository\RatingRepository;
use c975L\UiBundle\Video\VideoPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

class GalleryImportProviderTest extends TestCase
{
    private function createCategoryRepository(?GalleryCategory $existingCategory = null, ?GalleryCategory $automaticCategory = null): GalleryCategoryRepository
    {
        $repository = $this->createStub(GalleryCategoryRepository::class);
        $repository->method('findOneBySlug')->willReturn($existingCategory);
        // What the import asks before taking an archive's "automatic" flag: the gallery of the last additions this site already holds, if it has one
        $repository->method('findOneBy')->willReturn($automaticCategory);

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

    private function createProvider(EntityManagerInterface $em, ?GalleryCategory $existingCategory = null, ?string $projectDir = null, ?GalleryCategory $automaticCategory = null, ?RatingRepository $ratingRepository = null): GalleryImportProvider
    {
        return new GalleryImportProvider(
            $em,
            $this->createCategoryRepository($existingCategory, $automaticCategory),
            new GalleryMediaSlugger(new AsciiSlugger()),
            new BlockDataImporter($em, $this->createStub(FormBlockDependencyRegistry::class)),
            $ratingRepository ?? $this->createStub(RatingRepository::class),
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
                ['title' => 'Media 1', 'slug' => 'media-1', 'description' => 'Le port au petit matin', 'data' => ['photographer' => 'Laurent'], 'position' => 0, 'file' => 'files/p1.jpg'],
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
        // Not removed by Vich once stored: the very same file is copied back over what the pipeline made of it (see restoreArchivedFiles)
        $this->assertFalse($medias[1]->getFile()->isRemoveReplacedFile());
        // The exported slug is put back as it was, so the imported medias answer at the very urls the archive came from
        $this->assertSame(['media-1', 'media-2'], array_map(static fn (GalleryMedia $media): ?string => $media->getSlug(), $medias));
        // The caption travels with the media, and a media exported before there was one imports without one rather than with an empty string
        $this->assertSame('Le port au petit matin', $medias[0]->getDescription());
        $this->assertNull($medias[1]->getDescription());
        // The site's own fields make the same trip, a media exported without any importing with an empty payload rather than with null
        $this->assertSame(['photographer' => 'Laurent'], $medias[0]->getData());
        $this->assertSame([], $medias[1]->getData());

        unlink($filesDir . '/files/p1.jpg');
        unlink($filesDir . '/files/p2.jpg');
        rmdir($filesDir . '/files');
        rmdir($filesDir);
    }

    // A category exported out of the trash comes back to the trash, its medias each carrying their own flag - and an archive predating the trash imports as what it describes, a category that is not in it
    // The gallery of the last additions comes back as one on a site that has none - what the archive describes is a site whose automatic gallery is this very category
    public function testImportTakesTheAutomaticFlagWhenNoGalleryHoldsIt(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createProvider($em)->import([[
            'slug' => 'latest',
            'title' => 'Derniers ajouts',
            'automatic' => true,
            'medias' => [],
        ]]);

        $this->assertTrue($persisted[0]->isAutomatic());
    }

    // Two of them would show the same medias under two urls, so the flag is left where it already is - the imported category lands as a plain gallery
    public function testImportLeavesTheAutomaticFlagToTheGalleryAlreadyHoldingIt(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createProvider($em, automaticCategory: new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST))->import([[
            'slug' => 'latest',
            'title' => 'Derniers ajouts',
            'automatic' => true,
            'medias' => [],
        ]]);

        $this->assertFalse($persisted[0]->isAutomatic());
    }

    // import() only flushes once, at the end: asking the database inside the loop would have both items of the archive see it still empty and keep the flag, so the first one marked wins it and the ones after land as plain galleries
    public function testImportGivesTheAutomaticFlagToOnlyOneCategoryOfTheSameArchive(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createProvider($em)->import([
            [
                'slug' => 'latest',
                'title' => 'Derniers ajouts',
                'automatic' => true,
                'medias' => [],
            ],
            [
                'slug' => 'latest-bis',
                'title' => 'Derniers ajouts bis',
                'automatic' => true,
                'medias' => [],
            ],
        ]);

        $this->assertTrue($persisted[0]->isAutomatic());
        $this->assertFalse($persisted[1]->isAutomatic());
    }

    public function testImportCarriesTheTrashFlagAndDefaultsToOutOfIt(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createProvider($em)->import([[
            'slug' => 'voyages',
            'title' => 'Voyages',
            'isDeleted' => true,
            'medias' => [
                ['title' => 'Media 1', 'slug' => 'media-1', 'isDeleted' => true],
                ['title' => 'Media 2', 'slug' => 'media-2'],
            ],
        ], [
            'slug' => 'montagnes',
            'title' => 'Montagnes',
            'medias' => [],
        ]]);

        $categories = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof GalleryCategory));
        $medias = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof GalleryMedia));

        $this->assertTrue($categories[0]->isDeleted());
        $this->assertFalse($categories[1]->isDeleted());
        $this->assertTrue($medias[0]->isDeleted());
        $this->assertFalse($medias[1]->isDeleted());
    }

    // Read back for the same reason they are exported (see GalleryExportProvider): a round-trip must not republish what was masked, nor put back on sale what was taken off it - and an archive predating the two keys imports as a media that is neither
    public function testImportReadsTheMaskAndTheSaleFlagOfEachMedia(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createProvider($em)->import([[
            'slug' => 'voyages',
            'title' => 'Voyages',
            'medias' => [
                ['title' => 'Media 1', 'slug' => 'media-1', 'hidden' => true, 'printable' => false],
                ['title' => 'Media 2', 'slug' => 'media-2', 'hidden' => false, 'printable' => true],
                ['title' => 'Media 3', 'slug' => 'media-3'],
            ],
        ]]);

        $medias = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof GalleryMedia));

        $this->assertTrue($medias[0]->isHidden());
        $this->assertFalse($medias[0]->isPrintable());
        $this->assertFalse($medias[1]->isHidden());
        $this->assertTrue($medias[1]->isPrintable());
        $this->assertFalse($medias[2]->isHidden());
        $this->assertFalse($medias[2]->isPrintable());
    }

    // The gallery's own mask is read back the same way, an archive predating the key importing as a gallery that is not masked
    public function testImportReadsTheMaskOfTheCategory(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createProvider($em)->import([
            ['slug' => 'voyages', 'title' => 'Voyages', 'hidden' => true],
            ['slug' => 'objets', 'title' => 'Objets'],
        ]);

        $categories = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof GalleryCategory));

        $this->assertTrue($categories[0]->isHidden());
        $this->assertFalse($categories[1]->isHidden());
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

    // The replaced medias are gone for good, orphanRemoval deleting their rows: their likes hang off "gallery_media" + id and nothing cascades them, so a media reimported under that very id would read someone else's
    public function testImportDropsTheLikesOfTheMediasItReplaces(): void
    {
        $oldMedia = new GalleryMedia()->setTitle('Old')->setSlug('old');
        new \ReflectionProperty(GalleryMedia::class, 'id')->setValue($oldMedia, 7);
        $existingCategory = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $existingCategory->addMedia($oldMedia);

        $dropped = [];
        $ratingRepository = $this->createStub(RatingRepository::class);
        $ratingRepository->method('deleteForOwners')->willReturnCallback(function (string $ownerType, array $ownerIds) use (&$dropped): int {
            $dropped[] = [$ownerType, $ownerIds];

            return 0;
        });

        $provider = $this->createProvider($this->createStub(EntityManagerInterface::class), $existingCategory, ratingRepository: $ratingRepository);

        $provider->import([[
            'slug' => 'voyages',
            'title' => 'Voyages',
            'medias' => [['title' => 'New', 'position' => 0]],
        ]]);

        $this->assertSame([['gallery_media', [7]]], $dropped);
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

        $this->removeDir($projectDir);
        $this->removeDir($filesDir);
    }

    // What the upload pipeline recomputes from the re-uploaded stored file is overwritten by the archived files: a high resolution derived from a stored file comes back at its width instead of its own, and every round-trip re-encodes the webp once more
    public function testImportRestoresTheArchivedDerivativesOverTheRecomputedOnes(): void
    {
        $filesDir = $this->createFilesDir([
            'ab12_p1.webp' => 'stored-bytes',
            'ab12_p1-thumb.webp' => 'thumb-bytes',
            'ab12_p1-highres.webp' => 'highres-bytes',
        ]);
        $projectDir = sys_get_temp_dir() . '/gallery_import_project_' . bin2hex(random_bytes(4));

        // Stands in for what the pipeline wrote on the way in, which is exactly what the archive is meant to replace
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.webp', 'recomputed-stored');
        file_put_contents($projectDir . '/public/uploads/p1-thumb.webp', 'recomputed-thumb');
        file_put_contents($projectDir . '/public/uploads/p1-highres.webp', 'recomputed-highres');

        $media = $this->importSingleMedia([
            'title' => 'Media 1',
            'slug' => 'media-1',
            'file' => 'files/ab12_p1.webp',
            'thumbFile' => 'files/ab12_p1-thumb.webp',
            'highresFile' => 'files/ab12_p1-highres.webp',
        ], $filesDir, $projectDir);

        $this->assertSame('stored-bytes', file_get_contents($projectDir . '/public/uploads/p1.webp'));
        $this->assertSame('thumb-bytes', file_get_contents($projectDir . '/public/uploads/p1-thumb.webp'));
        $this->assertSame('highres-bytes', file_get_contents($projectDir . '/public/uploads/p1-highres.webp'));
        // The column describes the file actually served, and the pipeline had set it to the size of its own re-encoding
        $this->assertSame(\strlen('stored-bytes'), $media->getSize());

        $this->removeDir($projectDir);
        $this->removeDir($filesDir);
    }

    // The archive says what its files were called, and they go straight back under those names: nothing is handed to Vich, which would name them anew and leave the same gallery answering at different image urls on every site it is synced to
    public function testImportKeepsTheExportedFilenameAndLaysTheFilesUnderIt(): void
    {
        $filesDir = $this->createFilesDir([
            'ab12_nordkapp-a1b2c3.webp' => 'stored-bytes',
            'ab12_nordkapp-a1b2c3-thumb.webp' => 'thumb-bytes',
            'ab12_nordkapp-a1b2c3-highres.webp' => 'highres-bytes',
        ]);
        $projectDir = sys_get_temp_dir() . '/gallery_import_project_' . bin2hex(random_bytes(4));

        $media = $this->importSingleMedia([
            'title' => 'Nordkapp',
            'slug' => 'nordkapp',
            'filename' => 'medias/gallery/films/nordkapp-a1b2c3.webp',
            'updatedAt' => '2026-08-01T10:00:00+00:00',
            'file' => 'files/ab12_nordkapp-a1b2c3.webp',
            'thumbFile' => 'files/ab12_nordkapp-a1b2c3-thumb.webp',
            'highresFile' => 'files/ab12_nordkapp-a1b2c3-highres.webp',
        ], $filesDir, $projectDir);

        $this->assertSame('medias/gallery/films/nordkapp-a1b2c3.webp', $media->getFilename());

        $base = $projectDir . '/public/medias/gallery/films/nordkapp-a1b2c3';
        $this->assertSame('stored-bytes', file_get_contents($base . '.webp'));
        $this->assertSame('thumb-bytes', file_get_contents($base . '-thumb.webp'));
        $this->assertSame('highres-bytes', file_get_contents($base . '-highres.webp'));

        // Written by the restoration alone, Vich never having seen a file to write them from
        $this->assertSame(\strlen('stored-bytes'), $media->getSize());
        $this->assertNotNull($media->getMimeType());
        // Dated by when it was last touched on the site it comes from, not by the import
        $this->assertSame('2026-08-01', $media->getUpdatedAt()?->format('Y-m-d'));

        $this->removeDir($projectDir);
        $this->removeDir($filesDir);
    }

    // The site's own video keeps its name too, an url that is shared and cached being no less an url for pointing at a video
    public function testImportKeepsTheExportedVideoName(): void
    {
        $filesDir = $this->createFilesDir([
            'ab12_nordkapp-a1b2c3.webp' => 'still-bytes',
            'ab12_nordkapp-d4e5f6.mp4' => 'video-bytes',
        ]);
        $projectDir = sys_get_temp_dir() . '/gallery_import_project_' . bin2hex(random_bytes(4));

        $media = $this->importSingleMedia([
            'title' => 'Nordkapp',
            'slug' => 'nordkapp',
            'filename' => 'medias/gallery/films/nordkapp-a1b2c3.webp',
            'file' => 'files/ab12_nordkapp-a1b2c3.webp',
            'videoFilename' => 'medias/gallery/films/nordkapp-d4e5f6.mp4',
            'videoFile' => 'files/ab12_nordkapp-d4e5f6.mp4',
        ], $filesDir, $projectDir);

        $this->assertSame('medias/gallery/films/nordkapp-d4e5f6.mp4', $media->getVideoFilename());
        $this->assertSame('video-bytes', file_get_contents($projectDir . '/public/medias/gallery/films/nordkapp-d4e5f6.mp4'));
        // Derived from the name it was given, exactly as it would be from one Vich gave it
        $this->assertSame(GalleryMedia::MEDIA_TYPE_VIDEO, $media->getMediaType());
        $this->assertSame(\strlen('video-bytes'), $media->getVideoSize());

        $this->removeDir($projectDir);
        $this->removeDir($filesDir);
    }

    // What comes out of an archive is a path an admin uploaded: a name climbing out of the bundle's own media directory is refused, and the file named by Vich instead rather than laid wherever the process can write
    public function testImportRefusesAFilenameLeavingTheBundlesMediaDirectory(): void
    {
        $filesDir = $this->createFilesDir(['ab12_p1.webp' => 'stored-bytes']);
        $projectDir = sys_get_temp_dir() . '/gallery_import_project_' . bin2hex(random_bytes(4));

        $media = $this->importSingleMedia([
            'title' => 'Media 1',
            'slug' => 'media-1',
            'filename' => 'medias/gallery/../../../escaped.webp',
            'file' => 'files/ab12_p1.webp',
        ], $filesDir, $projectDir);

        // The name the stand-in for Vich gave it, the archive's own having been refused
        $this->assertSame('uploads/p1.webp', $media->getFilename());
        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/escaped.webp');

        $this->removeDir($projectDir);
        $this->removeDir($filesDir);
    }

    // A name is only honoured under this bundle's own media directory, an absolute path naming a file anywhere the process can write
    public function testImportRefusesAFilenameOutsideTheBundlesMediaDirectory(): void
    {
        $filesDir = $this->createFilesDir(['ab12_p1.webp' => 'stored-bytes']);
        $projectDir = sys_get_temp_dir() . '/gallery_import_project_' . bin2hex(random_bytes(4));

        $media = $this->importSingleMedia([
            'title' => 'Media 1',
            'slug' => 'media-1',
            'filename' => '/etc/escaped.webp',
            'file' => 'files/ab12_p1.webp',
        ], $filesDir, $projectDir);

        $this->assertSame('uploads/p1.webp', $media->getFilename());

        $this->removeDir($projectDir);
        $this->removeDir($filesDir);
    }

    // A null byte would have PHP stop reading the name where C does, so a name carrying one is refused whole
    public function testImportRefusesAFilenameCarryingANullByte(): void
    {
        $filesDir = $this->createFilesDir(['ab12_p1.webp' => 'stored-bytes']);
        $projectDir = sys_get_temp_dir() . '/gallery_import_project_' . bin2hex(random_bytes(4));

        $media = $this->importSingleMedia([
            'title' => 'Media 1',
            'slug' => 'media-1',
            'filename' => "medias/gallery/films/escaped.webp\0.txt",
            'file' => 'files/ab12_p1.webp',
        ], $filesDir, $projectDir);

        $this->assertSame('uploads/p1.webp', $media->getFilename());

        $this->removeDir($projectDir);
        $this->removeDir($filesDir);
    }

    // An archive from before the stored files were converted holds a jpeg, where the name it would be restored under says webp - the recomputed file stands rather than being overwritten by bytes it doesn't describe
    public function testImportKeepsTheRecomputedFileWhenTheArchivedOneCarriesAnotherFormat(): void
    {
        $filesDir = $this->createFilesDir(['ab12_p1.jpg' => 'legacy-jpeg-bytes']);
        $projectDir = sys_get_temp_dir() . '/gallery_import_project_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.webp', 'converted-webp');

        $this->importSingleMedia([
            'title' => 'Media 1',
            'slug' => 'media-1',
            'file' => 'files/ab12_p1.jpg',
        ], $filesDir, $projectDir);

        $this->assertSame('converted-webp', file_get_contents($projectDir . '/public/uploads/p1.webp'));

        $this->removeDir($projectDir);
        $this->removeDir($filesDir);
    }

    // Imports one media and hands it back, the flush standing in for Vich naming the stored file - which is what every archived file is then restored under
    private function importSingleMedia(array $mediaData, string $filesDir, string $projectDir): GalleryMedia
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->method('flush')->willReturnCallback(static function () use (&$persisted): void {
            foreach ($persisted as $entity) {
                if ($entity instanceof GalleryMedia && null === $entity->getFilename()) {
                    $entity->setFilename('uploads/p1.webp');
                }
            }
        });

        $this->createProvider($em, null, $projectDir)->import([[
            'slug' => 'voyages',
            'title' => 'Voyages',
            'medias' => [$mediaData],
        ]], $filesDir);

        return array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryMedia))[0];
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

        $this->createProvider($em, null, $projectDir)->import($exported['items'], $filesDir);

        $imported = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryMedia))[0];
        $this->assertSame($exportedMedia->getExternalUrl(), $imported->getExternalUrl());
        $this->assertSame(VideoPlatform::Youtube->value, $imported->getMediaType());
        // The whole point of the round-trip: the media lands at the very url it was exported from, images included
        $this->assertSame($exportedMedia->getFilename(), $imported->getFilename());

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
    public function testImportCarriesTheSummaryAndToleratesItsAbsence(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = $this->createProvider($em);

        $provider->import([
            ['slug' => 'paysages', 'title' => 'Paysages', 'summarySocialNetwork' => '<div>Nos paysages</div>'],
            ['slug' => 'portraits', 'title' => 'Portraits'],
        ]);

        $categories = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryCategory));
        $this->assertSame(['<div>Nos paysages</div>', null], array_map(static fn (GalleryCategory $c): ?string => $c->getSummarySocialNetwork(), $categories));
    }

    // An archive exported before the rename carries "description": read as the lead-in it is, rather than importing a category stripped of it
    public function testImportReadsTheLeadInOfAnArchivePredatingTheRename(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = $this->createProvider($em);

        $provider->import([
            ['slug' => 'paysages', 'title' => 'Paysages', 'description' => '<div>Nos paysages</div>'],
        ]);

        $categories = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryCategory));
        $this->assertSame('<div>Nos paysages</div>', $categories[0]->getSummarySocialNetwork());
    }
}
