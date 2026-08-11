<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

// Public front-office viewer - the categories are the gallery, so the index lists them all
// The first segment is ConfigBundle's "gallery-route-prefix" entry: it is carried as a route parameter and checked at each request by GalleryRoutePrefix (the routes' condition), so a site can rename it from the dashboard - "galerie", "fotos" - and the change applies straight away, no cache to clear. GalleryRoutePrefixListener feeds the same value to the generator, so nothing has to pass {gallery_prefix} to path()
class GalleryController extends AbstractController
{
    private const PREFIX_CONDITION = "service('" . GalleryRoutePrefix::ALIAS . "').matches(params['" . GalleryRoutePrefix::PARAMETER . "'])";

    public function __construct(
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryMediaRepository $mediaRepository,
    ) {
    }

    // INDEX
    #[Route('/{gallery_prefix}', name: 'gallery_index', methods: ['GET'], condition: self::PREFIX_CONDITION)]
    public function index(): Response
    {
        $categories = $this->categoryRepository->findAllOrdered();

        // The breadcrumb counts the categories next to its home label, as it counts the medias next to a category - taken from the list already read, so no query of its own
        return $this->render('@c975LGallery/gallery/index.html.twig', [
            'categories' => $categories,
            'categoriesCount' => count($categories),
        ]);
    }

    // CATEGORY
    #[Route('/{gallery_prefix}/{category}', name: 'gallery_category', methods: ['GET'], condition: self::PREFIX_CONDITION)]
    public function category(string $category): Response
    {
        $category = $this->resolveCategory($category);

        // The breadcrumb's home link carries the same count as on the index, counted here rather than listed, the page having no use for the categories themselves
        return $this->render('@c975LGallery/gallery/category.html.twig', [
            'category' => $category,
            'categoriesCount' => $this->categoryRepository->count([]),
            'medias' => $this->mediaRepository->findByCategory($category),
        ]);
    }

    // MEDIA - the stored (medium) file, the only media page there is: the high resolution opens over it, in a lightbox, rather than on a page of its own
    // Reached by slug rather than by id: the url is what an image search shows under the result, and a title says there what a number said nothing of (see GalleryMediaSlugger)
    #[Route('/{gallery_prefix}/{category}/{slug}', name: 'gallery_media', methods: ['GET'], condition: self::PREFIX_CONDITION)]
    public function media(string $category, string $slug): Response
    {
        [$category, $media] = $this->resolveCategoryAndMedia($category, $slug);

        return $this->render('@c975LGallery/gallery/media.html.twig', [
            'category' => $category,
            'categoriesCount' => $this->categoryRepository->count([]),
            'media' => $media,
            'previousNext' => $this->mediaRepository->findPreviousAndNext($media),
        ]);
    }

    private function resolveCategory(string $slug): GalleryCategory
    {
        $category = $this->categoryRepository->findOneBySlug($slug);

        if (null === $category) {
            throw new NotFoundHttpException('Gallery category not found');
        }

        return $category;
    }

    // Both segments are matched at once, the media's slug only being unique within its category (see GalleryMediaSlugger) - which is also what keeps a media from being browsed under an arbitrary category slug
    private function resolveCategoryAndMedia(string $categorySlug, string $slug): array
    {
        $category = $this->resolveCategory($categorySlug);
        $media = $this->mediaRepository->findOneBySlugInCategory($category, $slug);

        if (!$media instanceof GalleryMedia) {
            throw new NotFoundHttpException('Gallery media not found');
        }

        return [$category, $media];
    }
}
