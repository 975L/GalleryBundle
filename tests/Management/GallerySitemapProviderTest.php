<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Management\GallerySitemapProvider;
use c975L\GalleryBundle\Repository\GalleryPhotoRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;
use PHPUnit\Framework\TestCase;

class GallerySitemapProviderTest extends TestCase
{
    private function createConfigService(?string $siteUrl): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($siteUrl);

        return $configService;
    }

    private function createGallery(array $categories): Gallery
    {
        $gallery = new Gallery();
        foreach ($categories as $category) {
            $gallery->addCategory($category);
        }

        return $gallery;
    }

    private function createCategory(string $slug): GalleryCategory
    {
        return (new GalleryCategory())->setSlug($slug);
    }

    // The id is the route parameter and createdAt is set by the constructor - neither has a setter, both are written straight through reflection rather than around them
    private function createPhoto(int $id, ?string $updatedAt = null, ?string $createdAt = null): GalleryPhoto
    {
        $photo = new GalleryPhoto();
        (new \ReflectionProperty(GalleryPhoto::class, 'id'))->setValue($photo, $id);
        (new \ReflectionProperty(GalleryPhoto::class, 'createdAt'))->setValue($photo, null === $createdAt ? null : new \DateTimeImmutable($createdAt));

        if (null !== $updatedAt) {
            $photo->setUpdatedAt(new \DateTimeImmutable($updatedAt));
        }

        return $photo;
    }

    private function createProvider(?string $siteUrl, ?Gallery $gallery, array $photosByCategorySlug = []): GallerySitemapProvider
    {
        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findDefault')->willReturn($gallery);

        $photoRepository = $this->createStub(GalleryPhotoRepository::class);
        $photoRepository->method('findByCategory')->willReturnCallback(
            static fn (GalleryCategory $category): array => $photosByCategorySlug[$category->getSlug()] ?? []
        );

        return new GallerySitemapProvider($this->createConfigService($siteUrl), $galleryRepository, $photoRepository);
    }

    public function testGetSitemapNameReturnsGallery(): void
    {
        $this->assertSame('gallery', $this->createProvider('https://example.com', null)->getSitemapName());
    }

    public function testGetUrlsReturnsEmptyArrayWithoutASiteUrl(): void
    {
        $provider = $this->createProvider(null, $this->createGallery([$this->createCategory('nature')]));

        $this->assertSame([], $provider->getUrls());
    }

    // Nothing to declare before a gallery exists - the /photos page itself has no content to show either
    public function testGetUrlsReturnsEmptyArrayWithoutAGallery(): void
    {
        $this->assertSame([], $this->createProvider('https://example.com', null)->getUrls());
    }

    public function testGetUrlsDeclaresTheIndexEvenWithNoCategory(): void
    {
        $urls = $this->createProvider('https://example.com', $this->createGallery([]))->getUrls();

        $this->assertCount(1, $urls);
        $this->assertSame('https://example.com/photos', $urls[0]['loc']);
    }

    public function testGetUrlsDeclaresEachCategoryAndEachPhoto(): void
    {
        $provider = $this->createProvider(
            'https://example.com/',
            $this->createGallery([$this->createCategory('nature'), $this->createCategory('ville')]),
            ['nature' => [$this->createPhoto(12, '2026-05-04'), $this->createPhoto(13, '2026-06-08')]],
        );

        $urls = array_column($provider->getUrls(), 'loc');

        $this->assertSame([
            'https://example.com/photos',
            'https://example.com/photos/nature',
            'https://example.com/photos/nature/12',
            'https://example.com/photos/nature/13',
            'https://example.com/photos/ville',
        ], $urls);
    }

    // A category holds no date of its own: its content is the photo list, so the most recently touched one dates it
    public function testGetUrlsDatesACategoryFromItsMostRecentPhoto(): void
    {
        $provider = $this->createProvider(
            'https://example.com',
            $this->createGallery([$this->createCategory('nature')]),
            ['nature' => [$this->createPhoto(12, '2026-05-04'), $this->createPhoto(13, '2026-06-08')]],
        );

        $urls = $provider->getUrls();

        $this->assertSame('2026-06-08', $urls[1]['lastmod']);
        $this->assertSame('2026-05-04', $urls[2]['lastmod']);
    }

    // A photo never edited since upload is dated by its creation instead
    public function testGetUrlsFallsBackToThePhotoCreationDate(): void
    {
        $provider = $this->createProvider(
            'https://example.com',
            $this->createGallery([$this->createCategory('nature')]),
            ['nature' => [$this->createPhoto(12, null, '2026-02-01')]],
        );

        $this->assertSame('2026-02-01', $provider->getUrls()[2]['lastmod']);
    }

    // Every url carries the four keys SitemapWriter expects
    public function testGetUrlsReturnsCompleteEntries(): void
    {
        $provider = $this->createProvider(
            'https://example.com',
            $this->createGallery([$this->createCategory('nature')]),
            ['nature' => [$this->createPhoto(12, '2026-05-04')]],
        );

        foreach ($provider->getUrls() as $url) {
            $this->assertSame(['loc', 'lastmod', 'changefreq', 'priority'], array_keys($url));
            $this->assertIsInt($url['priority']);
        }
    }
}
