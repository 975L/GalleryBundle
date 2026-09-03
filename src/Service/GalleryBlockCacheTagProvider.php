<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\UiBundle\Contract\BlockCacheTagProviderInterface;
use c975L\UiBundle\Entity\Block;

// Both kinds of this bundle resolve their content live at render time (see GalleryBlockExtension), which no Block/Media event ever signals a change of - so their entries carry the tag GalleryCacheInvalidationListener drops whenever a gallery, a photograph or one of the settings those blocks are drawn from changes. That is what lets them be cached at all rather than declared "cacheable: false", the same way ShopBundle's and BookBundle's are
class GalleryBlockCacheTagProvider implements BlockCacheTagProviderInterface
{
    public function __construct(
        private readonly GalleryCategoryRepository $galleryCategoryRepository,
        private readonly GalleryLatestProvider $latestProvider,
    ) {
    }

    public function getCacheTagResolvers(): array
    {
        return [
            'gallery_categories' => $this->resolveCategories(...),
            'gallery_medias' => $this->resolveMedias(...),
        ];
    }

    // Null, i.e. render this block live, for as long as the gallery of the last additions is offered: it holds the photographs of the last days (see GalleryLatestProvider), which is a list moving on its own as those days go by - and the listing draws each gallery with a thumbnail taken from what it holds. A cache entry never expires (see BlockExtension, $item->expiresAfter(null)) and nothing is saved the day a photograph leaves that window, so the entry would show it for good
    /**
     * @return string[]|null
     */
    private function resolveCategories(Block $block): ?array
    {
        if ($this->latestProvider->isAvailable()) {
            return null;
        }

        // A gallery nobody picked a cover for draws its tile at random on each render (see GalleryCategory::getCoverOrRandomMedia()), which a cache entry would freeze into one single draw - the very reason resolveMedias() declines a block asked for a random order
        // An automatic gallery is left out of the test: it shows its newest photograph rather than one taken at random, and it carries no cover to pick anyway. coverMedia costs no query here, findAllOrdered() joining it
        foreach ($this->galleryCategoryRepository->findAllOrdered() as $category) {
            $cover = $category->getCoverMedia();

            if (!$category->isAutomatic() && (null === $cover || $cover->isDeleted())) {
                return null;
            }
        }

        return [GalleryBlockCacheInvalidator::CACHE_TAG_GALLERIES];
    }

    // Two cases decline the entry: a gallery drawn at random, which a cached entry would freeze into one single draw, and the gallery of the last additions, for the reason above. Any other gallery is a list of rows, and every way of changing one drops the tag
    /**
     * @return string[]|null
     */
    private function resolveMedias(Block $block): ?array
    {
        $data = $block->getData();

        if (true === ($data['random'] ?? false)) {
            return null;
        }

        return $this->isLatest((string) ($data['categorySlug'] ?? '')) ? null : [GalleryBlockCacheInvalidator::CACHE_TAG_GALLERIES];
    }

    // Read off the very list GalleryBlockExtension resolves its own slugs against, memoized for the request (see GalleryCategoryRepository::findAllOrdered): a findOneBySlug() here would be one more query per gallery block on a page carrying several
    private function isLatest(string $slug): bool
    {
        if ('' === $slug) {
            return false;
        }

        foreach ($this->galleryCategoryRepository->findAllOrdered() as $category) {
            if ($category->getSlug() === $slug) {
                return GalleryCategory::AUTOMATIC_LATEST === $category->getAutomaticKind();
            }
        }

        return false;
    }
}
