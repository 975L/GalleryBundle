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
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Service\GalleryLatestProvider;
use PHPUnit\Framework\TestCase;

class GalleryLatestProviderTest extends TestCase
{
    /** @param array<string, mixed> $configs */
    private function createProvider(array $configs = [], array $medias = [], ?GalleryMediaRepository $mediaRepository = null): GalleryLatestProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug): mixed => $configs[$slug] ?? null);

        if (null === $mediaRepository) {
            $mediaRepository = $this->createStub(GalleryMediaRepository::class);
            $mediaRepository->method('findLatest')->willReturn($medias);
        }

        return new GalleryLatestProvider($configService, $mediaRepository);
    }

    // The kind the category of this gallery carries, and what tells a site that never sells prints it still wants this one
    public function testItIsTheGalleryOfTheLastAdditionsAndEverySiteHasIt(): void
    {
        $provider = $this->createProvider();

        $this->assertSame(GalleryCategory::AUTOMATIC_LATEST, $provider->getKind());
        $this->assertTrue($provider->isAvailable());
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

        $provider = new GalleryLatestProvider($this->createStub(ConfigServiceInterface::class), $mediaRepository);

        $provider->getMedias();
        $provider->getMedias();
    }

    // The list only ever describes the request being rendered - a worker runtime keeping the service alive must not serve the next one the medias of the previous
    public function testResetDropsTheList(): void
    {
        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->exactly(2))->method('findLatest')->willReturn([]);

        $provider = new GalleryLatestProvider($this->createStub(ConfigServiceInterface::class), $mediaRepository);

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
    private function createMediaAddedOn(string $date): GalleryMedia
    {
        $media = new GalleryMedia();
        new \ReflectionProperty(GalleryMedia::class, 'createdAt')->setValue($media, new \DateTimeImmutable($date));

        return $media;
    }
}
