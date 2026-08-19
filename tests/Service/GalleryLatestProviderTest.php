<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Service\GalleryLatestProvider;
use PHPUnit\Framework\TestCase;

class GalleryLatestProviderTest extends TestCase
{
    /** @param array<string, mixed> $configs */
    private function createProvider(array $configs = [], array $medias = [], ?GalleryCategoryRepository $categoryRepository = null): GalleryLatestProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug): mixed => $configs[$slug] ?? null);

        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findLatest')->willReturn($medias);

        return new GalleryLatestProvider(
            $configService,
            $categoryRepository ?? $this->createStub(GalleryCategoryRepository::class),
            $mediaRepository,
        );
    }

    // A site that never opened the two entries gets the shipped rhythm rather than an empty gallery
    public function testTheDaysAndTheCeilingFallBackOnTheirDefaults(): void
    {
        $provider = $this->createProvider();

        $this->assertSame(GalleryLatestProvider::DEFAULT_DAYS, $provider->getDays());
        $this->assertSame(GalleryLatestProvider::DEFAULT_MAX, $provider->getMax());
    }

    public function testTheDaysAndTheCeilingAreReadFromTheConfiguration(): void
    {
        $provider = $this->createProvider(['gallery-latest-days' => '10', 'gallery-latest-max' => '50']);

        $this->assertSame(10, $provider->getDays());
        $this->assertSame(50, $provider->getMax());
    }

    // An entry emptied in the back office, or set to a value that would show nothing at all, is read as "not set" rather than as an empty gallery
    public function testAnEmptyOrNegativeEntryFallsBackOnItsDefault(): void
    {
        $provider = $this->createProvider(['gallery-latest-days' => '', 'gallery-latest-max' => '-10']);

        $this->assertSame(GalleryLatestProvider::DEFAULT_DAYS, $provider->getDays());
        $this->assertSame(1, $provider->getMax());
    }

    // Read once per request, however many screens of the page ask for the list
    public function testTheMediasAreReadOnlyOnce(): void
    {
        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->once())->method('findLatest')->willReturn([new GalleryMedia()]);

        $provider = new GalleryLatestProvider(
            $this->createStub(ConfigServiceInterface::class),
            $this->createStub(GalleryCategoryRepository::class),
            $mediaRepository,
        );

        $provider->getMedias();
        $provider->getMedias();
    }

    // The list only ever describes the request being rendered - a worker runtime keeping the service alive must not serve the next one the medias of the previous
    public function testResetDropsTheList(): void
    {
        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->exactly(2))->method('findLatest')->willReturn([]);

        $provider = new GalleryLatestProvider(
            $this->createStub(ConfigServiceInterface::class),
            $this->createStub(GalleryCategoryRepository::class),
            $mediaRepository,
        );

        $provider->getMedias();
        $provider->reset();
        $provider->getMedias();
    }

    // The back-office screen shows one grid per upload session, which is what a day of additions is
    public function testTheMediasAreGroupedByDayInTheOrderTheyWereRead(): void
    {
        $provider = $this->createProvider(medias: [
            $this->createMediaAddedOn('2026-08-19 10:00:00'),
            $this->createMediaAddedOn('2026-08-19 09:00:00'),
            $this->createMediaAddedOn('2026-08-17 08:00:00'),
        ]);

        $groups = $provider->getMediasByDay();

        $this->assertCount(2, $groups);
        $this->assertSame('2026-08-19', $groups[0]['day']->format('Y-m-d'));
        $this->assertCount(2, $groups[0]['medias']);
        $this->assertSame('2026-08-17', $groups[1]['day']->format('Y-m-d'));
        $this->assertCount(1, $groups[1]['medias']);
    }

    // Only the automatic category is handed the list: a normal one showing medias it doesn't hold would count and picture what it never displays
    public function testHydrateOnlyFillsTheAutomaticCategory(): void
    {
        $automatic = new GalleryCategory()->setAutomatic(true);
        $normal = new GalleryCategory();
        $provider = $this->createProvider(medias: [new GalleryMedia(), new GalleryMedia()]);

        $provider->hydrate([$automatic, $normal]);

        $this->assertSame(2, $automatic->getMediasCount());
        $this->assertSame(0, $normal->getMediasCount());
    }

    // Nobody creates the gallery of the last additions: it is written the first time it is asked for, and read back every time after
    public function testTheCategoryIsWrittenOnceAndReadBackAfterwards(): void
    {
        $category = new GalleryCategory()->setAutomatic(true);
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('findOrCreateAutomatic')->willReturn($category);

        $provider = $this->createProvider(categoryRepository: $categoryRepository);

        $this->assertSame($category, $provider->ensureCategory());
        $this->assertSame($category, $provider->ensureCategory());
    }

    // The list a screen read carries it already: nothing is written, and it is simply handed the medias it shows
    public function testPrepareHandsTheMediasToTheCategoryTheListAlreadyCarries(): void
    {
        $automatic = new GalleryCategory()->setAutomatic(true);
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->never())->method('findOrCreateAutomatic');

        $provider = $this->createProvider(medias: [new GalleryMedia(), new GalleryMedia()], categoryRepository: $categoryRepository);
        $categories = $provider->prepare([new GalleryCategory(), $automatic]);

        $this->assertCount(2, $categories);
        $this->assertSame(2, $automatic->getMediasCount());
    }

    // The very first render, on a site that has never had one: it is written and joins the list at its own rank
    public function testPrepareWritesTheCategoryAndPutsItInTheListAtItsPosition(): void
    {
        $automatic = new GalleryCategory()->setAutomatic(true)->setPosition(0);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOrCreateAutomatic')->willReturn($automatic);

        $categories = $this->createProvider(categoryRepository: $categoryRepository)
            ->prepare([new GalleryCategory()->setPosition(1), new GalleryCategory()->setPosition(2)]);

        $this->assertCount(3, $categories);
        $this->assertSame($automatic, $categories[0]);
    }

    // Moving it to the trash is how an admin is rid of it, so it must not come back on the site by the very call that would have written it
    public function testPrepareLeavesATrashedCategoryOutOfTheList(): void
    {
        $automatic = new GalleryCategory()->setAutomatic(true);
        $automatic->setIsDeleted(true);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOrCreateAutomatic')->willReturn($automatic);

        $categories = $this->createProvider(categoryRepository: $categoryRepository)->prepare([new GalleryCategory()]);

        $this->assertCount(1, $categories);
    }

    // A photo opened from the last additions is browsed among them: its neighbours are what was added just before and just after it, not what is filed next to it
    public function testFindPreviousAndNextWalksTheLatestMedias(): void
    {
        $medias = [new GalleryMedia(), new GalleryMedia(), new GalleryMedia()];
        $provider = $this->createProvider(medias: $medias);

        $result = $provider->findPreviousAndNext($medias[1]);

        $this->assertSame($medias[0], $result['previous']);
        $this->assertSame($medias[2], $result['next']);
    }

    // Circular like a category's own navigation, so walking the last additions never dead-ends
    public function testFindPreviousAndNextWrapsAroundAtBothEnds(): void
    {
        $medias = [new GalleryMedia(), new GalleryMedia()];
        $provider = $this->createProvider(medias: $medias);

        $this->assertSame($medias[1], $provider->findPreviousAndNext($medias[0])['previous']);
        $this->assertSame($medias[0], $provider->findPreviousAndNext($medias[1])['next']);
    }

    // A media that has since left the window is not walked among them at all - the page falls back on its own category's navigation, which is where it will still be tomorrow
    public function testFindPreviousAndNextReturnsNothingForAMediaOutsideTheList(): void
    {
        $provider = $this->createProvider(medias: [new GalleryMedia()]);

        $this->assertNull($provider->findPreviousAndNext(new GalleryMedia()));
    }

    private function createMediaAddedOn(string $date): GalleryMedia
    {
        $media = new GalleryMedia();
        new \ReflectionProperty(GalleryMedia::class, 'createdAt')->setValue($media, new \DateTimeImmutable($date));

        return $media;
    }
}
