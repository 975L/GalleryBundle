<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Twig\Extension;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Service\GallerySnippetBuilder;
use Twig\Attribute\AsTwigFunction;

// A Twig function rather than a template of the bundle, on the model of BookBundle's book_json_ld(): the markup belongs to the bundle, the theme to the site, and a site overriding gallery/media.html.twig keeps its structured data by calling the same function
class GalleryJsonLdExtension
{
    public function __construct(private readonly GallerySnippetBuilder $snippetBuilder)
    {
    }

    // Returns the <script type="application/ld+json"> payload for a photograph's page, empty when there is neither a file to name nor a player to frame
    #[AsTwigFunction('gallery_media_json_ld', isSafe: ['html'])]
    public function mediaJsonLd(GalleryMedia $media, ?string $contentUrl = null, ?string $thumbnailUrl = null, ?string $url = null, bool $printAvailable = false, ?string $embedUrl = null): string
    {
        return $this->snippetBuilder->buildJson($this->snippetBuilder->buildMedia($media, $contentUrl, $thumbnailUrl, $url, $printAvailable, $embedUrl));
    }

    /**
     * Same for a gallery's page, whose graph lists the photographs it shows.
     *
     * @param list<array{name: string, url: string}> $items
     */
    #[AsTwigFunction('gallery_json_ld', isSafe: ['html'])]
    public function galleryJsonLd(GalleryCategory $category, array $items = [], ?string $url = null): string
    {
        return $this->snippetBuilder->buildJson($this->snippetBuilder->buildGallery($category, $items, $url));
    }

    /**
     * Same for the index, which lists the galleries themselves.
     *
     * @param list<array{name: string, url: string}> $items
     */
    #[AsTwigFunction('gallery_index_json_ld', isSafe: ['html'])]
    public function indexJsonLd(array $items = []): string
    {
        return $this->snippetBuilder->buildJson($this->snippetBuilder->buildIndex($items));
    }
}
