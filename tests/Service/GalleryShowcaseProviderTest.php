<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Service\GallerySampleCatalog;
use c975L\GalleryBundle\Service\GalleryShowcaseProvider;
use c975L\UiBundle\Registry\PlaceholderMediaRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class GalleryShowcaseProviderTest extends TestCase
{
    public function testGetShowcasesCoversBothBlockKinds(): void
    {
        $showcases = $this->provider(['medias/sample.webp'])->getShowcases();

        $kinds = array_column($showcases, 'kind');
        $this->assertSame(['gallery_categories', 'gallery_medias'], $kinds);
        foreach ($showcases as $showcase) {
            $this->assertNotSame('', $showcase['description']);
            $this->assertSame(['' => '<rendered/>'], $showcase['variants']);
        }
    }

    // A media grid with no media in it shows nothing at all, so no placeholder means no showcase rather than an empty frame
    public function testGetShowcasesReturnsNoneWithoutAPlaceholderImage(): void
    {
        $this->assertSame([], $this->provider([])->getShowcases());
    }

    public function testGetShowcasesRendersTheComponentsThemselves(): void
    {
        $rendered = [];
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $context = []) use (&$rendered): string {
            $rendered[$template] = $context;

            return '<rendered/>';
        });

        $this->provider(['medias/one.webp', 'medias/two.webp'], $twig)->getShowcases();

        $this->assertArrayHasKey('@c975LGallery/components/Gallery/Categories.html.twig', $rendered);
        $this->assertArrayHasKey('@c975LGallery/components/Gallery/Medias.html.twig', $rendered);
        // Stand-ins are plain arrays, the components reading their attributes the same way as on a real entity
        $medias = $rendered['@c975LGallery/components/Gallery/Medias.html.twig']['medias'];
        // Four, the catalog's own count rather than the site's number of placeholders, which are rotated over them
        $this->assertCount(4, $medias);
        $this->assertSame('medias/one.webp', $medias[0]['thumbnailFilename']);
        $this->assertSame('medias/two.webp', $medias[1]['thumbnailFilename']);
        $this->assertSame('medias/one.webp', $medias[2]['thumbnailFilename']);
        $this->assertFalse($medias[0]['video']);
    }

    private function provider(array $images, ?Environment $twig = null): GalleryShowcaseProvider
    {
        if (null === $twig) {
            $twig = $this->createStub(Environment::class);
            $twig->method('render')->willReturn('<rendered/>');
        }

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $registry = $this->createStub(PlaceholderMediaRegistry::class);
        $registry->method('getImages')->willReturn($images);

        return new GalleryShowcaseProvider($twig, $translator, $registry, new GallerySampleCatalog());
    }
}
