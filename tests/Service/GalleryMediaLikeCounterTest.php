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
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Service\GalleryMediaLikeCounter;
use c975L\UiBundle\Repository\RatingRepository;
use PHPUnit\Framework\TestCase;

class GalleryMediaLikeCounterTest extends TestCase
{
    private function createConfigService(bool $ratingEnabled): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug): mixed => 'gallery-rating' === $slug ? $ratingEnabled : null);
        $configService->method('getBool')->willReturnCallback(static fn (mixed $value): bool => true === $value);

        return $configService;
    }

    private function createMedia(int $id): GalleryMedia
    {
        $media = new GalleryMedia();
        $reflection = new \ReflectionProperty(GalleryMedia::class, 'id');
        $reflection->setValue($media, $id);

        return $media;
    }

    public function testItCountsTheLikesOfTheWholeGridInOneRead(): void
    {
        $ratingRepository = $this->createMock(RatingRepository::class);
        $ratingRepository->expects($this->once())
            ->method('getAggregates')
            ->with('gallery_media', [7, 9])
            ->willReturn([7 => ['average' => 1.0, 'count' => 3]]);

        $counter = new GalleryMediaLikeCounter($this->createConfigService(true), $ratingRepository);

        // The media nobody liked is simply absent, which is what the tile reads as no like at all
        $this->assertSame([7 => 3], $counter->count([$this->createMedia(7), $this->createMedia(9)]));
    }

    // A site that shows no heart is asked nothing at all: the badge would be reading a feature its visitors are not offered
    public function testItReadsNothingWhenTheSiteShowsNoHeart(): void
    {
        $ratingRepository = $this->createMock(RatingRepository::class);
        $ratingRepository->expects($this->never())->method('getAggregates');

        $counter = new GalleryMediaLikeCounter($this->createConfigService(false), $ratingRepository);

        $this->assertSame([], $counter->count([$this->createMedia(7)]));
    }

    public function testItReadsNothingForAnEmptyGrid(): void
    {
        $ratingRepository = $this->createMock(RatingRepository::class);
        $ratingRepository->expects($this->never())->method('getAggregates');

        $counter = new GalleryMediaLikeCounter($this->createConfigService(true), $ratingRepository);

        $this->assertSame([], $counter->count([]));
    }
}
