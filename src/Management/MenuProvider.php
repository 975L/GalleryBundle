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
use c975L\GalleryBundle\Controller\Management\GalleryMediaCrudController;
use c975L\GalleryBundle\Controller\Management\GalleryPrintFormatCrudController;
use c975L\GalleryBundle\Controller\Management\GalleryPrintOrderCrudController;

// Four entries: the galleries, which is where a gallery is composed and its own medias are arranged (see GalleryCategoryCrudController), the whole library read across them all - the contact sheet a triage pass works from, which no single gallery's screen can be (see GalleryMediaCrudController) - then the print orders and the print catalogue
class MenuProvider implements MenuProviderInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getMenuSection(): array
    {
        return [
            'label' => 'label.gallery',
            'translation_domain' => 'gallery',
            'icon' => 'fas fa-camera',
        ];
    }

    public function getMenus(): array
    {
        return [
            'gallery' => [
                'controller' => GalleryCategoryCrudController::class,
                'label' => 'label.gallery_categories',
                'narration' => 'narration.gallery',
                'translation_domain' => 'gallery',
                'icon' => 'fas fa-images',
                // The very text the categories screen opens on (see gallery_category_index.html.twig), reused as-is for the onboarding tour rather than written again for it
                'description' => 'label.info_gallery_category',
                // The bar GalleryCategoryCrudController sets on its own index (see its roleNeeded()) - a gallery is content like any other
                'role' => $this->configService->get('site-role-editor'),
            ],
            // Every gallery's photographs in one grid, filtered and searched - what is on sale, what is masked, what visitors liked, read across the whole library rather than one gallery at a time
            'gallery_media' => [
                'controller' => GalleryMediaCrudController::class,
                'label' => 'label.gallery_medias',
                'narration' => 'narration.gallery_medias',
                'translation_domain' => 'gallery',
                'icon' => 'fas fa-photo-film',
                'description' => 'label.info_gallery_medias',
                // The same bar the galleries entry sits behind, both screens stating it themselves (see GalleryMediaCrudController::roleNeeded)
                'role' => $this->configService->get('site-role-editor'),
            ],
            // Listed whether or not the sale is open: the catalogue is written and the orders rehearsed before the shop opens, and the screens say themselves when it is still closed (see _gallery_print_sale_notice.html.twig)
            'gallery_print_order' => [
                'controller' => GalleryPrintOrderCrudController::class,
                // Lists what happened rather than what an admin makes: empty, it is no feature left unused (see UnusedFeatureBuilder)
                'creatable' => false,
                'label' => 'label.print_orders',
                'narration' => 'narration.print_orders',
                'translation_domain' => 'gallery',
                'icon' => 'fas fa-print',
                'description' => 'label.info_print_orders',
                'role' => $this->configService->get('site-role-editor'),
            ],
            'gallery_print_format' => [
                'controller' => GalleryPrintFormatCrudController::class,
                'label' => 'label.print_formats',
                'narration' => 'narration.print_formats',
                'translation_domain' => 'gallery',
                'icon' => 'fas fa-ruler-combined',
                'description' => 'label.info_print_formats',
                'role' => $this->configService->get('site-role-editor'),
            ],
        ];
    }

    public function getLinks(): array
    {
        return [];
    }
}
