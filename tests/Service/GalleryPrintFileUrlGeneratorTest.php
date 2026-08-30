<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Service\GalleryPrintFileUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The link handed to a lab. It is public by necessity - a lab pulls from its own servers - so the signature and the expiry are the whole of what keeps the untouched original off the open web
class GalleryPrintFileUrlGeneratorTest extends TestCase
{
    private function generator(UriSigner $uriSigner): GalleryPrintFileUrlGenerator
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.org/gallery-print-file/42');

        return new GalleryPrintFileUrlGenerator($urlGenerator, $uriSigner);
    }

    public function testTheUrlHandedToALabIsSignedAndAccepted(): void
    {
        $uriSigner = new UriSigner('secret');
        $url = $this->generator($uriSigner)->generate($this->copy(42));

        $this->assertNotNull($url);
        $this->assertTrue($uriSigner->check($url));
    }

    // Signed for a while and not forever: a link found in an old log is worth nothing
    public function testTheUrlCarriesAnExpiry(): void
    {
        $url = $this->generator(new UriSigner('secret'))->generate($this->copy(42));

        $this->assertStringContainsString('_expiration=', (string) $url);
    }

    // A signature made under another secret is not this site's, whatever it looks like
    public function testAnUrlSignedElsewhereIsRefused(): void
    {
        $url = $this->generator(new UriSigner('secret'))->generate($this->copy(42));

        $this->assertFalse(new UriSigner('another-secret')->check((string) $url));
    }

    // A copy never flushed names no file, so there is nothing to sign
    public function testACopyWithoutAnIdIsGivenNoUrl(): void
    {
        $this->assertNull($this->generator(new UriSigner('secret'))->generate(new GalleryPrintCopy()));
    }

    private function copy(int $id): GalleryPrintCopy
    {
        $copy = new GalleryPrintCopy();
        $property = new \ReflectionProperty(GalleryPrintCopy::class, 'id');
        $property->setValue($copy, $id);

        return $copy;
    }
}
