<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Command;

use c975L\GalleryBundle\Command\GalleryLegacyImportCommand;
use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;

// Each test runs in a throwaway project directory with real empty files, so Finder reads are exercised
class GalleryLegacyImportCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/gallery-legacy-import-test-' . uniqid();
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function writePhotos(string $relativeDir, array $filenames): void
    {
        $dir = $this->projectDir . '/' . $relativeDir;
        mkdir($dir, 0777, true);
        foreach ($filenames as $filename) {
            file_put_contents($dir . '/' . $filename, 'fake-jpg-bytes');
        }
    }

    private function createTester(
        EntityManagerInterface $em,
        ?GalleryRepository $galleryRepository = null,
        ?GalleryCategoryRepository $categoryRepository = null,
        ?TranslatorInterface $translator = null,
    ): CommandTester {
        $stubTranslator = $this->createStub(TranslatorInterface::class);
        $stubTranslator->method('trans')->willReturnArgument(0);

        return new CommandTester(new GalleryLegacyImportCommand(
            $em,
            $galleryRepository ?? $this->createStub(GalleryRepository::class),
            $categoryRepository ?? $this->createStub(GalleryCategoryRepository::class),
            new AsciiSlugger(),
            $translator ?? $stubTranslator,
            $this->projectDir,
        ));
    }

    public function testExecuteFailsWhenSourceDirDoesNotExist(): void
    {
        $tester = $this->createTester($this->createStub(EntityManagerInterface::class));

        $statusCode = $tester->execute(['source-dir' => 'does-not-exist']);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('Directory not found', $tester->getDisplay());
    }

    // --flat: every photo goes into the gallery's single "Non classé" category (findOrCreateUncategorized)
    public function testExecuteFlatImportsEveryPhotoIntoTheUncategorizedCategory(): void
    {
        $this->writePhotos('photos', ['a.jpg', 'b.jpg']);
        $gallery = (new Gallery())->setSlug('main');
        $uncategorized = (new GalleryCategory())->setSlug('non-classe')->setUncategorized(true);

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn($gallery);
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('findOrCreateUncategorized')->with($gallery)->willReturn($uncategorized);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->expects($this->once())->method('flush');

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository);
        $statusCode = $tester->execute(['source-dir' => 'photos', '--flat' => true]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('2 photos created and flushed. 0 skipped.', $tester->getDisplay());
        $this->assertCount(2, $persisted);
        $this->assertContainsOnlyInstancesOf(GalleryPhoto::class, $persisted);
    }

    // --flat --category: a flat source landing in a named category rather than in "Non classé" - what a
    // site importing several distinct flat sources (photos, then video stills) needs to keep them apart
    public function testExecuteFlatImportsIntoTheNamedCategoryWhenOneIsGiven(): void
    {
        $this->writePhotos('photos', ['a.jpg']);
        $gallery = (new Gallery())->setSlug('main');

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn($gallery);
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->never())->method('findOrCreateUncategorized');
        $categoryRepository->expects($this->once())->method('findOneBySlug')->with($gallery, 'tiktoks')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->expects($this->atLeastOnce())->method('flush');

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository);
        $statusCode = $tester->execute(['source-dir' => 'photos', '--flat' => true, '--category' => 'TikToks']);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('1 photos created and flushed', $tester->getDisplay());

        $categories = array_filter($persisted, static fn (object $entity): bool => $entity instanceof GalleryCategory);
        $this->assertCount(1, $categories);
        $this->assertSame('tiktoks', reset($categories)->getSlug());
    }

    // No subdirectories layout: each subdirectory becomes/matches one GalleryCategory by slug
    public function testExecuteImportsOneCategoryPerSubdirectory(): void
    {
        $this->writePhotos('photos/paysages', ['a.jpg']);
        $this->writePhotos('photos/portraits', ['b.jpg', 'c.jpg']);
        $gallery = (new Gallery())->setSlug('main');

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn(null);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->method('flush');

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository);
        $statusCode = $tester->execute(['source-dir' => 'photos']);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('3 photos created and flushed. 0 skipped.', $tester->getDisplay());

        $categories = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryCategory));
        $this->assertCount(2, $categories);
        $this->assertSame(['paysages', 'portraits'], array_map(static fn (GalleryCategory $c): string => $c->getSlug(), $categories));

        $photos = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryPhoto));
        $this->assertCount(3, $photos);
    }

    // A category that already has photos is left untouched, so a re-run after fixing an error elsewhere doesn't duplicate it
    public function testExecuteSkipsACategoryThatAlreadyHasPhotos(): void
    {
        $this->writePhotos('photos/paysages', ['a.jpg']);
        $gallery = (new Gallery())->setSlug('main');
        $category = (new GalleryCategory())->setSlug('paysages');
        $category->addPhoto(new GalleryPhoto());

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository);
        $statusCode = $tester->execute(['source-dir' => 'photos']);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('0 photos created and flushed. 1 skipped.', $tester->getDisplay());
        $this->assertStringContainsString('already has photos', $tester->getDisplay());
    }

    // The Gallery for --gallery-slug doesn't exist yet - created on the fly, and becomes the site's default since none exists
    public function testExecuteCreatesTheGalleryWhenTheSlugDoesNotExistYet(): void
    {
        $this->writePhotos('photos/paysages', ['a.jpg']);

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn(null);
        $galleryRepository->method('findDefault')->willReturn(null);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn(null);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->method('flush');

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository);
        $tester->execute(['source-dir' => 'photos', '--gallery-slug' => 'new-gallery']);

        $galleries = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof Gallery));
        $this->assertCount(1, $galleries);
        $this->assertSame('new-gallery', $galleries[0]->getSlug());
        $this->assertTrue($galleries[0]->isDefault());
    }

    // --dry-run never touches persist()/flush(), and doesn't call the side-effecting findOrCreateUncategorized() either
    public function testDryRunNeverPersistsOrFlushes(): void
    {
        $this->writePhotos('photos', ['a.jpg']);
        $gallery = (new Gallery())->setSlug('main');

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn($gallery);
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->never())->method('findOrCreateUncategorized');
        $categoryRepository->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository);
        $statusCode = $tester->execute(['source-dir' => 'photos', '--flat' => true, '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('1 photos would be created. 0 skipped.', $tester->getDisplay());
    }

    public function testExecuteAppliesCreditsAndRightsReservedToEveryPhoto(): void
    {
        $this->writePhotos('photos', ['a.jpg']);
        $gallery = (new Gallery())->setSlug('main');
        $uncategorized = (new GalleryCategory())->setSlug('non-classe')->setUncategorized(true);

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOrCreateUncategorized')->willReturn($uncategorized);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository);
        $tester->execute([
            'source-dir' => 'photos',
            '--flat' => true,
            '--credits' => 'Studio 975L',
            '--rights-reserved' => true,
        ]);

        $photos = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryPhoto));
        $this->assertSame('Studio 975L', $photos[0]->getCredits());
        $this->assertTrue($photos[0]->isRightsReserved());
    }

    // --credits=0 is a legitimate value (e.g. an internal photographer code) and must not be treated as "no credits"
    public function testExecuteKeepsTheStringZeroAsCredits(): void
    {
        $this->writePhotos('photos', ['a.jpg']);
        $gallery = (new Gallery())->setSlug('main');
        $uncategorized = (new GalleryCategory())->setSlug('non-classe')->setUncategorized(true);

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOrCreateUncategorized')->willReturn($uncategorized);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository);
        $tester->execute(['source-dir' => 'photos', '--flat' => true, '--credits' => '0']);

        $photos = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryPhoto));
        $this->assertSame('0', $photos[0]->getCredits());
    }

    // The imported photo's file must never be flagged for deletion - the source is the site's own live legacy photo, not a throwaway extraction
    public function testExecuteNeverFlagsTheImportedFileForDeletion(): void
    {
        $this->writePhotos('photos', ['a.jpg']);
        $gallery = (new Gallery())->setSlug('main');
        $uncategorized = (new GalleryCategory())->setSlug('non-classe')->setUncategorized(true);

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOrCreateUncategorized')->willReturn($uncategorized);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository);
        $tester->execute(['source-dir' => 'photos', '--flat' => true]);

        $photos = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryPhoto));
        $file = $photos[0]->getFile();
        $this->assertInstanceOf(ReplacingFile::class, $file);
        $this->assertFalse($file->isRemoveReplacedFile());
        $this->assertFalse($file->isRemoveReplacedFileOnError());
    }

    // Camera-exported files are commonly uppercase (.JPG/.JPEG) - matching must not be case-sensitive
    public function testExecuteMatchesUppercaseJpgExtensions(): void
    {
        $this->writePhotos('photos/paysages', ['IMG_0001.JPG', 'b.jpeg']);
        $gallery = (new Gallery())->setSlug('main');

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository);
        $tester->execute(['source-dir' => 'photos']);

        $this->assertStringContainsString('2 photos created and flushed. 0 skipped.', $tester->getDisplay());
    }

    // Category slugs must be normalized like the admin CRUD does, not used verbatim from the directory name
    public function testExecuteSlugifiesCategoryDirectoryNames(): void
    {
        $this->writePhotos('photos/Étoiles & Vacances', ['a.jpg']);
        $gallery = (new Gallery())->setSlug('main');

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn(null);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository);
        $tester->execute(['source-dir' => 'photos']);

        $categories = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryCategory));
        $this->assertCount(1, $categories);
        $this->assertSame('etoiles-vacances', $categories[0]->getSlug());
    }

    // The dry-run preview must show the same "Uncategorized" title the live path would actually create, via the translator - not a hardcoded French string
    public function testDryRunUncategorizedTitleUsesTheTranslator(): void
    {
        $this->writePhotos('photos', ['a.jpg']);
        $gallery = (new Gallery())->setSlug('main');

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOneBySlug')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBy')->willReturn(null);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with('label.gallery_uncategorized', [], 'gallery')
            ->willReturn('Uncategorized');

        $em = $this->createStub(EntityManagerInterface::class);

        $tester = $this->createTester($em, $galleryRepository, $categoryRepository, $translator);
        $statusCode = $tester->execute(['source-dir' => 'photos', '--flat' => true, '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $statusCode);
    }
}
