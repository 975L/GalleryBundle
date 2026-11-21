<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Service\GalleryCategoryUrls;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

class GalleryCategoryUrlsTest extends TestCase
{
    // A title or a typed slug gives a lowercased slug
    public function testSlug(): void
    {
        $this->assertSame('catalog-2', $this->urls($this->createStub(GalleryUrlRedirector::class))->slug('Catalog 2 !'));
    }

    // A gallery's url and the wildcard below it are both released
    public function testRelease(): void
    {
        $released = [];
        $redirector = $this->createMock(GalleryUrlRedirector::class);
        $redirector->expects($this->exactly(2))->method('release')->willReturnCallback(static function (EntityManagerInterface $entityManager, string $path) use (&$released): void {
            $released[] = $path;
        });

        $this->urls($redirector)->release($this->createStub(EntityManagerInterface::class), new GalleryCategory()->setSlug('catalog'));
        $this->assertSame(['/gallery/catalog', '/gallery/catalog/*'], $released);
    }

    // A rename redirects the old url and every media url below it to the new one
    public function testRedirectRename(): void
    {
        $recorded = [];
        $redirector = $this->createMock(GalleryUrlRedirector::class);
        $redirector->expects($this->exactly(2))->method('record')->willReturnCallback(static function (EntityManagerInterface $entityManager, string $fromPath, string $toUrl) use (&$recorded): void {
            $recorded[] = [$fromPath, $toUrl];
        });

        $this->urls($redirector)->redirectRename($this->createStub(EntityManagerInterface::class), 'catalog', 'catalog-2');
        $this->assertSame([['/gallery/catalog', '/gallery/catalog-2'], ['/gallery/catalog/*', '/gallery/catalog-2']], $recorded);
    }

    // A slug left unchanged records no row, its wildcard would redirect every live media url
    public function testRedirectRenameKeepsAnUnchangedSlug(): void
    {
        $redirector = $this->createMock(GalleryUrlRedirector::class);
        $redirector->expects($this->never())->method('record');

        $this->urls($redirector)->redirectRename($this->createStub(EntityManagerInterface::class), 'catalog', 'catalog');
    }

    // The service over a generator answering the category's path
    private function urls(GalleryUrlRedirector $redirector): GalleryCategoryUrls
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => '/gallery/' . $parameters['category']);

        return new GalleryCategoryUrls($redirector, $urlGenerator, new AsciiSlugger());
    }
}
