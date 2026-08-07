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
use c975L\GalleryBundle\Controller\Management\GalleryCategoryCrudController;

// One entry for the whole feature: it opens the categories, which are the site's galleries, each holding its own medias and videos (see GalleryCategoryCrudController) - the media CRUD edits one media at a time and has nothing to list on its own
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
            'gallery' => [
                'controller' => GalleryCategoryCrudController::class,
                'label' => 'label.gallery',
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
