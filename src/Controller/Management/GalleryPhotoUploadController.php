<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller\Management;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Form\GalleryPhotoBatchUploadType;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryPhotoRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GalleryPhotoUploadController extends AbstractController
{
    // Used by GalleryPhotoCrudController's index action button (NEW is disabled there - photos are
    // only ever created here, in bulk)
    public const UPLOAD_ROUTE = 'management_gallery_photo_upload';

    public function __construct(
        private readonly GalleryRepository $galleryRepository,
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryPhotoRepository $photoRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    #[AdminRoute(path: '/gallery/upload', name: 'gallery_photo_upload', options: ['methods' => ['GET', 'POST']])]
    public function upload(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $gallery = $this->galleryRepository->findOrCreateDefault();

        // Preselects the category when arriving from "Ajouter des photos" on an already-filtered index
        $preselected = null;
        $categoryId = $request->query->get('category');
        if (null !== $categoryId) {
            $preselected = $this->categoryRepository->find($categoryId);
        }

        $form = $this->createForm(GalleryPhotoBatchUploadType::class, ['category' => $preselected], ['gallery' => $gallery]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $category = $data['category'] ?? null;
            if (!$category instanceof GalleryCategory) {
                $category = $this->categoryRepository->findOrCreateUncategorized($gallery);
            }

            $position = $this->nextPosition($category);
            foreach ($data['photos'] as $photo) {
                if (null === $photo->getFile()) {
                    continue;
                }

                $photo
                    ->setCategory($category)
                    ->setCredits($data['credits'] ?: null)
                    ->setRightsReserved($data['rightsReserved'] ?? false)
                    ->setPosition($position++)
                ;
                $this->entityManager->persist($photo);
            }
            $this->entityManager->flush();

            $this->addFlash('success', 'label.gallery_photos_uploaded');

            $url = $this->adminUrlGenerator
                ->setController(GalleryPhotoCrudController::class)
                ->setAction(Action::INDEX)
                ->set('filters[category][value]', $category->getId())
                ->generateUrl()
            ;

            return $this->redirect($url);
        }

        return $this->render('@c975LGallery/management/gallery_photo_upload.html.twig', [
            'form' => $form,
        ]);
    }

    private function nextPosition(GalleryCategory $category): int
    {
        $positions = array_map(
            static fn (GalleryPhoto $photo): int => $photo->getPosition(),
            $this->photoRepository->findByCategory($category)
        );

        return [] === $positions ? 0 : max($positions) + 1;
    }
}
