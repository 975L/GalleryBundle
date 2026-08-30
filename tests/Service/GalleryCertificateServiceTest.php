<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Service\GalleryCertificateService;
use c975L\UiBundle\Contract\PdfGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The sheet that is signed by hand and posted. What is drawn and what is refused matters as much as the drawing: a certificate for a print that is not numbered would certify nothing
class GalleryCertificateServiceTest extends TestCase
{
    public function testANumberedCopyIsDrawn(): void
    {
        $this->assertSame('the-pdf', $this->service()->render($this->sold(3, 'a-token')));
    }

    // An open edition has nothing to certify - a print without a number is not an original in any sense the word carries
    public function testAnOpenEditionIsNotDrawn(): void
    {
        $this->assertNull($this->service()->render(new GalleryPrintCopy()));
    }

    public function testACopyWithoutATokenIsNotDrawn(): void
    {
        $this->assertNull($this->service()->render(new GalleryPrintCopy()->setNumber(3)));
    }

    // One file and not one download per copy: three numbered prints are three sheets to sign in the same sitting
    public function testAWholeOrderIsDrawnAsOneDocument(): void
    {
        $service = $this->service();

        $this->assertSame('the-pdf', $service->renderMany([$this->sold(1, 'one'), $this->sold(2, 'two')]));
    }

    // An order of open editions alone: nothing to sign, so no empty document is handed to the admin
    public function testAnOrderCarryingNothingNumberedIsNotDrawn(): void
    {
        $this->assertNull($this->service()->renderMany([new GalleryPrintCopy(), new GalleryPrintCopy()]));
    }

    public function testTheVerificationUrlIsBuiltOnTheCopysOwnToken(): void
    {
        $this->assertSame('https://example.org/certificate/a-token', $this->service()->getVerificationUrl($this->sold(3, 'a-token')));
    }

    public function testACopyWithoutATokenHasNoAddressToCheckItAt(): void
    {
        $this->assertNull($this->service()->getVerificationUrl(new GalleryPrintCopy()));
    }

    // The download's name is read off the live photograph, unlike everything the sheet states: it names a file an admin is about to open, not the document
    public function testTheDownloadIsNamedAfterThePhotographAndItsRank(): void
    {
        $copy = $this->sold(3, 'a-token')->setMedia(new GalleryMedia()->setSlug('aiguille-du-midi')->setEditionSize(30));

        $this->assertSame('certificat-aiguille-du-midi-3-30.pdf', $this->service()->getFilename($copy));
    }

    // The photograph may be gone for good, the register outliving it: the download still gets a name
    public function testADownloadOfACopyWhosePhotographIsGoneIsStillNamed(): void
    {
        $this->assertSame('certificat-tirage-3-0.pdf', $this->service()->getFilename($this->sold(3, 'a-token')));
    }

    private function service(): GalleryCertificateService
    {
        $pdfGenerator = $this->createStub(PdfGeneratorInterface::class);
        $pdfGenerator->method('render')->willReturn('the-pdf');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters): string => 'https://example.org/certificate/' . $parameters['certificate'],
        );

        return new GalleryCertificateService($pdfGenerator, $urlGenerator);
    }

    private function sold(int $number, string $token): GalleryPrintCopy
    {
        return new GalleryPrintCopy()->setNumber($number)->setCertificate($token);
    }
}
