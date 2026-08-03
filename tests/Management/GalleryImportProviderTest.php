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
use c975L\GalleryBundle\Management\GalleryImportProvider;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class GalleryImportProviderTest extends TestCase
{
    private function createGalleryRepository(?Gallery $existingGallery = null, ?Gallery $existingDefault = null): GalleryRepository
    {
        $repository = $this->createStub(GalleryRepository::class);
        $repository->method('findOneBySlug')->willReturn($existingGallery);
        $repository->method('findDefault')->willReturn($existingDefault);

        return $repository;
    }

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

    public function testSupportsImportOnlyMatchesGalleryCategoryKind(): void
    {
        $provider = new GalleryImportProvider(
            $this->createStub(EntityManagerInterface::class),
            $this->createGalleryRepository(),
            $this->createCategoryRepository(),
        );

        $this->assertTrue($provider->supportsImport('gallery_category'));
        $this->assertFalse($provider->supportsImport('site_page'));
    }

    public function testImportCreatesTheGalleryAndCategoryWhenBothAreMissing(): void
    {
        $filesDir = $this->createFilesDir(['p1.jpg' => 'bytes-1', 'p2.jpg' => 'bytes-2']);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = new GalleryImportProvider($em, $this->createGalleryRepository(), $this->createCategoryRepository());

        $result = $provider->import([[
            'gallerySlug' => 'main',
            'galleryTitle' => 'Galerie',
            'galleryPosition' => 0,
            'galleryDefault' => true,
            'slug' => 'voyages',
            'title' => 'Voyages',
            'position' => 0,
            'uncategorized' => false,
            'coverPhotoIndex' => 1,
            'photos' => [
                ['alt' => 'Photo 1', 'position' => 0, 'originalFilename' => 'p1.jpg', 'file' => 'files/p1.jpg'],
                ['alt' => 'Photo 2', 'position' => 1, 'originalFilename' => 'p2.jpg', 'file' => 'files/p2.jpg'],
            ],
        ]], $filesDir);

        $this->assertSame(['created' => 1, 'updated' => 0], $result);

        $gallery = null;
        $category = null;
        $photos = [];
        foreach ($persisted as $entity) {
            if ($entity instanceof Gallery) {
                $gallery = $entity;
            } elseif ($entity instanceof GalleryCategory) {
                $category = $entity;
            } elseif ($entity instanceof GalleryPhoto) {
                $photos[] = $entity;
            }
        }

        $this->assertInstanceOf(Gallery::class, $gallery);
        $this->assertSame('main', $gallery->getSlug());
        $this->assertInstanceOf(GalleryCategory::class, $category);
        $this->assertSame('voyages', $category->getSlug());
        $this->assertSame($gallery, $category->getGallery());
        $this->assertCount(2, $photos);
        $this->assertSame($photos[1], $category->getCoverPhoto());
        $this->assertSame($filesDir . '/files/p2.jpg', $photos[1]->getFile()->getPathname());

        unlink($filesDir . '/files/p1.jpg');
        unlink($filesDir . '/files/p2.jpg');
        rmdir($filesDir . '/files');
        rmdir($filesDir);
    }

    public function testImportReplacesAnExistingCategorysPhotos(): void
    {
        $filesDir = $this->createFilesDir(['new.jpg' => 'new-bytes']);

        $gallery = (new Gallery())->setSlug('main')->setTitle('Galerie');
        $oldPhoto = (new GalleryPhoto())->setAlt('Old');
        $existingCategory = (new GalleryCategory())->setGallery($gallery)->setSlug('voyages')->setTitle('Voyages');
        $existingCategory->addPhoto($oldPhoto);

        $em = $this->createStub(EntityManagerInterface::class);

        $provider = new GalleryImportProvider(
            $em,
            $this->createGalleryRepository($gallery),
            $this->createCategoryRepository($existingCategory),
        );

        $result = $provider->import([[
            'gallerySlug' => 'main',
            'slug' => 'voyages',
            'title' => 'Voyages',
            'photos' => [
                ['alt' => 'New', 'position' => 0, 'originalFilename' => 'new.jpg', 'file' => 'files/new.jpg'],
            ],
        ]], $filesDir);

        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertCount(1, $existingCategory->getPhotos());
        $this->assertSame('New', $existingCategory->getPhotos()->first()->getAlt());
        $this->assertNull($oldPhoto->getCategory());

        unlink($filesDir . '/files/new.jpg');
        rmdir($filesDir . '/files');
        rmdir($filesDir);
    }

    // Two items sharing a gallerySlug that doesn't exist yet must reuse the same new Gallery instead of each creating one - findOneBySlug() can't see an unflushed persist, so the provider itself must not rely on it a second time within the same import
    public function testImportReusesTheSameNewGalleryAcrossItemsSharingItsSlug(): void
    {
        $filesDir = $this->createFilesDir(['a.jpg' => 'bytes-a', 'b.jpg' => 'bytes-b']);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = new GalleryImportProvider($em, $this->createGalleryRepository(), $this->createCategoryRepository());

        $result = $provider->import([
            [
                'gallerySlug' => 'main',
                'galleryTitle' => 'Galerie',
                'slug' => 'paysages',
                'title' => 'Paysages',
                'photos' => [['alt' => 'A', 'position' => 0, 'originalFilename' => 'a.jpg', 'file' => 'files/a.jpg']],
            ],
            [
                'gallerySlug' => 'main',
                'galleryTitle' => 'Galerie',
                'slug' => 'portraits',
                'title' => 'Portraits',
                'photos' => [['alt' => 'B', 'position' => 0, 'originalFilename' => 'b.jpg', 'file' => 'files/b.jpg']],
            ],
        ], $filesDir);

        $this->assertSame(['created' => 2, 'updated' => 0], $result);

        $galleries = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof Gallery));
        $this->assertCount(1, $galleries);

        $categories = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof GalleryCategory));
        $this->assertCount(2, $categories);
        $this->assertSame($galleries[0], $categories[0]->getGallery());
        $this->assertSame($galleries[0], $categories[1]->getGallery());

        unlink($filesDir . '/files/a.jpg');
        unlink($filesDir . '/files/b.jpg');
        rmdir($filesDir . '/files');
        rmdir($filesDir);
    }

    // --- default flag --------------------------------------------------------------------------------------

    public function testImportGrantsTheDefaultFlagWhenNoLocalDefaultGalleryExists(): void
    {
        $galleries = $this->importAndCollectGalleries($this->createGalleryRepository(), [
            ['gallerySlug' => 'main', 'galleryDefault' => true, 'slug' => 'voyages', 'title' => 'Voyages'],
        ]);

        $this->assertCount(1, $galleries);
        $this->assertTrue($galleries[0]->isDefault());
    }

    // Two default galleries would make findDefault() return an arbitrary one, hiding half the categories from the front-office
    public function testImportDoesNotCreateASecondDefaultGallery(): void
    {
        $localDefault = (new Gallery())->setSlug('galerie')->setDefault(true);

        $galleries = $this->importAndCollectGalleries($this->createGalleryRepository(null, $localDefault), [
            ['gallerySlug' => 'main', 'galleryDefault' => true, 'slug' => 'voyages', 'title' => 'Voyages'],
        ]);

        $this->assertCount(1, $galleries);
        $this->assertFalse($galleries[0]->isDefault());
        $this->assertTrue($localDefault->isDefault());
    }

    // findDefault() can't see an unflushed persist, so the second item must not grant the flag a second time either
    public function testImportGrantsTheDefaultFlagOnlyOnceWithinTheSameBatch(): void
    {
        $galleries = $this->importAndCollectGalleries($this->createGalleryRepository(), [
            ['gallerySlug' => 'main', 'galleryDefault' => true, 'slug' => 'voyages', 'title' => 'Voyages'],
            ['gallerySlug' => 'archives', 'galleryDefault' => true, 'slug' => 'anciennes', 'title' => 'Anciennes'],
        ]);

        $this->assertCount(2, $galleries);
        $this->assertTrue($galleries[0]->isDefault());
        $this->assertFalse($galleries[1]->isDefault());
    }

    /** @return Gallery[] */
    private function importAndCollectGalleries(GalleryRepository $galleryRepository, array $items): array
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        (new GalleryImportProvider($em, $galleryRepository, $this->createCategoryRepository()))->import($items);

        return array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof Gallery));
    }
}
