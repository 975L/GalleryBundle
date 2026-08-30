<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Controller;

use c975L\GalleryBundle\Controller\CertificateController;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Repository\GalleryPrintCopyRepository;
use c975L\GalleryBundle\Service\GalleryCertificateService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

// Where a numbered print is checked - the page the address printed on a certificate leads to. Only 'twig' is ever fetched, so a bare Container is enough and no kernel boot is needed
class CertificateControllerTest extends TestCase
{
    public function testASoldCopyIsCheckedAgainstTheRegister(): void
    {
        $copy = new GalleryPrintCopy()->setNumber(3)->setMedia(new GalleryMedia());

        $response = $this->createController($copy)->certificate('a-token');

        $this->assertSame(200, $response->getStatusCode());
    }

    // A token that does not resolve is exactly what a forged certificate looks like, and saying more would help whoever made it
    public function testAnUnknownTokenIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->createController(null)->certificate('forged');
    }

    // A row of an announced edition that nobody has bought yet carries no number, and there is nothing to certify
    public function testACopyNobodyHasBoughtYetIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->createController(new GalleryPrintCopy())->certificate('a-token');
    }

    // The photograph may have been deleted for good since the sale, the register outliving it: the certificate still checks out
    public function testACopyWhosePhotographIsGoneStillChecksOut(): void
    {
        $copy = new GalleryPrintCopy()->setNumber(3);

        $this->assertSame(200, $this->createController($copy)->certificate('a-token')->getStatusCode());
    }

    private function createController(?GalleryPrintCopy $copy): CertificateController
    {
        $copyRepository = $this->createStub(GalleryPrintCopyRepository::class);
        $copyRepository->method('findByCertificate')->willReturn($copy);

        $certificateService = $this->createStub(GalleryCertificateService::class);
        $certificateService->method('getVerificationUrl')->willReturn('https://example.org/certificate/a-token');

        $controller = new CertificateController($copyRepository, $certificateService);

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(static fn (string $view): string => $view);

        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }
}
