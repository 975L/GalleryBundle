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
use c975L\GalleryBundle\Repository\GalleryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class GalleryRepositoryTest extends TestCase
{
    public function testFindOrCreateDefaultReturnsTheExistingDefaultGalleryWithoutPersistingAnything(): void
    {
        $existing = (new Gallery())->setSlug('main')->setDefault(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $repository = new GalleryRepositoryFindDefaultFixture($existing, $entityManager);

        $this->assertSame($existing, $repository->findOrCreateDefault());
    }

    // No Gallery picker is exposed yet in the Management CRUD - every category/photo created there is attached to this one, lazily created on first use
    public function testFindOrCreateDefaultCreatesAndPersistsANewDefaultGalleryWhenNoneExists(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = null;
        $entityManager->expects($this->once())->method('persist')->with($this->callback(function (Gallery $gallery) use (&$persisted) {
            $persisted = $gallery;

            return true;
        }));
        $entityManager->expects($this->once())->method('flush');

        $repository = new GalleryRepositoryFindDefaultFixture(null, $entityManager);
        $gallery = $repository->findOrCreateDefault();

        $this->assertSame($persisted, $gallery);
        $this->assertSame('main', $gallery->getSlug());
        $this->assertSame('Galerie', $gallery->getTitle());
        $this->assertTrue($gallery->isDefault());
    }
}

// findDefault()/getEntityManager() have no natural key to mock via createStub/createMock on the ServiceEntityRepository (real Doctrine internals) - overriding the bundle's own declared methods instead, parent constructor never invoked, mirrors ConfigRepositoryFindOneBySlugFixture in ConfigBundle/SiteBundle
class GalleryRepositoryFindDefaultFixture extends GalleryRepository
{
    public function __construct(
        private readonly ?Gallery $existingDefault,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findDefault(): ?Gallery
    {
        return $this->existingDefault;
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
