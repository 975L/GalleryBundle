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
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryPhotoRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

// Public front-office viewer, default gallery only - a second, non-default Gallery would need its own slug-prefixed route set (see GalleryRepository::findDefault()), deliberately left out until a site actually needs more than one
class GalleryController extends AbstractController
{
    public function __construct(
        private readonly GalleryRepository $galleryRepository,
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryPhotoRepository $photoRepository,
    ) {
    }

    // INDEX
    #[Route('/photos', name: 'gallery_index', methods: ['GET'])]
    public function index(): Response
    {
        $gallery = $this->galleryRepository->findDefault();

        return $this->render('@c975LGallery/gallery/index.html.twig', [
            'categories' => null !== $gallery ? $gallery->getCategories() : [],
        ]);
    }

    // CATEGORY
    #[Route('/photos/{category}', name: 'gallery_category', methods: ['GET'])]
    public function category(string $category): Response
    {
        $category = $this->resolveCategory($category);

        return $this->render('@c975LGallery/gallery/category.html.twig', [
            'category' => $category,
            'photos' => $this->photoRepository->findByCategory($category),
        ]);
    }

    // PHOTO - medium resolution (the stored file itself)
    #[Route('/photos/{category}/{id<\d+>}', name: 'gallery_photo', methods: ['GET'])]
    public function photo(string $category, int $id): Response
    {
        [$category, $photo] = $this->resolveCategoryAndPhoto($category, $id);

        return $this->render('@c975LGallery/gallery/photo.html.twig', [
            'category' => $category,
            'photo' => $photo,
            'previousNext' => $this->photoRepository->findPreviousAndNext($photo),
        ]);
    }

    // PHOTO - high resolution. A video entry has no high resolution of its own (its still is only ever the grid thumbnail), so the url doesn't exist for one rather than serving a blown-up poster
    #[Route('/photos/{category}/{id<\d+>}/hr', name: 'gallery_photo_hr', methods: ['GET'])]
    public function photoHr(string $category, int $id): Response
    {
        [$category, $photo] = $this->resolveCategoryAndPhoto($category, $id);

        if ($photo->isVideo()) {
            throw new NotFoundHttpException('Gallery photo not found');
        }

        return $this->render('@c975LGallery/gallery/photo_hr.html.twig', [
            'category' => $category,
            'photo' => $photo,
            'previousNext' => $this->photoRepository->findPreviousAndNext($photo),
        ]);
    }

    private function resolveCategory(string $slug): GalleryCategory
    {
        $gallery = $this->galleryRepository->findDefault();
        $category = null !== $gallery ? $this->categoryRepository->findOneBySlug($gallery, $slug) : null;

        if (null === $category) {
            throw new NotFoundHttpException('Gallery category not found');
        }

        return $category;
    }

    // Resolves both from the URL and checks the photo actually belongs to that category, so an id can't be browsed under an arbitrary category slug
    private function resolveCategoryAndPhoto(string $categorySlug, int $id): array
    {
        $category = $this->resolveCategory($categorySlug);
        $photo = $this->photoRepository->find($id);

        if (!$photo instanceof GalleryPhoto || $photo->getCategory() !== $category) {
            throw new NotFoundHttpException('Gallery photo not found');
        }

        return [$category, $photo];
    }
}
