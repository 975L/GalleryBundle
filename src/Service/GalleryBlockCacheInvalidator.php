<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use Symfony\Contracts\Cache\TagAwareCacheInterface;

// The tag every cached block of this bundle carries, and the one place it is dropped from. UiBundle's BlockCacheInvalidationListener only ever invalidates the changed Block itself, and knows nothing of the galleries those blocks query at render time - the same gap ShopBundle and BookBundle close for their own kinds
class GalleryBlockCacheInvalidator
{
    // One tag for both kinds: a photograph added shows in its gallery and changes the thumbnail the listing of galleries draws it with, so no change of one leaves the other as it was
    public const string CACHE_TAG_GALLERIES = 'gallery_galleries';

    public function __construct(private readonly TagAwareCacheInterface $cache)
    {
    }

    public function invalidateGalleries(): void
    {
        $this->cache->invalidateTags([self::CACHE_TAG_GALLERIES]);
    }
}
