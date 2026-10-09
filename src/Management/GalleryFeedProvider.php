<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\FeedProviderInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// The latest photographs (/feed/gallery.xml) - GalleryBundle's contribution to the feeds ConfigBundle's FeedRenderer serves
class GalleryFeedProvider implements FeedProviderInterface
{
    public function __construct(
        private readonly GalleryMediaRepository $galleryMediaRepository,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly GalleryRoutePrefix $routePrefix,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // The feed's name, served at /feed/gallery.xml
    public function getFeedName(): string
    {
        return 'gallery';
    }

    // The feed's title, in the site's language
    public function getFeedTitle(): string
    {
        return $this->translator->trans('label.feed_gallery', [], 'gallery');
    }

    // Each media with the medium file every page shows, the high resolution one being too heavy for a feed reader
    public function getEntries(int $limit): array
    {
        $siteUrl = $this->siteUrlResolver->siteUrl();
        if (null === $siteUrl) {
            return [];
        }

        $entries = [];
        foreach ($this->galleryMediaRepository->findRecent($limit) as $media) {
            // The prefix passed by hand, GalleryRoutePrefixListener only setting it on an HTTP request
            $path = $this->urlGenerator->generate('gallery_media', [
                GalleryRoutePrefix::PARAMETER => $this->routePrefix->get(),
                'category' => $media->getCategory()?->getSlug(),
                'slug' => $media->getSlug(),
            ]);

            $entries[] = [
                'url' => $siteUrl . $path,
                'title' => (string) ($media->getTitle() ?? $media->getSlug()),
                'updated' => $media->getCreatedAt() ?? new \DateTimeImmutable(),
                'summary' => $media->getDescription(),
                'image' => null !== $media->getFilename() && !$media->isVideo() ? $siteUrl . '/' . $media->getFilename() : null,
            ];
        }

        return $entries;
    }
}
