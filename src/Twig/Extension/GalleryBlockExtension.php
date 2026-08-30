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
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Service\GalleryAutomaticProvider;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

// Resolves, at render time, what the block templates of this bundle display - a Block only ever stores what to show (a category slug, a maximum), never the medias themselves, so a block never goes stale against the media library. Same split as BookBundle's BookBlockExtension
class GalleryBlockExtension implements ResetInterface
{
    /** @var ?list<GalleryCategory> */
    private ?array $categories = null;

    public function __construct(
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryAutomaticProvider $automaticProvider,
    ) {
    }

    /**
     * @return list<GalleryCategory>
     */
    #[AsTwigFunction('gallery_block_categories')]
    public function getCategories(?int $max = null): array
    {
        $categories = $this->loadCategories();

        return null !== $max ? \array_slice($categories, 0, $max) : $categories;
    }

    /**
     * A null "category" is what a block pointing at a renamed or deleted one returns, its template then rendering nothing rather than an empty grid.
     * Shuffling happens before the maximum applies, so "4 medias at random" draws from the whole category and not from its first four; the block being uncached, a new draw is made at every render.
     *
     * @return array{category: ?GalleryCategory, medias: list<GalleryMedia>}
     */
    #[AsTwigFunction('gallery_block_medias')]
    public function getMedias(?string $categorySlug, ?int $max = null, bool $random = false): array
    {
        $category = null !== $categorySlug ? $this->findCategoryBySlug($categorySlug) : null;

        if (null === $category) {
            return ['category' => null, 'medias' => []];
        }

        // An automatic gallery is offered in the block form like any other category, and dropped on a page it is what shows the site's last additions, or the photographs on sale, there - its list is gathered rather than read from a relation it has none of (see GalleryAutomaticProvider)
        $medias = $this->automaticProvider->getMedias($category);

        if ($random) {
            shuffle($medias);
        }

        return [
            'category' => $category,
            'medias' => null !== $max ? \array_slice($medias, 0, $max) : $medias,
        ];
    }

    // The whole ordered list is read once per request, every block then resolving its slug against it in PHP: a page composed of several gallery blocks points at a different category in each, so a findOneBySlug() per block would issue a query per block. findAllOrdered() also joins coverMedia, which the templates need anyway
    /** @return list<GalleryCategory> */
    private function loadCategories(): array
    {
        // The automatic galleries are part of the list, each holding the medias it shows - a block listing the categories carries them like any other, they having none of their own (see GalleryAutomaticProvider)
        return $this->categories ??= $this->automaticProvider->prepare($this->categoryRepository->findAllOrdered());
    }

    private function findCategoryBySlug(string $slug): ?GalleryCategory
    {
        foreach ($this->loadCategories() as $category) {
            if ($slug === $category->getSlug()) {
                return $category;
            }
        }

        return null;
    }

    // The list only ever describes the request being rendered - dropped between two of them so a worker runtime (FrankenPHP, RoadRunner...) doesn't serve the next one the categories of the previous
    public function reset(): void
    {
        $this->categories = null;
    }
}
