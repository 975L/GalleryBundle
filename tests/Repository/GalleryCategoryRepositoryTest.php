<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Repository;

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
        $existing = new GalleryCategory()->setUncategorized(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $repository = new GalleryCategoryRepositoryFindOneByFixture($existing, $entityManager, $this->createTranslator());

        $this->assertSame($existing, $repository->findOrCreateUncategorized());
    }

    // The catch-all is where an upload lands when no real category is picked, so it can never be a category the site does not show - flagged some other way (a fixture, an import, a hand-written query), it is lifted back out on the way through
    public function testFindOrCreateUncategorizedLiftsTheExistingOneOutOfTheTrash(): void
    {
        $existing = new GalleryCategory()->setUncategorized(true);
        $existing->setIsDeleted(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $repository = new GalleryCategoryRepositoryFindOneByFixture($existing, $entityManager, $this->createTranslator());

        $this->assertSame($existing, $repository->findOrCreateUncategorized());
        $this->assertFalse($existing->isDeleted());
    }

    // Catch-all category a GalleryMedia falls back to when imported without a real one - created lazily, translated at creation time only
    public function testFindOrCreateUncategorizedCreatesAndPersistsATranslatedCategoryWhenNoneExists(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = null;
        $entityManager->expects($this->once())->method('persist')->with($this->callback(function (GalleryCategory $category) use (&$persisted) {
            $persisted = $category;

            return true;
        }));
        $entityManager->expects($this->once())->method('flush');

        $repository = new GalleryCategoryRepositoryFindOneByFixture(null, $entityManager, $this->createTranslator());
        $category = $repository->findOrCreateUncategorized();

        $this->assertSame($persisted, $category);
        $this->assertSame('non-classe', $category->getSlug());
        $this->assertSame('label.gallery_uncategorized', $category->getTitle());
        $this->assertTrue($category->isUncategorized());
    }

    // Nobody creates the gallery of the last additions: it is written the first time the galleries are listed, and read back every time after
    public function testFindOrCreateAutomaticCreatesAndPersistsATranslatedCategoryWhenNoneExists(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = null;
        $entityManager->expects($this->once())->method('persist')->with($this->callback(function (GalleryCategory $category) use (&$persisted) {
            $persisted = $category;

            return true;
        }));
        $entityManager->expects($this->once())->method('flush');

        $category = new GalleryCategoryRepositoryFindOneByFixture(null, $entityManager, $this->createTranslator())->findOrCreateAutomatic();

        $this->assertSame($persisted, $category);
        $this->assertSame('latest', $category->getSlug());
        $this->assertSame('label.gallery_latest_title', $category->getTitle());
        $this->assertTrue($category->isAutomatic());
    }

    // Moving it to the trash is the only way an admin has of being rid of it, so it is left exactly where it was put - unlike the catch-all above, which an upload has to be able to land on
    public function testFindOrCreateAutomaticLeavesATrashedOneInTheTrash(): void
    {
        $existing = new GalleryCategory()->setAutomatic(true);
        $existing->setIsDeleted(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $repository = new GalleryCategoryRepositoryFindOneByFixture($existing, $entityManager, $this->createTranslator());

        $this->assertSame($existing, $repository->findOrCreateAutomatic());
        $this->assertTrue($existing->isDeleted());
    }

    // The slug is a constant on a column held unique: a site that already named a gallery "latest" would meet a UniqueConstraintViolationException on every page listing its galleries, so the taken one is suffixed until it is free
    public function testFindOrCreateAutomaticSuffixesASlugAlreadyTaken(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $repository = new GalleryCategoryRepositoryFindOneByFixture(null, $entityManager, $this->createTranslator(), ['latest', 'latest-2']);

        $this->assertSame('latest-3', $repository->findOrCreateAutomatic()->getSlug());
    }

    // Same slug, same constraint, same suffixing - and the catch-all is the one an upload lands on, so failing to create it would leave the medias nowhere to go
    public function testFindOrCreateUncategorizedSuffixesASlugAlreadyTaken(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $repository = new GalleryCategoryRepositoryFindOneByFixture(null, $entityManager, $this->createTranslator(), ['non-classe']);

        $this->assertSame('non-classe-2', $repository->findOrCreateUncategorized()->getSlug());
    }
}

// findOneBy() is resolved by Doctrine's real EntityRepository internals - overriding it directly (a real, non-magic, declared method here) plus getEntityManager() instead, parent constructor never invoked, mirrors ConfigRepositoryFindOneBySlugFixture in ConfigBundle/SiteBundle. GalleryCategoryRepository's own $translator (used by the inherited, un-overridden findOrCreateUncategorized()) is private to that class and typed non-nullable - reflection initializes it directly since the skipped constructor never gets the chance to
class GalleryCategoryRepositoryFindOneByFixture extends GalleryCategoryRepository
{
    public function __construct(
        private readonly ?GalleryCategory $existingCategory,
        private readonly EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        /** @var list<string> */
        private readonly array $takenSlugs = [],
    ) {
        new \ReflectionProperty(GalleryCategoryRepository::class, 'translator')->setValue($this, $translator);
    }

    // The slug lookup freeSlug() makes is answered from the taken list, everything else - the automatic and uncategorized flags - by the single category the fixture was given
    #[\Override]
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        if (isset($criteria['slug'])) {
            return \in_array($criteria['slug'], $this->takenSlugs, true) ? new GalleryCategory()->setSlug($criteria['slug']) : null;
        }

        return $this->existingCategory;
    }

    #[\Override]
    protected function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
