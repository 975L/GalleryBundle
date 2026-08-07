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
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use Symfony\Contracts\Translation\TranslatorInterface;

// Declares the gallery index, its categories and every media page (public/sitemap-gallery.xml) - GalleryBundle's contribution to the site's sitemap-index.xml, written by ConfigBundle's SitemapWriter (c975l:sitemaps:create) with no command of its own to run
// Monthly: these urls are health-checked too (ConfigBundle's DeclaredUrlsHealthCheckPass registers one provider per sitemap), and one declared url per media makes it by far the longest run - nothing like the handful of pages the weekly entry covers. A stale media page is also nothing like a product page going down
#[AsHealthCheck(frequency: AsHealthCheck::FREQUENCY_MONTHLY)]
class GallerySitemapProvider implements SitemapProviderInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly GalleryCategoryRepository $galleryCategoryRepository,
        private readonly GalleryMediaRepository $galleryMediaRepository,
        private readonly TranslatorInterface $translator,
        // The very service the routes match on, rather than a second hard-coded "/gallery" that a site renaming its prefix would leave behind in the sitemap. Built here instead of through the router: the command runs in cli, where there is no request to carry the prefix into the generator (see GalleryRoutePrefixListener)
        private readonly GalleryRoutePrefix $routePrefix,
    ) {
    }

    public function getSitemapName(): string
    {
        return 'gallery';
    }

    // A sitemap only accepts absolute urls, so there's nothing to declare before "site-url" is configured - and nothing either before a single category exists, the index page being that list
    public function getUrls(): array
    {
        $urlRoot = rtrim((string) $this->configService->get('site-url'), '/');
        $categories = $this->galleryCategoryRepository->findAllOrdered();
        if ('' === $urlRoot || [] === $categories) {
            return [];
        }

        // "title" is what ConfigBundle's SeoFilesWriter builds public/llms.txt from, the sitemap itself ignoring it. Only the index and the categories carry one: an untitled url is skipped there, and listing one line per media would turn llms.txt into a Markdown sitemap, which the format isn't
        $urls = [[
            'loc' => $urlRoot . '/' . $this->routePrefix->get(),
            'lastmod' => date('Y-m-d'),
            'changefreq' => 'weekly',
            'priority' => 8,
            'title' => $this->translator->trans('label.gallery_page_title', [], 'gallery'),
        ]];

        foreach ($categories as $category) {
            $urls = array_merge($urls, $this->getCategoryUrls($urlRoot, $category));
        }

        return $urls;
    }

    // A category and the medias it holds - a media has a page of its own (/{prefix}/{category}/{slug}), which is what an image search actually lands on, so each is declared rather than left to be discovered from its category page alone
    private function getCategoryUrls(string $urlRoot, GalleryCategory $category): array
    {
        $categoryUrl = $urlRoot . '/' . $this->routePrefix->get() . '/' . $category->getSlug();
        $medias = $this->galleryMediaRepository->findByCategory($category);

        // GalleryCategory carries no date of its own, so the most recently touched media is what dates the category page - its content is exactly that list
        $urls = [[
            'loc' => $categoryUrl,
            'lastmod' => $this->lastMediaDate($medias),
            'changefreq' => 'weekly',
            'priority' => 7,
            'title' => (string) $category->getTitle(),
        ]];

        // Left without a "title" on purpose, see getUrls() - a media page's own title says nothing a reader of llms.txt could act on
        foreach ($medias as $media) {
            $urls[] = [
                'loc' => $categoryUrl . '/' . $media->getSlug(),
                'lastmod' => ($media->getUpdatedAt() ?? $media->getCreatedAt())?->format('Y-m-d') ?? date('Y-m-d'),
                'changefreq' => 'monthly',
                'priority' => 5,
            ];
        }

        return $urls;
    }

    private function lastMediaDate(array $medias): string
    {
        $dates = [];
        foreach ($medias as $media) {
            $date = $media->getUpdatedAt() ?? $media->getCreatedAt();
            if (null !== $date) {
                $dates[] = $date->format('Y-m-d');
            }
        }

        return $dates ? max($dates) : date('Y-m-d');
    }
}
