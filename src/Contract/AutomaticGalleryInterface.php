<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Contract;

use c975L\GalleryBundle\Entity\GalleryMedia;

/**
 * One kind of automatic gallery: a category that holds no media of its own and is handed a list at read time.
 *
 * Only the list is asked for here. Everything a category needs around it - being written the first time it is
 * looked for, taking its place in the index, being handed to a tile, giving a media its neighbours - is the same
 * whatever the kind, and belongs to GalleryAutomaticProvider rather than to each of these.
 *
 * A site gathering its photographs on some rule of its own writes a class implementing this and tags it. It then
 * has a gallery of its own on the index, in the menus and in the sitemap, without a single screen learning of it.
 */
interface AutomaticGalleryInterface
{
    // The kind this gallery answers to, stored on the category it owns (see GalleryCategory::AUTOMATIC_LATEST)
    public function getKind(): string;

    // Whether this site wants that gallery at all - asked before the category is created, so a feature nobody turned on never leaves a row behind
    public function isAvailable(): bool;

    /** @return list<GalleryMedia> */
    public function getMedias(): array;
}
