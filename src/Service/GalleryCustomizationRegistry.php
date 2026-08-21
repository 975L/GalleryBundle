<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Contract\GalleryCustomizationProviderInterface;

// Reads what the site declares about its own galleries. The first provider answering wins, as a site declares one - the iterator exists so an app running several bundles of its own is not forced into a single class
class GalleryCustomizationRegistry
{
    /** @param iterable<GalleryCustomizationProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    // The form of the fields a site adds to a gallery, null when it adds none
    /** @return class-string|null */
    public function getCategoryDataFormType(): ?string
    {
        foreach ($this->providers as $provider) {
            $formType = $provider->getCategoryDataFormType();

            if (null !== $formType) {
                return $formType;
            }
        }

        return null;
    }

    // The same for a media
    /** @return class-string|null */
    public function getMediaDataFormType(): ?string
    {
        foreach ($this->providers as $provider) {
            $formType = $provider->getMediaDataFormType();

            if (null !== $formType) {
                return $formType;
            }
        }

        return null;
    }
}
