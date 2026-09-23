<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use c975L\UiBundle\Contract\SocialContentSourceInterface;
use c975L\UiBundle\Model\SocialContent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Hands SocialBundle's publication the gallery's photographs, one at a time - drawn at random, or oldest first, as "gallery-social-order" says - a site without SocialBundle simply never asks. What went out where is SocialBundle's to record, so nothing here nor on GalleryMedia keeps track of it
class GallerySocialContentSource implements SocialContentSourceInterface
{
    public function __construct(
        private readonly GalleryMediaRepository $mediaRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly ConfigServiceInterface $configService,
        private readonly GalleryRoutePrefix $routePrefix,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function getSourceType(): string
    {
        return 'gallery_media';
    }

    // Never: the same photograph twice is a repeat, a gallery keeps growing with new ones to post
    public function getRepeatAfterDays(): ?int
    {
        return null;
    }

    public function getNextContent(array $excludedIds): ?SocialContent
    {
        if (null === $this->siteUrlResolver->siteUrl()) {
            return null;
        }

        // At random unless the site asked for its oldest first: a gallery followed day after day reads better as a surprise than as its own archive in order
        if ('oldest' === $this->configService->get('gallery-social-order')) {
            return $this->toContent($this->mediaRepository->findNextToPost($excludedIds));
        }

        $ids = $this->mediaRepository->findPostableIds($excludedIds);

        return [] === $ids ? null : $this->toContent($this->mediaRepository->findPostable($ids[array_rand($ids)]));
    }

    // Null as well for a photograph taken off the site since the post was prepared, or whose gallery was: a reviewer publishing it a day later must not put back what was withdrawn
    public function getContent(string $sourceId): ?SocialContent
    {
        return $this->toContent($this->mediaRepository->findPostable((int) $sourceId));
    }

    // Null while "site-url" is unset: the run happens in a console, with no request to take the host from, and a post linking to a relative url would lead nowhere
    private function toContent(?GalleryMedia $media): ?SocialContent
    {
        $siteUrl = $this->siteUrlResolver->siteUrl();
        if (null === $media || null === $siteUrl || null === $media->getFilename()) {
            return null;
        }

        // The prefix passed by hand: GalleryRoutePrefixListener only sets it on an HTTP request, and a console has none
        $path = $this->urlGenerator->generate('gallery_media', [
            GalleryRoutePrefix::PARAMETER => $this->routePrefix->get(),
            'category' => $media->getCategory()?->getSlug(),
            'slug' => $media->getSlug(),
        ]);
        $title = (string) ($media->getTitle() ?? $media->getSlug());

        return new SocialContent(
            sourceId: (string) $media->getId(),
            title: $title,
            url: $siteUrl . $path,
            // The medium file, the one every page shows: a few hundred kilobytes, well under what a network accepts, where the high resolution one may not be
            imagePath: $this->projectDir . '/public/' . $media->getFilename(),
            imageUrl: $siteUrl . '/' . $media->getFilename(),
            imageAlt: $title,
            variables: $this->variables($media),
        );
    }

    // What the site's post template may carry beside the title and the url
    /** @return array<string, string> */
    private function variables(GalleryMedia $media): array
    {
        return array_filter([
            'category' => (string) $media->getCategory()?->getTitle(),
            'description' => trim(html_entity_decode(strip_tags((string) $media->getDescription()))),
            'credits' => (string) $media->getCredits(),
        ], static fn (string $value): bool => '' !== $value);
    }
}
