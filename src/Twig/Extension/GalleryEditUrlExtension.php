<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Twig\Extension;

use c975L\GalleryBundle\Controller\Management\GalleryCategoryCrudController;
use c975L\GalleryBundle\Controller\Management\GalleryMediaCrudController;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Points the public pages' "edit" button at what is being looked at - the gallery on a category page, the media on its own - as UiBundle's own hover button does for a block (see BlockFocusUrl). The admin url is generated, never written out, the dashboard deciding where the CRUDs are mounted
// The role deciding who is offered the button is checked by the templates, where it costs no query (see gallery/category.html.twig and gallery/media.html.twig)
class GalleryEditUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gallery_category_edit_url', [$this, 'getCategoryEditUrl']),
            new TwigFunction('gallery_media_edit_url', [$this, 'getMediaEditUrl']),
        ];
    }

    // A category's edit screen is the gallery itself: its heading blocks, its medias, their order and its cover (see GalleryCategoryCrudController)
    public function getCategoryEditUrl(GalleryCategory $category): ?string
    {
        return $this->editUrl(GalleryCategoryCrudController::class, $category->getId());
    }

    // The category is carried along the way every media screen carries it: it is where the media CRUD comes back to after a save, a delete or a cancel (see GalleryMediaCrudController::index())
    public function getMediaEditUrl(GalleryMedia $media): ?string
    {
        return $this->editUrl(GalleryMediaCrudController::class, $media->getId(), ['category' => $media->getCategory()?->getId()]);
    }

    // An entity with no id has no screen to point at - an in-memory one, a fixture preview - and a null parameter is left out rather than generated as an empty one
    // Null too when the URL can't be built at all: EasyAdmin resolves the dashboard it is mounted under through a cache map written only when the route collection is regenerated (see AdminRouteGenerator::saveAdminRoutesInCache()), so that pool being emptied while the compiled routes stay fresh makes every generateUrl() call from a public page throw, and it stays that way until the routes are regenerated. The button is an editor-only convenience - losing it beats taking the page down for the only people able to fix it
    private function editUrl(string $crudControllerFqcn, ?int $entityId, array $parameters = []): ?string
    {
        if (null === $entityId) {
            return null;
        }

        $adminUrl = $this->adminUrlGenerator
            ->unsetAll()
            ->setController($crudControllerFqcn)
            ->setAction(Action::EDIT)
            ->setEntityId($entityId)
        ;

        foreach ($parameters as $name => $value) {
            if (null !== $value) {
                $adminUrl->set($name, $value);
            }
        }

        try {
            return $adminUrl->generateUrl();
        } catch (\Throwable) {
            return null;
        }
    }
}
