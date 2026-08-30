<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller;

use c975L\GalleryBundle\Repository\GalleryPrintCopyRepository;
use c975L\GalleryBundle\Service\GalleryCertificateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where a numbered print is checked.
 *
 * Public and deliberately plain: what it says is that this token belongs to this photograph, at this rank of an edition
 * of that size, sold on that date. Nothing about the buyer - a certificate proves a print, it does not publish who owns
 * it.
 *
 * Outside the gallery's configurable prefix, and outside its style. This url is printed on paper: it has to keep
 * working after the dashboard renames "gallery" to "photos", and after the site is redesigned twice.
 */
class CertificateController extends AbstractController
{
    public function __construct(
        private readonly GalleryPrintCopyRepository $copyRepository,
        private readonly GalleryCertificateService $certificateService,
    ) {
    }

    #[Route('/certificate/{certificate}', name: 'gallery_print_certificate', methods: ['GET'])]
    public function certificate(string $certificate): Response
    {
        $copy = $this->copyRepository->findByCertificate($certificate);

        // An unknown token and an unsold number answer the same way: there is nothing here. A certificate that does not resolve is precisely what a forged one looks like, and saying more would help whoever made it
        if (null === $copy || null === $copy->getNumber()) {
            throw new NotFoundHttpException();
        }

        return $this->render('@c975LGallery/print/certificate-check.html.twig', [
            'copy' => $copy,
            'media' => $copy->getMedia(),
            'verificationUrl' => $this->certificateService->getVerificationUrl($copy),
        ]);
    }
}
