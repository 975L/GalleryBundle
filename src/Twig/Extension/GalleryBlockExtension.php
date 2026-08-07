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
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Resolves, at render time, what the block templates of this bundle display - a Block only ever stores what to show (a category slug, a maximum), never the medias themselves, so a block never goes stale against the media library. Same split as BookBundle's BookBlockExtension
class GalleryBlockExtension extends AbstractExtension
{
    public function __construct(
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryMediaRepository $mediaRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gallery_block_categories', [$this, 'getCategories']),
            new TwigFunction('gallery_block_medias', [$this, 'getMedias']),
        ];
    }

    /**
     * @return list<GalleryCategory>
     */
    public function getCategories(?int $max = null): array
    {
        $categories = $this->categoryRepository->findAllOrdered();

        return null !== $max ? \array_slice($categories, 0, $max) : $categories;
    }

    /**
     * A null "category" is what a block pointing at a renamed or deleted one returns, its template then rendering nothing rather than an empty grid.
     * Shuffling happens before the maximum applies, so "4 medias at random" draws from the whole category and not from its first four; the block being uncached, a new draw is made at every render.
     *
     * @return array{category: ?GalleryCategory, medias: list<GalleryMedia>}
     */
    public function getMedias(?string $categorySlug, ?int $max = null, bool $random = false): array
    {
        $category = null !== $categorySlug ? $this->categoryRepository->findOneBySlug($categorySlug) : null;

        if (null === $category) {
            return ['category' => null, 'medias' => []];
        }

        $medias = $this->mediaRepository->findByCategory($category);

        if ($random) {
            shuffle($medias);
        }

        return [
            'category' => $category,
            'medias' => null !== $max ? \array_slice($medias, 0, $max) : $medias,
        ];
    }
}
