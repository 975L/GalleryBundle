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
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Management\GallerySitemapProvider;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class GallerySitemapProviderTest extends TestCase
{
    private function createConfigService(?string $siteUrl): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($siteUrl);

        return $configService;
    }

    private function createCategory(string $slug): GalleryCategory
    {
        return new GalleryCategory()->setSlug($slug)->setTitle(ucfirst($slug));
    }

    // The slug is the route parameter; createdAt is set by the constructor and has no setter, so it is written straight through reflection rather than around it
    private function createMedia(string $slug, ?string $updatedAt = null, ?string $createdAt = null): GalleryMedia
    {
        $media = new GalleryMedia()->setSlug($slug);
        new \ReflectionProperty(GalleryMedia::class, 'createdAt')->setValue($media, null === $createdAt ? null : new \DateTimeImmutable($createdAt));

        if (null !== $updatedAt) {
            $media->setUpdatedAt(new \DateTimeImmutable($updatedAt));
        }

        return $media;
    }

    private function createProvider(?string $siteUrl, array $categories, array $mediasByCategorySlug = [], string $routePrefix = GalleryRoutePrefix::DEFAULT): GallerySitemapProvider
    {
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findAllOrdered')->willReturn($categories);

        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findVisibleByCategory')->willReturnCallback(
            static fn (GalleryCategory $category): array => $mediasByCategorySlug[$category->getSlug()] ?? []
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Galerie Medias');

        return new GallerySitemapProvider(
            $this->createConfigService($siteUrl),
            $categoryRepository,
            $mediaRepository,
            $translator,
            new GalleryRoutePrefix($this->createConfigService($routePrefix)),
        );
    }

    public function testGetSitemapNameReturnsGallery(): void
    {
        $this->assertSame('gallery', $this->createProvider('https://example.com', [])->getSitemapName());
    }

    public function testGetUrlsReturnsEmptyArrayWithoutASiteUrl(): void
    {
        $provider = $this->createProvider(null, [$this->createCategory('nature')]);

        $this->assertSame([], $provider->getUrls());
    }

    // Nothing to declare before a single category exists - the index page itself has no content to show either
    public function testGetUrlsReturnsEmptyArrayWithoutAnyCategory(): void
    {
        $this->assertSame([], $this->createProvider('https://example.com', [])->getUrls());
    }

    public function testGetUrlsDeclaresTheIndexAheadOfTheCategories(): void
    {
        $urls = $this->createProvider('https://example.com', [$this->createCategory('nature')])->getUrls();

        $this->assertSame('https://example.com/gallery', $urls[0]['loc']);
        $this->assertSame('https://example.com/gallery/nature', $urls[1]['loc']);
    }

    public function testGetUrlsDeclaresEachCategoryAndEachMedia(): void
    {
        $provider = $this->createProvider(
            'https://example.com/',
            [$this->createCategory('nature'), $this->createCategory('ville')],
            ['nature' => [$this->createMedia('col-du-galibier', '2026-05-04'), $this->createMedia('mont-blanc', '2026-06-08')]],
        );

        $urls = array_column($provider->getUrls(), 'loc');

        $this->assertSame([
            'https://example.com/gallery',
            'https://example.com/gallery/nature',
            'https://example.com/gallery/nature/col-du-galibier',
            'https://example.com/gallery/nature/mont-blanc',
            'https://example.com/gallery/ville',
        ], $urls);
    }

    // The declared urls follow the configured route prefix, the routes themselves being mounted on it
    public function testGetUrlsFollowsTheConfiguredRoutePrefix(): void
    {
        $provider = $this->createProvider(
            'https://example.com',
            [$this->createCategory('nature')],
            ['nature' => [$this->createMedia('col-du-galibier', '2026-05-04')]],
            'galerie',
        );

        $this->assertSame([
            'https://example.com/galerie',
            'https://example.com/galerie/nature',
            'https://example.com/galerie/nature/col-du-galibier',
        ], array_column($provider->getUrls(), 'loc'));
    }

    // A category holds no date of its own: its content is the media list, so the most recently touched one dates it
    public function testGetUrlsDatesACategoryFromItsMostRecentMedia(): void
    {
        $provider = $this->createProvider(
            'https://example.com',
            [$this->createCategory('nature')],
            ['nature' => [$this->createMedia('col-du-galibier', '2026-05-04'), $this->createMedia('mont-blanc', '2026-06-08')]],
        );

        $urls = $provider->getUrls();

        $this->assertSame('2026-06-08', $urls[1]['lastmod']);
        $this->assertSame('2026-05-04', $urls[2]['lastmod']);
    }

    // A media never edited since upload is dated by its creation instead
    public function testGetUrlsFallsBackToTheMediaCreationDate(): void
    {
        $provider = $this->createProvider(
            'https://example.com',
            [$this->createCategory('nature')],
            ['nature' => [$this->createMedia('col-du-galibier', null, '2026-02-01')]],
        );

        $this->assertSame('2026-02-01', $provider->getUrls()[2]['lastmod']);
    }

    // Every url carries the four keys SitemapWriter expects
    public function testGetUrlsReturnsCompleteEntries(): void
    {
        $provider = $this->createProvider(
            'https://example.com',
            [$this->createCategory('nature')],
            ['nature' => [$this->createMedia('col-du-galibier', '2026-05-04')]],
        );

        foreach ($provider->getUrls() as $url) {
            $this->assertSame(['loc', 'lastmod', 'changefreq', 'priority'], array_slice(array_keys($url), 0, 4));
            $this->assertIsInt($url['priority']);
        }
    }

    // "title" is what ConfigBundle's SeoFilesWriter builds llms.txt from - the index and the categories carry one
    public function testGetUrlsTitlesTheIndexAndTheCategories(): void
    {
        $provider = $this->createProvider(
            'https://example.com',
            [$this->createCategory('nature')],
            ['nature' => [$this->createMedia('col-du-galibier', '2026-05-04')]],
        );

        $urls = $provider->getUrls();

        $this->assertSame('Galerie Medias', $urls[0]['title']);
        $this->assertSame('Nature', $urls[1]['title']);
    }

    // One url is declared per media: titling them all would turn llms.txt into a Markdown sitemap, so they are skipped there
    public function testGetUrlsLeavesTheMediasUntitled(): void
    {
        $provider = $this->createProvider(
            'https://example.com',
            [$this->createCategory('nature')],
            ['nature' => [$this->createMedia('col-du-galibier', '2026-05-04')]],
        );

        $this->assertArrayNotHasKey('title', $provider->getUrls()[2]);
    }
}
