<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use c975L\GalleryBundle\Service\GallerySampleCatalog;
use c975L\GalleryBundle\Service\GalleryShowcaseProvider;
use c975L\UiBundle\Registry\PlaceholderMediaRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        $this->provider(['medias/one.webp', 'medias/two.webp'], $this->recordingTwig($rendered))->getShowcases();

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

    // The photograph the site declares for that very media (see PlaceholderMediaProviderInterface's "keyed_images"), which is also the one a demo site is seeded with, rather than one of the rotated pool
    public function testGetShowcasesPrefersThePhotographDeclaredForTheMedia(): void
    {
        $rendered = [];
        $keyed = [
            'gallery/cretes-au-matin' => ['showcase/gallery/cretes-au-matin-1.webp'],
            'gallery/lac-gele' => ['showcase/gallery/lac-gele-1.webp'],
        ];
        $this->provider(['medias/one.webp'], $this->recordingTwig($rendered), $keyed)->getShowcases();

        $medias = $rendered['@c975LGallery/components/Gallery/Medias.html.twig']['medias'];
        $this->assertSame('showcase/gallery/cretes-au-matin-1.webp', $medias[0]['thumbnailFilename']);
        $this->assertSame('showcase/gallery/lac-gele-1.webp', $medias[1]['thumbnailFilename']);
        // Declared for neither, so back to the rotated pool
        $this->assertSame('medias/one.webp', $medias[2]['thumbnailFilename']);

        // The categories grid shows each category's cover, and reads the same declarations
        $categories = $rendered['@c975LGallery/components/Gallery/Categories.html.twig']['categories'];
        $this->assertSame('showcase/gallery/cretes-au-matin-1.webp', $categories[0]['coverOrRandomMedia']['thumbnailFilename']);
    }

    // The stand-ins' categories and medias only exist in the demonstration site, so that is where their thumbnails lead
    public function testGetShowcasesLinksTheStandInsToTheDemonstrationSite(): void
    {
        $rendered = [];
        $this->provider(['medias/one.webp'], $this->recordingTwig($rendered))->getShowcases();

        $categories = $rendered['@c975LGallery/components/Gallery/Categories.html.twig']['categories'];
        $this->assertSame('/demo/gallery/paysages', $categories[0]['url']);

        $medias = $rendered['@c975LGallery/components/Gallery/Medias.html.twig']['medias'];
        $this->assertSame('/demo/gallery/paysages/cretes-au-matin', $medias[0]['url']);
    }

    // The prefix is the demonstration's own, not this site's: it is edited in each back office (ConfigBundle's "gallery-route-prefix"), and a site having renamed it would send the visitor to a segment the demonstration does not answer on
    public function testGetShowcasesLinksThroughTheDefaultGalleryPrefix(): void
    {
        $rendered = [];
        $this->provider(['medias/one.webp'], $this->recordingTwig($rendered))->getShowcases();

        $categories = $rendered['@c975LGallery/components/Gallery/Categories.html.twig']['categories'];
        $this->assertSame('/demo/' . GalleryRoutePrefix::DEFAULT . '/paysages', $categories[0]['url']);
    }

    // No demonstration named, no link: Image:Link renders a <span> rather than sending a visitor to a category this site does not hold
    public function testGetShowcasesLeavesTheStandInsUnlinkedWithoutADemonstrationSite(): void
    {
        $rendered = [];
        $this->provider(['medias/one.webp'], $this->recordingTwig($rendered), [], null)->getShowcases();

        $categories = $rendered['@c975LGallery/components/Gallery/Categories.html.twig']['categories'];
        $this->assertSame('', $categories[0]['url']);
        $this->assertSame('', $rendered['@c975LGallery/components/Gallery/Medias.html.twig']['medias'][0]['url']);
    }

    /**
     * @param array<string, array<string, mixed>> $rendered
     */
    private function recordingTwig(array &$rendered): Environment
    {
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $context = []) use (&$rendered): string {
            $rendered[$template] = $context;

            return '<rendered/>';
        });

        return $twig;
    }

    /**
     * @param list<string>                $images
     * @param array<string, list<string>> $keyedImages
     */
    private function provider(array $images, ?Environment $twig = null, array $keyedImages = [], ?string $demoUrl = '/demo/'): GalleryShowcaseProvider
    {
        if (null === $twig) {
            $twig = $this->createStub(Environment::class);
            $twig->method('render')->willReturn('<rendered/>');
        }

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $registry = $this->createStub(PlaceholderMediaRegistry::class);
        $registry->method('getImages')->willReturn($images);
        $registry->method('getImagesFor')->willReturnCallback(static fn (string $key): array => $keyedImages[$key] ?? []);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key): mixed => 'ui-showcase-demo-url' === $key ? $demoUrl : null);

        // The site's own generator, whose paths the demonstration's address is prefixed to - it lays the gallery prefix down as it was handed, the way the router does for a {gallery_prefix} it was given explicitly
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route, array $parameters = []): string => 'gallery_category' === $route
            ? '/' . $parameters['gallery_prefix'] . '/' . $parameters['category']
            : '/' . $parameters['gallery_prefix'] . '/' . $parameters['category'] . '/' . $parameters['slug']);

        return new GalleryShowcaseProvider($twig, $translator, $registry, new GallerySampleCatalog($registry), $configService, $urlGenerator);
    }
}
