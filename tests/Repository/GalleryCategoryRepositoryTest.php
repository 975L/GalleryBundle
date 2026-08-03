<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Repository;

use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class GalleryCategoryRepositoryTest extends TestCase
{
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    public function testFindOrCreateUncategorizedReturnsTheExistingOneWithoutPersistingAnything(): void
    {
        $gallery = new Gallery();
        $existing = (new GalleryCategory())->setGallery($gallery)->setUncategorized(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $repository = new GalleryCategoryRepositoryFindOneByFixture($existing, $entityManager, $this->createTranslator());

        $this->assertSame($existing, $repository->findOrCreateUncategorized($gallery));
    }

    // Catch-all category a GalleryPhoto falls back to when uploaded without picking a real one - created lazily, translated at creation time only
    public function testFindOrCreateUncategorizedCreatesAndPersistsATranslatedCategoryWhenNoneExists(): void
    {
        $gallery = new Gallery();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = null;
        $entityManager->expects($this->once())->method('persist')->with($this->callback(function (GalleryCategory $category) use (&$persisted) {
            $persisted = $category;

            return true;
        }));
        $entityManager->expects($this->once())->method('flush');

        $repository = new GalleryCategoryRepositoryFindOneByFixture(null, $entityManager, $this->createTranslator());
        $category = $repository->findOrCreateUncategorized($gallery);

        $this->assertSame($persisted, $category);
        $this->assertSame($gallery, $category->getGallery());
        $this->assertSame('non-classe', $category->getSlug());
        $this->assertSame('label.gallery_uncategorized', $category->getTitle());
        $this->assertTrue($category->isUncategorized());
    }

    // --- makeSlugUnique ------------------------------------------------------------------------------------

    public function testMakeSlugUniqueKeepsTheSlugWhenNoOtherCategoryUsesIt(): void
    {
        $category = (new GalleryCategory())->setGallery(new Gallery());
        $repository = new GalleryCategoryRepositoryBySlugFixture([]);

        $this->assertSame('ete-2024', $repository->makeSlugUnique($category, 'ete-2024'));
    }

    // Two different titles can slugify identically ("Été 2024" and "Ete 2024")
    public function testMakeSlugUniqueSuffixesTheSlugUntilItIsFree(): void
    {
        $gallery = new Gallery();
        $category = (new GalleryCategory())->setGallery($gallery);
        $repository = new GalleryCategoryRepositoryBySlugFixture([
            'ete-2024' => (new GalleryCategory())->setGallery($gallery),
            'ete-2024-2' => (new GalleryCategory())->setGallery($gallery),
        ]);

        $this->assertSame('ete-2024-3', $repository->makeSlugUnique($category, 'ete-2024'));
    }

    // Editing a category without renaming it must not keep suffixing its own slug
    public function testMakeSlugUniqueIgnoresTheCategoryItself(): void
    {
        $gallery = new Gallery();
        $category = (new GalleryCategory())->setGallery($gallery);
        $repository = new GalleryCategoryRepositoryBySlugFixture(['ete-2024' => $category]);

        $this->assertSame('ete-2024', $repository->makeSlugUnique($category, 'ete-2024'));
    }

    public function testMakeSlugUniqueLeavesTheSlugAloneWhenTheCategoryHasNoGalleryYet(): void
    {
        $repository = new GalleryCategoryRepositoryBySlugFixture([]);

        $this->assertSame('ete-2024', $repository->makeSlugUnique(new GalleryCategory(), 'ete-2024'));
    }
}

// findOneBy() is resolved by Doctrine's real EntityRepository internals - overriding it directly (a real, non-magic, declared method here) plus getEntityManager() instead, parent constructor never invoked, mirrors ConfigRepositoryFindOneBySlugFixture in ConfigBundle/SiteBundle. GalleryCategoryRepository's own $translator (used by the inherited, un-overridden findOrCreateUncategorized()) is private to that class and typed non-nullable - reflection initializes it directly since the skipped constructor never gets the chance to
class GalleryCategoryRepositoryFindOneByFixture extends GalleryCategoryRepository
{
    public function __construct(
        private readonly ?GalleryCategory $existingUncategorized,
        private readonly EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ) {
        (new \ReflectionProperty(GalleryCategoryRepository::class, 'translator'))->setValue($this, $translator);
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->existingUncategorized;
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}

// Same skipped-constructor technique, but answering findOneBy() per requested slug so makeSlugUnique() can walk the collisions
class GalleryCategoryRepositoryBySlugFixture extends GalleryCategoryRepository
{
    /** @param array<string, GalleryCategory> $categoriesBySlug */
    public function __construct(private readonly array $categoriesBySlug)
    {
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->categoriesBySlug[$criteria['slug']] ?? null;
    }
}
