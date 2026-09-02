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

    // A site that never opened the shop must not grow a gallery of prints (see GalleryAutomaticProvider::ensureCategory), and neither must one whose ceiling entry says nothing
    public function testItIsOnlyWantedWhereTheShopIsOpenAndTheCeilingIsSet(): void
    {
        $this->assertFalse($this->createProvider()->isAvailable());
        $this->assertFalse($this->createProvider(['gallery-print-enabled' => false, 'gallery-printable-max' => '200'])->isAvailable());
        $this->assertFalse($this->createProvider(['gallery-print-enabled' => true])->isAvailable());
        $this->assertTrue($this->createProvider(['gallery-print-enabled' => true, 'gallery-printable-max' => '200'])->isAvailable());
    }

    // An entry emptied in the back office, or set to a value that would show nothing at all, closes the gallery rather than drawing it over a single photograph - same as the gallery of the last additions
    public function testAnEmptyOrNegativeCeilingClosesTheGallery(): void
    {
        $this->assertSame(0, $this->createProvider()->getMax());
        $this->assertSame(0, $this->createProvider(['gallery-printable-max' => ''])->getMax());
        $this->assertSame(0, $this->createProvider(['gallery-printable-max' => '-5'])->getMax());
    }

    // The entry ships with its ceiling, so the gallery a fresh install draws is the one it describes
    public function testTheEntryShipsWithItsCeiling(): void
    {
        $configs = json_decode(file_get_contents(__DIR__ . '/../../config/configs.json'), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame('200', array_column($configs, 'value', 'slug')['gallery-printable-max']);
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
