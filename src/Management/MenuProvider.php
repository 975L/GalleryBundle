<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\GalleryBundle\Controller\Management\GalleryPhotoCrudController;

// Only the photo CRUD is listed - category management (GalleryCategoryCrudController) is reachable from its toolbar instead of getting its own sidebar entry, so the two screens read as one linked feature rather than two unrelated ones
class MenuProvider implements MenuProviderInterface
{
    public function getMenuSection(): array
    {
        return [
            'label' => 'label.management',
            'translation_domain' => 'site',
        ];
    }

    public function getMenus(): array
    {
        return [
            'gallery_photo' => [
                'controller' => GalleryPhotoCrudController::class,
                'label' => 'label.gallery_photos',
                'translation_domain' => 'gallery',
                'icon' => 'fas fa-images',
            ],
        ];
    }

    public function getLinks(): array
    {
        return [];
    }
}
