<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\UiBundle\Contract\PdfGeneratorInterface;
use Endroid\QrCode\Builder\Builder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The certificate of authenticity - the sheet that is signed by hand and posted, and the page anyone can check it
 * against.
 *
 * Two objects for one claim. The paper is what the buyer keeps and what carries a signature; the page is what makes the
 * claim verifiable by somebody who was not there - a buyer reselling the print in ten years, a gallery asked to take it.
 * Neither is the register: the register is the rows, and both of these only read them.
 *
 * Uses the ecosystem's own generator (see PaymentBundle's InvoiceService, which renders invoices the same way), so a
 * site that has themed its invoices has themed its certificates.
 */
class GalleryCertificateService
{
    public function __construct(
        private readonly PdfGeneratorInterface $pdfGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    // The sheet to print, sign and slip into an envelope. Null for an open edition, which has nothing to certify - a print without a number is not an original in any sense the word carries
    public function render(GalleryPrintCopy $copy): ?string
    {
        return $this->renderMany([$copy]);
    }

    /**
     * Every certificate of an order in one document, a page each.
     *
     * One file and not one download per copy: an order of three numbered prints is three sheets to sign in the same
     * sitting, and asking for them one at a time is how one gets forgotten in the envelope.
     *
     * @param list<GalleryPrintCopy> $copies
     */
    public function renderMany(array $copies): ?string
    {
        $numbered = [];

        foreach ($copies as $copy) {
            if (null !== $copy->getNumber() && null !== $copy->getCertificate()) {
                $url = $this->getVerificationUrl($copy);

                // The copy alone, no photograph: everything the sheet states was frozen onto the row at the sale (see PrintCopySnapshot), and reading the entity again is exactly what would let a retitled photograph contradict a certificate already signed
                $numbered[] = [
                    'copy' => $copy,
                    'verificationUrl' => $url,
                    'qrCode' => null === $url ? null : $this->getQrCode($url),
                ];
            }
        }

        if ([] === $numbered) {
            return null;
        }

        return $this->pdfGenerator->render('@c975LGallery/print/certificate.html.twig', ['certificates' => $numbered]);
    }

    /**
     * The verification address drawn as a qr code, inlined as a data uri.
     *
     * Inlined rather than linked: the certificate is rendered outside any request the pdf engine could follow, and a
     * sheet whose image failed to load is a sheet that goes out with a hole in it. It sits next to the address written
     * in full, which is what stays readable when the code is scuffed or the scanner is a human being with a keyboard.
     */
    private function getQrCode(string $url): string
    {
        return new Builder()->build(data: $url, size: 220, margin: 6)->getDataUri();
    }

    // What the qr code printed on the certificate points at, and the only address at which a number can be checked
    public function getVerificationUrl(GalleryPrintCopy $copy): ?string
    {
        $token = $copy->getCertificate();

        return null === $token ? null : $this->urlGenerator->generate(
            'gallery_print_certificate',
            ['certificate' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    // What the file is called when it is downloaded - the photograph and the rank, so a folder of certificates sorts itself. Read off the live photograph on purpose, unlike everything the sheet states: this names a download an admin is about to open, not the document
    public function getFilename(GalleryPrintCopy $copy): string
    {
        return sprintf('certificat-%s-%d-%d.pdf', $copy->getMedia()?->getSlug() ?? 'tirage', (int) $copy->getNumber(), (int) $copy->getMedia()?->getEditionSize());
    }
}
