<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\ConfigBundle\Service\LocalizedUrlGenerator;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use c975L\UiBundle\Contract\InternalLinkLocalizerInterface;

// Rewrites this gallery's own links into the language the page around them is being read in - the counterpart of SiteBundle's PageLinkLocalizer, for the three screens this bundle owns. Without it a visitor reading "/en/" is sent back into the writing language at the first click on a tile
class GalleryLinkLocalizer implements InternalLinkLocalizerInterface
{
    public function __construct(
        private readonly GalleryRoutePrefix $routePrefix,
        private readonly LocalizedUrlGenerator $localizedUrlGenerator,
    ) {
    }

    public function localize(string $value): string
    {
        // A whole rich text: only what an href holds is a link, the same words elsewhere in the prose being prose
        if (str_contains($value, 'href="')) {
            return (string) preg_replace_callback(
                '#href="([^"]*)"#',
                fn (array $matches): string => sprintf('href="%s"', $this->localizePath($matches[1])),
                $value
            );
        }

        return $this->localizePath($value);
    }

    // The first segment is an entry an editor writes ("galerie", "fotos"), so the pattern is built at each call rather than written as a constant - a site naming its own has to have its own links rewritten (see GalleryRoutePrefix)
    private function localizePath(string $path): string
    {
        $prefix = $this->routePrefix->get();

        // A gallery this site does not serve here: its routes match nothing, and an url pointing at them would be a dead link
        if ('' === $prefix) {
            return $path;
        }

        $segment = preg_quote($prefix, '#');

        // The three screens, longest first: a media url is a category url with one more segment, and the shorter pattern would swallow it
        if (1 === preg_match(sprintf('#^/%s/(?<category>[a-zA-Z0-9\-]+)/(?<slug>[a-zA-Z0-9\-]+)$#', $segment), $path, $matches)) {
            return $this->localizedUrlGenerator->path('gallery_media', ['category' => $matches['category'], 'slug' => $matches['slug']]);
        }

        if (1 === preg_match(sprintf('#^/%s/(?<category>[a-zA-Z0-9\-]+)$#', $segment), $path, $matches)) {
            return $this->localizedUrlGenerator->path('gallery_category', ['category' => $matches['category']]);
        }

        if (1 === preg_match(sprintf('#^/%s$#', $segment), $path)) {
            return $this->localizedUrlGenerator->path('gallery_index');
        }

        return $path;
    }
}
