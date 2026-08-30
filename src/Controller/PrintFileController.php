<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller;

use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Service\GalleryPrintFileBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands a lab the file it is printing.
 *
 * The one place the untouched original leaves this site, and it leaves it composed - resized to the size ordered and
 * signed at that size (see GalleryPrintFileBuilder). Nobody browses here: the url is built for one copy, signed, and
 * expires, and a request without a valid signature is a 404 rather than a 403 - there is nothing to tell whoever is
 * asking, including that the copy exists.
 *
 * Deliberately outside the gallery's configurable prefix: this is a machine's url, and renaming "gallery" to "photos"
 * in the dashboard must not break the orders a lab is already fetching.
 */
class PrintFileController extends AbstractController
{
    public function __construct(
        private readonly GalleryPrintFileBuilder $fileBuilder,
        private readonly UriSigner $uriSigner,
    ) {
    }

    #[Route('/gallery-print-file/{copy}', name: 'gallery_print_file', methods: ['GET'])]
    public function serve(Request $request, GalleryPrintCopy $copy): BinaryFileResponse
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw new NotFoundHttpException();
        }

        $path = $this->fileBuilder->build($copy);

        // The original was deleted or the format withdrawn since the order was placed - the lab is told there is nothing here, and the order comes back to the admin rather than being printed from something else
        if (null === $path) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('print-%d.jpg', (int) $copy->getId()));

        return $response;
    }
}
