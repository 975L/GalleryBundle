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
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Management\GalleryFeedProvider;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class GalleryFeedProviderTest extends TestCase
{
    private function createProvider(?string $siteUrl, array $medias): GalleryFeedProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($siteUrl);

        $repository = $this->createStub(GalleryMediaRepository::class);
        $repository->method('findRecent')->willReturn($medias);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters): string => '/' . $parameters[GalleryRoutePrefix::PARAMETER] . '/' . $parameters['category'] . '/' . $parameters['slug']
        );

        return new GalleryFeedProvider($repository, new SiteUrlResolver($configService), $urlGenerator, new GalleryRoutePrefix($configService), $this->createStub(TranslatorInterface::class));
    }

    // createdAt has no setter, so it is written through reflection
    private function createMedia(string $slug): GalleryMedia
    {
        $media = new GalleryMedia()->setSlug($slug)->setTitle(ucfirst($slug))->setFilename('medias/gallery/' . $slug . '.webp')->setCategory(new GalleryCategory()->setSlug('trips'));
        new \ReflectionProperty(GalleryMedia::class, 'createdAt')->setValue($media, new \DateTimeImmutable('2026-10-01'));

        return $media;
    }

    public function testEachMediaLinksToItsPageWithItsMediumFile(): void
    {
        $entries = $this->createProvider('https://example.com/', [$this->createMedia('lake')])->getEntries(20);

        $this->assertCount(1, $entries);
        $this->assertStringEndsWith('/trips/lake', $entries[0]['url']);
        $this->assertStringStartsWith('https://example.com/', $entries[0]['url']);
        $this->assertSame('Lake', $entries[0]['title']);
        $this->assertSame('https://example.com/medias/gallery/lake.webp', $entries[0]['image']);
    }

    public function testNoSiteUrlMeansNoEntry(): void
    {
        $this->assertSame([], $this->createProvider(null, [$this->createMedia('lake')])->getEntries(20));
    }
}
