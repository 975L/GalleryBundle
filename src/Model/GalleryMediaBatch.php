<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Model;

// What a whole batch of uploads shares, gathered rather than passed as five positional arguments to GalleryMediaFactory - the two screens that add medias (the upload screen and the category creation form) fill the same one, so a setting added here reaches both
final class GalleryMediaBatch
{
    public function __construct(
        // Seeds the title of every media in the batch, numbered from where the category leaves off ("Cailloux couleur 1", "Cailloux couleur 2"). Left empty, each media falls back to its own filename, which is what a camera names IMG_1234
        public readonly ?string $titleRoot = null,
        public readonly ?string $credits = null,
        public readonly bool $rightsReserved = false,
        // Keeps the untouched upload alongside the derivatives, under GalleryMedia::ORIGINAL_DIRECTORY - see UiBundle's VichOriginalKeepableInterface
        public readonly bool $keepOriginals = false,
        // Stamps the site's signature into a corner of every derivative, the light or the dark one being picked per photo (see UiBundle's VichWatermarkableInterface). The corner left null takes the one set site-wide, a whole gallery rarely wanting its own
        public readonly bool $watermark = false,
        public readonly ?string $watermarkPosition = null,
    ) {
    }
}
