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
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Controller\Management\GalleryCategoryCrudController;
use c975L\GalleryBundle\Controller\Management\GalleryPrintFormatCrudController;
use c975L\GalleryBundle\Controller\Management\GalleryPrintOrderCrudController;

// One entry for the whole feature: it opens the categories, which are the site's galleries, each holding its own medias and videos (see GalleryCategoryCrudController) - the media CRUD edits one media at a time and has nothing to list on its own
class MenuProvider implements MenuProviderInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getMenuSection(): array
    {
        return [
            'label' => 'label.management',
            'translation_domain' => 'site',
        ];
    }

    public function getMenus(): array
    {
        $menus = [
            'gallery' => [
                'controller' => GalleryCategoryCrudController::class,
                'label' => 'label.gallery',
                'narration' => 'narration.gallery',
                'translation_domain' => 'gallery',
                'icon' => 'fas fa-images',
                // The very text the categories screen opens on (see gallery_category_index.html.twig), reused as-is for the onboarding tour rather than written again for it
                'description' => 'label.info_gallery_category',
                // The bar GalleryCategoryCrudController sets on its own index (see its roleNeeded()) - a gallery is content like any other
                'role' => $this->configService->get('site-role-editor'),
            ],
        ];

        // The two print screens only exist for a site that sells prints. Hidden and not disabled: a menu entry that opens on an empty feature is a question an admin has to answer every time they read the menu
        if (true === $this->configService->get('gallery-print-enabled')) {
            $menus['gallery_print_order'] = [
                'controller' => GalleryPrintOrderCrudController::class,
                'label' => 'label.print_orders',
                'narration' => 'narration.print_orders',
                'translation_domain' => 'gallery',
                'icon' => 'fas fa-print',
                'description' => 'label.info_print_orders',
                'role' => $this->configService->get('site-role-editor'),
            ];

            $menus['gallery_print_format'] = [
                'controller' => GalleryPrintFormatCrudController::class,
                'label' => 'label.print_formats',
                'narration' => 'narration.print_formats',
                'translation_domain' => 'gallery',
                'icon' => 'fas fa-ruler-combined',
                'description' => 'label.info_print_formats',
                'role' => $this->configService->get('site-role-editor'),
            ];
        }

        return $menus;
    }

    public function getLinks(): array
    {
        return [];
    }
}
