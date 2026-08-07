<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Form\GalleryMediaBatchUploadType;
use c975L\GalleryBundle\Model\GalleryMediaBatch;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Service\GalleryMediaFactory;
use c975L\GalleryBundle\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class GalleryMediaUploadController extends AbstractController
{
    // Used by GalleryCategoryCrudController's per-category "add medias" action (NEW is disabled on the media CRUD - medias are only ever created here, in bulk, for the category the link carries)
    public const UPLOAD_ROUTE = 'management_gallery_media_upload';

    public function __construct(
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryMediaFactory $galleryMediaFactory,
        private readonly UploadLimits $uploadLimits,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // Deliberately not mounted under /gallery/: the category CRUD sits there, and its own /gallery/{entityId} route is declared first, so /gallery/upload would be read as a category id
    #[AdminRoute(path: '/gallery-upload', name: 'gallery_media_upload', options: ['methods' => ['GET', 'POST']])]
    public function upload(Request $request): Response
    {
        // Same role as the CRUDs this screen is reached from (see GalleryCategoryCrudController)
        $this->denyAccessUnlessGranted((string) $this->configService->get('site-role-editor'));

        // No category, no upload: the screen has no picker of its own, so an url reached without one has nothing to attach the medias to
        $category = $this->categoryRepository->find($request->query->getInt('category'));
        if (!$category instanceof GalleryCategory) {
            throw $this->createNotFoundException('Gallery category not found');
        }

        // A batch php threw away for being over post_max_size, which arrives as an empty POST (see UploadLimits::isTruncatedRequest)
        if ($this->uploadLimits->isTruncatedRequest($request)) {
            // Translated here rather than left as a key for the flash to resolve: the dashboard renders flashes in its own domain, not this bundle's
            $this->addFlash('danger', $this->translator->trans('label.gallery_upload_batch_refused', [], 'gallery'));

            return $this->redirect($request->getUri());
        }

        $form = $this->createForm(GalleryMediaBatchUploadType::class, [], ['category_title' => (string) $category->getTitle()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $medias = $this->galleryMediaFactory->createFromUploads(
                $category,
                $data['files'],
                new GalleryMediaBatch(
                    $data['titleRoot'] ?? null,
                    $data['credits'] ?? null,
                    $data['rightsReserved'] ?? false,
                    $data['keepOriginals'] ?? false,
                    $data['watermark'] ?? false,
                    $data['watermarkPosition'] ?? null,
                ),
            );

            foreach ($medias as $media) {
                $this->entityManager->persist($media);
            }
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('label.gallery_medias_uploaded', [], 'gallery'));

            // Back to the category just filled, whose edit screen is where its medias are listed
            $url = $this->adminUrlGenerator
                ->setController(GalleryCategoryCrudController::class)
                ->setAction(Action::EDIT)
                ->setEntityId($category->getId())
                ->generateUrl()
            ;

            return $this->redirect($url);
        }

        return $this->render('@c975LGallery/management/gallery_media_upload.html.twig', [
            'form' => $form,
            'category' => $category,
        ]);
    }
}
