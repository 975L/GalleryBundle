<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\ConfigBundle\Service\SiteLocales;

// Which languages each screen of the gallery really answers in - the one thing every localised url of this bundle is gated on (see LocalizedRouteNegotiator). The whole answer lives here rather than in the controller, so translating the gallery is a change to this file and to nothing else
class GalleryTranslatedLocales
{
    public function __construct(private readonly SiteLocales $siteLocales)
    {
    }

    // Every language the site declares, translated or not: "/en" is the language the gallery is read in, not a claim about each row - what a row really says is GalleryTranslator::translatedLocales()
    /** @return list<string> */
    public function all(): array
    {
        return $this->siteLocales->all();
    }
}
