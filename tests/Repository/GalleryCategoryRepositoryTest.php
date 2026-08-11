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
}

// findOneBy() is resolved by Doctrine's real EntityRepository internals - overriding it directly (a real, non-magic, declared method here) plus getEntityManager() instead, parent constructor never invoked, mirrors ConfigRepositoryFindOneBySlugFixture in ConfigBundle/SiteBundle. GalleryCategoryRepository's own $translator (used by the inherited, un-overridden findOrCreateUncategorized()) is private to that class and typed non-nullable - reflection initializes it directly since the skipped constructor never gets the chance to
class GalleryCategoryRepositoryFindOneByFixture extends GalleryCategoryRepository
{
    public function __construct(
        private readonly ?GalleryCategory $existingUncategorized,
        private readonly EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ) {
        new \ReflectionProperty(GalleryCategoryRepository::class, 'translator')->setValue($this, $translator);
    }

    #[\Override]
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->existingUncategorized;
    }

    #[\Override]
    protected function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
