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
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\UiBundle\Repository\RatingRepository;

// How many visitors liked each of the photographs a back-office grid draws - the badge under the thumbnail, on a category's own grid and on the whole library's contact sheet alike (see _gallery_media_tile.html.twig), asked in one query for the whole grid and from one place, both grids asking the same question and never answering it twice differently
class GalleryMediaLikeCounter
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly RatingRepository $ratingRepository,
    ) {
    }

    // Keyed by media id, ids nobody liked being absent - the tile reads a missing one as no like at all
    /**
     * @param list<GalleryMedia> $medias
     *
     * @return array<int, int>
     */
    public function count(array $medias): array
    {
        // Nothing counted on a site that shows no heart: the badge would be reading a feature its visitors are not offered (see gallery/media.html.twig, which asks the same entry)
        if (!$this->configService->getBool($this->configService->get('gallery-rating'))) {
            return [];
        }

        $ids = array_values(array_filter(array_map(
            static fn (GalleryMedia $media): ?int => $media->getId(),
            $medias
        )));

        if ([] === $ids) {
            return [];
        }

        // The very owner type the heart under the photograph votes on, and the one a permanent deletion drops its rows by (see GalleryCategoryCrudController::dropRatings)
        return array_map(
            static fn (array $aggregate): int => $aggregate['count'],
            $this->ratingRepository->getAggregates('gallery_media', $ids)
        );
    }
}
