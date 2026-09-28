<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Template;

use c975L\UiBundle\Service\JsonLdBuilder;
use c975L\UiBundle\Twig\JsonLdExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

// The trail the navigation prints is the one a search engine reads: same levels, without the counts that label the links
class GalleryBreadcrumbJsonLdTest extends TestCase
{
    public function testAMediaPagePublishesTheThreeLevelsItPrints(): void
    {
        $payload = $this->payloadOf($this->render(['category' => ['title' => 'Montagne', 'slug' => 'montagne', 'mediasCount' => 12], 'current' => 'Lac d\'Annecy']));

        $this->assertSame('BreadcrumbList', $payload['@type']);
        $this->assertSame(
            [['label.gallery_home', 'https://example.test/gallery_index'], ['Montagne', 'https://example.test/gallery_category/montagne'], ['Lac d\'Annecy', 'https://example.test/galerie/montagne/lac']],
            array_map(static fn (array $level): array => [$level['name'], $level['item']], $payload['itemListElement']),
        );
    }

    public function testACategoryPageStopsAtItself(): void
    {
        $payload = $this->payloadOf($this->render(['category' => ['title' => 'Montagne', 'slug' => 'montagne', 'mediasCount' => 12]]));

        $this->assertCount(2, $payload['itemListElement']);
        $this->assertSame('Montagne', $payload['itemListElement'][1]['name']);
    }

    // The index is the page itself, and a trail of one level says nothing
    public function testTheIndexPublishesNoTrail(): void
    {
        $this->assertStringNotContainsString('application/ld+json', $this->render(['categoriesCount' => 3]));
    }

    private function payloadOf(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $matches), 'No BreadcrumbList was published.');

        return json_decode($matches[1], true, 512, \JSON_THROW_ON_ERROR);
    }

    private function render(array $context): string
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'));
        $twig->addExtension(new AttributeExtension(JsonLdExtension::class));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([JsonLdExtension::class => static fn (): JsonLdExtension => new JsonLdExtension(new JsonLdBuilder())]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('localized_path', static fn (string $route, array $parameters = []): string => '/' . $route . ([] === $parameters ? '' : '/' . implode('/', $parameters))));
        $twig->addFunction(new TwigFunction('absolute_url', static fn (string $path): string => 'https://example.test' . $path));
        $twig->addGlobal('app', ['request' => ['pathinfo' => '/galerie/montagne/lac']]);

        return $twig->render('components/Gallery/Navigation.html.twig', $context);
    }
}
