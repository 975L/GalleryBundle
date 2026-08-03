<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Attribute\AsHealthCheck;
use c975L\ConfigBundle\Management\SitemapProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Repository\GalleryPhotoRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;

// Declares the gallery, its categories and every photo page (public/sitemap-gallery.xml) - GalleryBundle's contribution to the site's sitemap-index.xml, written by ConfigBundle's SitemapWriter (c975l:sitemaps:create) with no command of its own to run. Only the default gallery is declared, matching what GalleryController actually serves: a second, non-default Gallery has no public route to point at yet
// Monthly: these urls are health-checked too (ConfigBundle's DeclaredUrlsHealthCheckPass registers one provider per sitemap), and one declared url per photo makes it by far the longest run - nothing like the handful of pages the weekly entry covers. A stale photo page is also nothing like a product page going down
#[AsHealthCheck(frequency: AsHealthCheck::FREQUENCY_MONTHLY)]
class GallerySitemapProvider implements SitemapProviderInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly GalleryRepository $galleryRepository,
        private readonly GalleryPhotoRepository $galleryPhotoRepository,
    ) {
    }

    public function getSitemapName(): string
    {
        return 'gallery';
    }

    // A sitemap only accepts absolute urls, so there's nothing to declare before "site-url" is configured - and nothing either before a gallery exists
    public function getUrls(): array
    {
        $urlRoot = rtrim((string) $this->configService->get('site-url'), '/');
        $gallery = $this->galleryRepository->findDefault();
        if ('' === $urlRoot || null === $gallery) {
            return [];
        }

        $urls = [[
            'loc' => $urlRoot . '/photos',
            'lastmod' => date('Y-m-d'),
            'changefreq' => 'weekly',
            'priority' => 8,
        ]];

        foreach ($gallery->getCategories() as $category) {
            $urls = array_merge($urls, $this->getCategoryUrls($urlRoot, $category));
        }

        return $urls;
    }

    // A category and the photos it holds - a photo has a page of its own (/photos/{category}/{id}), which is what an image search actually lands on, so each is declared rather than left to be discovered from its category page alone
    private function getCategoryUrls(string $urlRoot, GalleryCategory $category): array
    {
        $categoryUrl = $urlRoot . '/photos/' . $category->getSlug();
        $photos = $this->galleryPhotoRepository->findByCategory($category);

        // Neither Gallery nor GalleryCategory carries a date of its own, so the most recently touched photo is what dates the category page - its content is exactly that list
        $urls = [[
            'loc' => $categoryUrl,
            'lastmod' => $this->lastPhotoDate($photos),
            'changefreq' => 'weekly',
            'priority' => 7,
        ]];

        foreach ($photos as $photo) {
            $urls[] = [
                'loc' => $categoryUrl . '/' . $photo->getId(),
                'lastmod' => ($photo->getUpdatedAt() ?? $photo->getCreatedAt())?->format('Y-m-d') ?? date('Y-m-d'),
                'changefreq' => 'monthly',
                'priority' => 5,
            ];
        }

        return $urls;
    }

    private function lastPhotoDate(array $photos): string
    {
        $dates = [];
        foreach ($photos as $photo) {
            $date = $photo->getUpdatedAt() ?? $photo->getCreatedAt();
            if (null !== $date) {
                $dates[] = $date->format('Y-m-d');
            }
        }

        return $dates ? max($dates) : date('Y-m-d');
    }
}
