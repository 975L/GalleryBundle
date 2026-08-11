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
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use PHPUnit\Framework\TestCase;

class GalleryMediaRepositoryTest extends TestCase
{
    private function createRepository(array $mediasInCategory): GalleryMediaRepository
    {
        return new GalleryMediaRepositoryFindAdjacentFixture($mediasInCategory);
    }

    public function testFindPreviousAndNextReturnsTheNeighboursInPosition(): void
    {
        $category = new GalleryCategory();
        $medias = [new GalleryMedia()->setPosition(0), new GalleryMedia()->setPosition(1), new GalleryMedia()->setPosition(2)];
        foreach ($medias as $media) {
            $media->setCategory($category);
        }

        $result = $this->createRepository($medias)->findPreviousAndNext($medias[1]);

        $this->assertSame($medias[0], $result['previous']);
        $this->assertSame($medias[2], $result['next']);
    }

    // The first media's "previous" wraps around to the last one, so navigation never dead-ends
    public function testFindPreviousAndNextWrapsAroundAtTheStartOfTheCategory(): void
    {
        $category = new GalleryCategory();
        $medias = [new GalleryMedia()->setPosition(0), new GalleryMedia()->setPosition(1)];
        foreach ($medias as $media) {
            $media->setCategory($category);
        }

        $result = $this->createRepository($medias)->findPreviousAndNext($medias[0]);

        $this->assertSame($medias[1], $result['previous']);
        $this->assertSame($medias[1], $result['next']);
    }

    // The last media's "next" wraps around to the first one
    public function testFindPreviousAndNextWrapsAroundAtTheEndOfTheCategory(): void
    {
        $category = new GalleryCategory();
        $medias = [new GalleryMedia()->setPosition(0), new GalleryMedia()->setPosition(1)];
        foreach ($medias as $media) {
            $media->setCategory($category);
        }

        $result = $this->createRepository($medias)->findPreviousAndNext($medias[1]);

        $this->assertSame($medias[0], $result['previous']);
        $this->assertSame($medias[0], $result['next']);
    }

    // A single-media category wraps to itself on both sides
    public function testFindPreviousAndNextWrapsToItselfWhenTheCategoryHasOnlyOneMedia(): void
    {
        $category = new GalleryCategory();
        $media = new GalleryMedia()->setPosition(0)->setCategory($category);

        $result = $this->createRepository([$media])->findPreviousAndNext($media);

        $this->assertSame($media, $result['previous']);
        $this->assertSame($media, $result['next']);
    }

    // Position isn't a unique constraint (freely editable in the admin) - ties are broken by id, same order as the real ORDER BY p.position, p.id
    public function testFindPreviousAndNextBreaksPositionTiesById(): void
    {
        $category = new GalleryCategory();
        $medias = [new GalleryMedia()->setPosition(0), new GalleryMedia()->setPosition(0), new GalleryMedia()->setPosition(0)];
        foreach ($medias as $index => $media) {
            $media->setCategory($category);
            new \ReflectionProperty(GalleryMedia::class, 'id')->setValue($media, $index + 1);
        }

        $result = $this->createRepository($medias)->findPreviousAndNext($medias[1]);

        $this->assertSame($medias[0], $result['previous']);
        $this->assertSame($medias[2], $result['next']);
    }
}

// findAdjacent()/findEdge() go through Doctrine's real QueryBuilder/DQL internals (no in-memory equivalent to stub without a real EntityManager) - overriding them directly here with the same position/id ordering the real DQL applies, parent constructor never invoked, mirrors ConfigRepositoryFindOneBySlugFixture in ConfigBundle/SiteBundle. This exercises findPreviousAndNext()'s own wraparound/fallback orchestration for real, while the query construction itself is left to manual/functional verification
class GalleryMediaRepositoryFindAdjacentFixture extends GalleryMediaRepository
{
    public function __construct(private readonly array $medias)
    {
    }

    #[\Override]
    protected function findAdjacent(GalleryCategory $category, GalleryMedia $media, string $direction): ?GalleryMedia
    {
        $ordered = $this->medias;
        usort($ordered, static fn (GalleryMedia $a, GalleryMedia $b): int => $a->getPosition() <=> $b->getPosition() ?: $a->getId() <=> $b->getId());

        $index = array_search($media, $ordered, true);
        if (false === $index) {
            return null;
        }

        return 'next' === $direction ? ($ordered[$index + 1] ?? null) : ($index > 0 ? $ordered[$index - 1] : null);
    }

    #[\Override]
    protected function findEdge(GalleryCategory $category, string $edge): ?GalleryMedia
    {
        if ([] === $this->medias) {
            return null;
        }

        $ordered = $this->medias;
        usort($ordered, static fn (GalleryMedia $a, GalleryMedia $b): int => $a->getPosition() <=> $b->getPosition() ?: $a->getId() <=> $b->getId());

        return 'first' === $edge ? $ordered[0] : $ordered[array_key_last($ordered)];
    }
}
