<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Controller;

use c975L\GalleryBundle\Controller\PrintFileController;
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Service\GalleryPrintFileBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The one place the untouched original leaves this site. Nobody browses here: the url is built for one copy, signed and expiring, and everything else is told there is nothing at all
class PrintFileControllerTest extends TestCase
{
    private string $path;
    private UriSigner $uriSigner;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/gallery-print-file-' . uniqid() . '.jpg';
        file_put_contents($this->path, 'the composed print');
        $this->uriSigner = new UriSigner('secret');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testALabAskingWithTheSignatureThisSitePutOnTheUrlGetsTheFile(): void
    {
        $response = $this->createController($this->path)->serve($this->signedRequest(), new GalleryPrintCopy());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->path, $response->getFile()->getPathname());
    }

    // Handed over as an attachment, a lab downloading the file rather than being shown it
    public function testTheFileIsHandedOverAsADownload(): void
    {
        $response = $this->createController($this->path)->serve($this->signedRequest(), new GalleryPrintCopy());

        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    // 404 and not 403: there is nothing to tell whoever is asking, including that the copy exists
    public function testAnUnsignedRequestIsNotFoundRatherThanForbidden(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->createController($this->path)->serve(Request::create('https://example.org/gallery-print-file/42'), new GalleryPrintCopy());
    }

    // A signature made under another secret is not this site's, whatever it looks like
    public function testAnUrlSignedElsewhereIsRefused(): void
    {
        $url = new UriSigner('another-secret')->sign('https://example.org/gallery-print-file/42');

        $this->expectException(NotFoundHttpException::class);

        $this->createController($this->path)->serve(Request::create($url), new GalleryPrintCopy());
    }

    public function testAnExpiredUrlIsRefused(): void
    {
        $url = $this->uriSigner->sign('https://example.org/gallery-print-file/42', new \DateTimeImmutable('-1 hour'));

        $this->expectException(NotFoundHttpException::class);

        $this->createController($this->path)->serve(Request::create($url), new GalleryPrintCopy());
    }

    // The original was deleted or the format withdrawn since the order was placed: the lab is told there is nothing here, rather than being sent something else to print
    public function testACopyWhoseFileCannotBeComposedIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->createController(null)->serve($this->signedRequest(), new GalleryPrintCopy());
    }

    private function createController(?string $path): PrintFileController
    {
        $fileBuilder = $this->createStub(GalleryPrintFileBuilder::class);
        $fileBuilder->method('build')->willReturn($path);

        return new PrintFileController($fileBuilder, $this->uriSigner);
    }

    private function signedRequest(): Request
    {
        return Request::create($this->uriSigner->sign('https://example.org/gallery-print-file/42', new \DateTimeImmutable('+7 days')));
    }
}
