<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\UiBundle\Contract\GalleryShowcaseProviderInterface;
use c975L\UiBundle\Registry\PlaceholderMediaRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

// Shows both of this bundle's block kinds in a block showcase (see UiBundle's GalleryShowcaseRegistry). Neither fits BlockFixtureProviderInterface: their templates resolve real content live via gallery_block_*() (GalleryBlockExtension), querying the default gallery straight from the database. Rendered here instead, directly against the same components, bypassing those queries.
class GalleryShowcaseProvider implements GalleryShowcaseProviderInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly PlaceholderMediaRegistry $placeholderMediaRegistry,
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
        foreach (array_slice($images, 0, 3) as $index => $image) {
            $number = $index + 1;
            $categories[] = $this->category($number, [$this->media($image, $number)]);
        }

        return $this->twig->render('@c975LGallery/components/Gallery/Categories.html.twig', [
            'categories' => $categories,
        ]);
    }

    private function mediasVariant(array $images): string
    {
        $medias = [];
        foreach (array_slice($images, 0, 4) as $index => $image) {
            $medias[] = $this->media($image, $index + 1);
        }

        // Each media names the category filing it, the way a real one does - Medias.html.twig hands that one, not the grid's own, to the Media component building the photo's url
        $category = $this->category(1, $medias);
        $medias = array_map(static fn (array $media): array => $media + ['category' => $category], $medias);

        return $this->twig->render('@c975LGallery/components/Gallery/Medias.html.twig', [
            'category' => $category,
            'medias' => $medias,
            'displayTitle' => true,
        ]);
    }

    // Every key Category.html.twig and Medias.html.twig read on a category: the cover falls back to a random media on a real entity (GalleryCategory::getCoverOrRandomMedia()), where a stand-in simply names its first one, and "automatic" stays false, the showcase standing for an ordinary gallery rather than the one gathering the latest additions
    /**
     * @param list<array<string, mixed>> $medias
     *
     * @return array<string, mixed>
     */
    private function category(int $number, array $medias): array
    {
        return [
            'slug' => 'categorie-exemple-' . $number,
            'title' => $this->translator->trans('label.gallery_showcase_category_title', ['%number%' => $number], 'gallery'),
            'coverOrRandomMedia' => $medias[0] ?? null,
            'mediasCount' => count($medias),
            'automatic' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function media(string $image, int $number): array
    {
        return [
            'slug' => 'media-exemple-' . $number,
            'title' => $this->translator->trans('label.gallery_showcase_media_title', ['%number%' => $number], 'gallery'),
            // The placeholder image stands in for the thumbnail a real media derives from its stored file - the grids read that one alone, whichever way they frame it (see Media.html.twig)
            'thumbnailFilename' => $image,
            'video' => false,
        ];
    }
}
