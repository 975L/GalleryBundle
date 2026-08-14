<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\UrlMetadataProviderInterface;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;

// Lists the gallery index in "Descriptions d'urls" (c975l:url-metadata:sync), the one page of the bundle no entity speaks for - a category and a media each say their own from their columns, and a row written for them would never be read (see UrlMetadataResolver)
class GalleryUrlMetadataProvider implements UrlMetadataProviderInterface
{
    public function __construct(
        // The same service the routes match on, so a site renaming its prefix from the dashboard - "photos", "galerie" - gets that url declared and not the default one
        private readonly GalleryRoutePrefix $routePrefix,
    ) {
    }

    // The prefix is read at sync time, not at build time: a row already written keeps its text, and a renamed prefix simply adds the new url and reports the former one as orphaned, for the back office to remove by hand
    public function getUrlMetadataPaths(): array
    {
        return ['/' . $this->routePrefix->get()];
    }
}
