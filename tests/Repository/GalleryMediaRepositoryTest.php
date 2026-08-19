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

    // --- findLatest ----------------------------------------------------------------------------------------

    // A rolling window of calendar days, today included: what arrived within it is shown, the rest is not, whatever day it was added on
    public function testFindLatestKeepsWhatWasAddedWithinTheWindow(): void
    {
        $repository = new GalleryMediaRepositoryLatestFixture([
            $this->createMediaAddedOn('-1 day'),
            $this->createMediaAddedOn('-2 days'),
            $this->createMediaAddedOn('-30 days'),
        ]);

        $this->assertCount(2, $repository->findLatest(7, 200));
    }

    // Today counts as one of the days, so a one-day window is today alone
    public function testFindLatestCountsTodayAsOneOfTheDays(): void
    {
        $repository = new GalleryMediaRepositoryLatestFixture([
            $this->createMediaAddedOn('now'),
            $this->createMediaAddedOn('-1 day'),
        ]);

        $this->assertCount(1, $repository->findLatest(1, 200));
    }

    // A site that has published nothing this week shows its last session rather than an empty gallery - and that day alone, not the ones before it
    public function testFindLatestFallsBackOnTheLastDayCarryingAnAddition(): void
    {
        $repository = new GalleryMediaRepositoryLatestFixture([
            $this->createMediaAddedOn('-30 days 10:00'),
            $this->createMediaAddedOn('-30 days 09:00'),
            $this->createMediaAddedOn('-31 days 09:00'),
        ]);

        $this->assertCount(2, $repository->findLatest(7, 200));
    }

    // An empty library is an empty gallery, the fallback having nothing to fall back on
    public function testFindLatestReturnsNothingWithoutASingleMedia(): void
    {
        $this->assertSame([], new GalleryMediaRepositoryLatestFixture([])->findLatest(7, 200));
    }

    // The ceiling bounds the read itself: the day a whole library was brought in is served as its most recent medias, not whole
    public function testFindLatestNeverGoesPastTheCeiling(): void
    {
        $medias = [];
        for ($i = 0; $i < 10; ++$i) {
            $medias[] = $this->createMediaAddedOn('-1 day');
        }

        $this->assertCount(4, new GalleryMediaRepositoryLatestFixture($medias)->findLatest(7, 4));
    }

    // An entry set to nothing empties the gallery rather than reading the whole table - the two configs are read with their own fallbacks (see GalleryLatestProvider)
    public function testFindLatestReturnsNothingWithoutADayOrACeiling(): void
    {
        $repository = new GalleryMediaRepositoryLatestFixture([$this->createMediaAddedOn('now')]);

        $this->assertSame([], $repository->findLatest(0, 200));
        $this->assertSame([], $repository->findLatest(7, 0));
    }

    // Relative to the day the test runs on, the window findLatest() opens being counted from today
    private function createMediaAddedOn(string $date): GalleryMedia
    {
        $media = new GalleryMedia();
        new \ReflectionProperty(GalleryMedia::class, 'createdAt')->setValue($media, new \DateTimeImmutable($date));

        return $media;
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

// latestMedias() reads the table through Doctrine's own QueryBuilder, which has no in-memory equivalent to stub without a real EntityManager - the same filtering and the same ordering are applied here instead, parent constructor never invoked, so findLatest()'s window and its fallback are exercised for real
class GalleryMediaRepositoryLatestFixture extends GalleryMediaRepository
{
    public function __construct(private readonly array $medias)
    {
    }

    #[\Override]
    protected function latestMedias(?\DateTimeImmutable $since, int $limit): array
    {
        $medias = array_filter(
            $this->medias,
            static fn (GalleryMedia $media): bool => null === $since || $media->getCreatedAt() >= $since
        );

        usort($medias, static fn (GalleryMedia $a, GalleryMedia $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());

        return \array_slice($medias, 0, $limit);
    }
}
