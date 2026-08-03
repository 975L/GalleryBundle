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
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Repository\GalleryPhotoRepository;
use PHPUnit\Framework\TestCase;

class GalleryPhotoRepositoryTest extends TestCase
{
    private function createRepository(array $photosInCategory): GalleryPhotoRepository
    {
        return new GalleryPhotoRepositoryFindAdjacentFixture($photosInCategory);
    }

    public function testFindPreviousAndNextReturnsTheNeighboursInPosition(): void
    {
        $category = new GalleryCategory();
        $photos = [(new GalleryPhoto())->setPosition(0), (new GalleryPhoto())->setPosition(1), (new GalleryPhoto())->setPosition(2)];
        foreach ($photos as $photo) {
            $photo->setCategory($category);
        }

        $result = $this->createRepository($photos)->findPreviousAndNext($photos[1]);

        $this->assertSame($photos[0], $result['previous']);
        $this->assertSame($photos[2], $result['next']);
    }

    // The first photo's "previous" wraps around to the last one, so navigation never dead-ends
    public function testFindPreviousAndNextWrapsAroundAtTheStartOfTheCategory(): void
    {
        $category = new GalleryCategory();
        $photos = [(new GalleryPhoto())->setPosition(0), (new GalleryPhoto())->setPosition(1)];
        foreach ($photos as $photo) {
            $photo->setCategory($category);
        }

        $result = $this->createRepository($photos)->findPreviousAndNext($photos[0]);

        $this->assertSame($photos[1], $result['previous']);
        $this->assertSame($photos[1], $result['next']);
    }

    // The last photo's "next" wraps around to the first one
    public function testFindPreviousAndNextWrapsAroundAtTheEndOfTheCategory(): void
    {
        $category = new GalleryCategory();
        $photos = [(new GalleryPhoto())->setPosition(0), (new GalleryPhoto())->setPosition(1)];
        foreach ($photos as $photo) {
            $photo->setCategory($category);
        }

        $result = $this->createRepository($photos)->findPreviousAndNext($photos[1]);

        $this->assertSame($photos[0], $result['previous']);
        $this->assertSame($photos[0], $result['next']);
    }

    // A single-photo category wraps to itself on both sides
    public function testFindPreviousAndNextWrapsToItselfWhenTheCategoryHasOnlyOnePhoto(): void
    {
        $category = new GalleryCategory();
        $photo = (new GalleryPhoto())->setPosition(0)->setCategory($category);

        $result = $this->createRepository([$photo])->findPreviousAndNext($photo);

        $this->assertSame($photo, $result['previous']);
        $this->assertSame($photo, $result['next']);
    }

    // Position isn't a unique constraint (freely editable in the admin) - ties are broken by id, same order as the real ORDER BY p.position, p.id
    public function testFindPreviousAndNextBreaksPositionTiesById(): void
    {
        $category = new GalleryCategory();
        $photos = [(new GalleryPhoto())->setPosition(0), (new GalleryPhoto())->setPosition(0), (new GalleryPhoto())->setPosition(0)];
        foreach ($photos as $index => $photo) {
            $photo->setCategory($category);
            (new \ReflectionProperty(GalleryPhoto::class, 'id'))->setValue($photo, $index + 1);
        }

        $result = $this->createRepository($photos)->findPreviousAndNext($photos[1]);

        $this->assertSame($photos[0], $result['previous']);
        $this->assertSame($photos[2], $result['next']);
    }
}

// findAdjacent()/findEdge() go through Doctrine's real QueryBuilder/DQL internals (no in-memory equivalent to stub without a real EntityManager) - overriding them directly here with the same position/id ordering the real DQL applies, parent constructor never invoked, mirrors ConfigRepositoryFindOneBySlugFixture in ConfigBundle/SiteBundle. This exercises findPreviousAndNext()'s own wraparound/fallback orchestration for real, while the query construction itself is left to manual/functional verification
class GalleryPhotoRepositoryFindAdjacentFixture extends GalleryPhotoRepository
{
    public function __construct(private readonly array $photos)
    {
    }

    protected function findAdjacent(GalleryCategory $category, GalleryPhoto $photo, string $direction): ?GalleryPhoto
    {
        $ordered = $this->photos;
        usort($ordered, static fn (GalleryPhoto $a, GalleryPhoto $b): int => $a->getPosition() <=> $b->getPosition() ?: $a->getId() <=> $b->getId());

        $index = array_search($photo, $ordered, true);
        if (false === $index) {
            return null;
        }

        return 'next' === $direction ? ($ordered[$index + 1] ?? null) : ($index > 0 ? $ordered[$index - 1] : null);
    }

    protected function findEdge(GalleryCategory $category, string $edge): ?GalleryPhoto
    {
        if ([] === $this->photos) {
            return null;
        }

        $ordered = $this->photos;
        usort($ordered, static fn (GalleryPhoto $a, GalleryPhoto $b): int => $a->getPosition() <=> $b->getPosition() ?: $a->getId() <=> $b->getId());

        return 'first' === $edge ? $ordered[0] : $ordered[array_key_last($ordered)];
    }
}
