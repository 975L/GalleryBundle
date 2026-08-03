<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\UiBundle\Contract\BundleStylesheetProviderInterface;

class StylesheetProvider implements BundleStylesheetProviderInterface
{
    public function getStylesheets(): array
    {
        return [
            'bundles/c975lgallery/css/styles.min.css',
        ];
        // The gallery's own shape tokens ship commented out in the app's assets/styles/themes/gallery.css, copied there once by the scaffold and owned by the app from then on - colors and fonts stay admin-editable, compiled into bundles/build/site-theme.css by UiBundle
    }
}
