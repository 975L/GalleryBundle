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
use c975L\GalleryBundle\Service\GalleryPrintableProvider;
use PHPUnit\Framework\TestCase;

class GalleryPrintableProviderTest extends TestCase
{
    /** @param array<string, mixed> $configs */
    private function createProvider(array $configs = [], array $medias = [], ?GalleryMediaRepository $mediaRepository = null): GalleryPrintableProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug): mixed => $configs[$slug] ?? null);

        if (null === $mediaRepository) {
            $mediaRepository = $this->createStub(GalleryMediaRepository::class);
            $mediaRepository->method('findPrintable')->willReturn($medias);
        }

        return new GalleryPrintableProvider($configService, $mediaRepository);
    }

    public function testItIsTheGalleryOfThePrints(): void
    {
        $this->assertSame(GalleryCategory::AUTOMATIC_PRINTABLE, $this->createProvider()->getKind());
    }

    // A site that never opened the shop must not grow a gallery of prints (see GalleryAutomaticProvider::ensureCategory)
    public function testItIsOnlyWantedWhereTheShopIsOpen(): void
    {
        $this->assertFalse($this->createProvider()->isAvailable());
        $this->assertFalse($this->createProvider(['gallery-print-enabled' => false])->isAvailable());
        $this->assertTrue($this->createProvider(['gallery-print-enabled' => true])->isAvailable());
    }

    // A site that never opened the entry gets the shipped ceiling rather than an empty gallery, and a negative one is floored at a photograph rather than emptying it - same as the gallery of the last additions
    public function testTheCeilingFallsBackOnItsDefault(): void
    {
        $this->assertSame(GalleryPrintableProvider::DEFAULT_MAX, $this->createProvider()->getMax());
        $this->assertSame(GalleryPrintableProvider::DEFAULT_MAX, $this->createProvider(['gallery-printable-max' => ''])->getMax());
        $this->assertSame(1, $this->createProvider(['gallery-printable-max' => '-5'])->getMax());
    }

    public function testTheCeilingIsReadFromTheConfiguration(): void
    {
        $this->assertSame(40, $this->createProvider(['gallery-printable-max' => 40])->getMax());
    }

    // Every screen showing the gallery asks for the same list, and it is read once for the request they are all rendered in
    public function testTheMediasAreReadOnlyOnceAndBoundedByTheCeiling(): void
    {
        $media = new GalleryMedia();
        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->once())->method('findPrintable')->with(40)->willReturn([$media]);

        $provider = $this->createProvider(['gallery-printable-max' => 40], mediaRepository: $mediaRepository);

        $this->assertSame([$media], $provider->getMedias());
        $this->assertSame([$media], $provider->getMedias());
    }

    // The list only ever describes the request being rendered - a worker runtime keeping the service alive must not serve the next one the medias of the previous
    public function testResetDropsTheList(): void
    {
        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->exactly(2))->method('findPrintable')->willReturn([]);

        $provider = $this->createProvider(mediaRepository: $mediaRepository);

        $provider->getMedias();
        $provider->reset();
        $provider->getMedias();
    }
}
