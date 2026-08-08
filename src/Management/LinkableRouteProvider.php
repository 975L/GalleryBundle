<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\LinkableRouteProviderInterface;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

// Exposes the public viewer as SiteBundle Menu targets (navbar/footer): the index listing every gallery, and one entry per category - the categories being the site's galleries, a menu item usually points straight at one of them
// Nothing is stored but the target itself: the url is generated at render time (see MenuExtension), so renaming the route prefix in the dashboard or a category's slug in the CRUD leaves no menu item behind
class LinkableRouteProvider implements LinkableRouteProviderInterface
{
    // What a category entry is keyed on, its id following - the menu item stores it as "route:gallery_category.12"
    public const CATEGORY_PREFIX = 'gallery_category.';

    public function __construct(
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getLinkableRoutes(): array
    {
        $routes = [
            'gallery_index' => [
                'label' => 'label.gallery',
                'translation_domain' => 'gallery',
            ],
        ];

        $gallery = $this->translator->trans('label.gallery', [], 'gallery');

        // Every category the index itself lists, in the order an admin arranged them (the picker sorts them alphabetically among the pages anyway)
        // Keyed by id rather than by slug: a renamed category keeps the menu item pointing at it, its slug and its title both being read here again at each render
        foreach ($this->categoryRepository->findAllOrdered() as $category) {
            $routes[self::CATEGORY_PREFIX . $category->getId()] = [
                // The title is the admin's own, not a key to translate - shown as it is in the rendered menu, where "Galerie - " would only take room in a navbar
                'label' => (string) $category->getTitle(),
                'translation_domain' => false,
                // The picker holds it among every page of the site, so it says what it is there, and the gallery entries sit together once the list is sorted
                'picker_label' => $gallery . ' - ' . $category->getTitle(),
                'route' => 'gallery_category',
                'params' => ['category' => (string) $category->getSlug()],
            ];
        }

        return $routes;
    }
}
