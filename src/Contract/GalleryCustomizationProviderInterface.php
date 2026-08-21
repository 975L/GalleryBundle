<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Contract;

// What a site adds to the galleries the bundle ships, declared once rather than by overriding the CRUD controllers - the fields its own categories and its own medias carry, held in their "data" payload. Implemented by the consuming app, collected through the "gallery.customization_provider" tag
interface GalleryCustomizationProviderInterface
{
    /**
     * A plain form type mapped on GalleryCategory::$data, holding the fields this site adds to a gallery and no other site has - the same way a block declares the form of its own data (see UiBundle's "ui.block" tag). Null for a site adding none.
     *
     * @return class-string|null
     */
    public function getCategoryDataFormType(): ?string;

    /**
     * The same for GalleryMedia::$data, holding what this site says about a photograph that no other site records. A caption is not one of them: every gallery wants one, so it is a column of its own (see GalleryMedia::$description).
     *
     * @return class-string|null
     */
    public function getMediaDataFormType(): ?string;
}
