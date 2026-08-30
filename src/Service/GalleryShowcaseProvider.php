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
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use c975L\UiBundle\Contract\GalleryShowcaseProviderInterface;
use c975L\UiBundle\Registry\PlaceholderMediaRegistry;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

// Shows both of this bundle's block kinds in a block showcase (see UiBundle's GalleryShowcaseRegistry). Neither fits BlockFixtureProviderInterface: their templates resolve real content live via gallery_block_*() (GalleryBlockExtension), querying the default gallery straight from the database. Rendered here instead, directly against the same components, bypassing those queries.
class GalleryShowcaseProvider implements GalleryShowcaseProviderInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly PlaceholderMediaRegistry $placeholderMediaRegistry,
        private readonly GallerySampleCatalog $catalog,
        private readonly ConfigServiceInterface $configService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getShowcases(): array
    {
        // A media grid with no media in it shows nothing at all, so a site declaring no placeholder image gets no gallery showcase rather than an empty frame
        $images = $this->placeholderMediaRegistry->getImages();
        if ([] === $images) {
            return [];
        }

        return [
            $this->translator->trans('label.gallery_showcase_categories', [], 'gallery') => [
                'description' => $this->translator->trans('label.gallery_showcase_categories_description', [], 'gallery'),
                'kind' => 'gallery_categories',
                'variants' => ['' => $this->categoriesVariant($images)],
            ],
            $this->translator->trans('label.gallery_showcase_medias', [], 'gallery') => [
                'description' => $this->translator->trans('label.gallery_showcase_medias_description', [], 'gallery'),
                'kind' => 'gallery_medias',
                'variants' => ['' => $this->mediasVariant($images)],
            ],
        ];
    }

    // Stand-ins are plain arrays, not GalleryMedia/GalleryCategory: a real media derives its thumbnail filename from its own uploaded file (see GalleryMedia::getThumbnailFilename()), which a site's placeholder image has no sibling of - the components read their attributes the same way either way
    private function categoriesVariant(array $images): string
    {
        $categories = [];
        foreach (array_slice($this->catalog->getCategories(), 0, 3) as $index => $spec) {
            $cover = $spec['medias'][0];
            $categories[] = $this->category($spec, [$this->media($cover, $this->catalog->photograph($cover['slug'], $images, $index), $spec['slug'])]);
        }

        return $this->twig->render('@c975LGallery/components/Gallery/Categories.html.twig', [
            'categories' => $categories,
        ]);
    }

    private function mediasVariant(array $images): string
    {
        $spec = $this->catalog->getCategories()[0];

        $medias = [];
        foreach (array_slice($spec['medias'], 0, 4) as $index => $mediaSpec) {
            $medias[] = $this->media($mediaSpec, $this->catalog->photograph($mediaSpec['slug'], $images, $index), $spec['slug']);
        }

        // Each media names the category filing it, the way a real one does - Medias.html.twig hands that one, not the grid's own, to the Media component building the photo's url
        $category = $this->category($spec, $medias);
        $medias = array_map(static fn (array $media): array => $media + ['category' => $category], $medias);

        return $this->twig->render('@c975LGallery/components/Gallery/Medias.html.twig', [
            'category' => $category,
            'medias' => $medias,
            'displayTitle' => true,
        ]);
    }

    // Every key Category.html.twig and Medias.html.twig read on a category: the cover falls back to a random media on a real entity (GalleryCategory::getCoverOrRandomMedia()), where a stand-in simply names its first one, and "automatic" stays false, the showcase standing for an ordinary gallery rather than one of those gathering their medias
    /**
     * @param array{slug: string, title: string, medias: list<array{slug: string, title: string}>} $spec
     * @param list<array<string, mixed>>                                                           $medias
     *
     * @return array<string, mixed>
     */
    private function category(array $spec, array $medias): array
    {
        return [
            'slug' => $spec['slug'],
            'title' => $this->translator->trans($spec['title'], [], 'gallery'),
            'coverOrRandomMedia' => $medias[0] ?? null,
            'mediasCount' => count($medias),
            'automatic' => false,
            'url' => $this->url('gallery_category', ['category' => $spec['slug']]),
        ];
    }

    /**
     * @param array{slug: string, title: string} $spec
     *
     * @return array<string, mixed>
     */
    private function media(array $spec, string $image, string $categorySlug): array
    {
        return [
            'slug' => $spec['slug'],
            'title' => $this->translator->trans($spec['title'], [], 'gallery'),
            // The placeholder image stands in for the thumbnail a real media derives from its stored file - the grids read that one alone, whichever way they frame it (see Media.html.twig)
            'thumbnailFilename' => $image,
            'video' => false,
            'url' => $this->url('gallery_media', ['category' => $categorySlug, 'slug' => $spec['slug']]),
        ];
    }

    /**
     * Where a stand-in's thumbnail leads: the demonstration site the app names in "ui-showcase-demo-url", which
     * is the only place holding these categories and these medias (same catalog, see GalleryDemoFixtureProvider) -
     * the site rendering the showcase has none of them, and its own gallery url would be a 404.
     *
     * Empty when no demonstration is named, and Image:Link then renders a <span> rather than a dead link. The path
     * is generated against the default gallery prefix rather than this site's own (see GalleryRoutePrefix): that
     * first segment is edited in each back office, and a site having renamed it would send the visitor to a segment
     * the demonstration does not answer on.
     *
     * @param array<string, string> $parameters
     */
    private function url(string $route, array $parameters): string
    {
        $demo = rtrim((string) $this->configService->get('ui-showcase-demo-url'), '/');

        return '' === $demo ? '' : $demo . $this->urlGenerator->generate($route, $parameters + [GalleryRoutePrefix::PARAMETER => GalleryRoutePrefix::DEFAULT]);
    }
}
