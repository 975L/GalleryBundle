<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Management\LinkableRouteProvider;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class LinkableRouteProviderTest extends TestCase
{
    // The id is what a category entry is keyed on and has no setter, so it is written straight through reflection
    private function createCategory(int $id, string $slug, string $title): GalleryCategory
    {
        $category = new GalleryCategory()->setSlug($slug)->setTitle($title);
        new \ReflectionProperty(GalleryCategory::class, 'id')->setValue($category, $id);

        return $category;
    }

    private function createProvider(array $categories): LinkableRouteProvider
    {
        $repository = $this->createStub(GalleryCategoryRepository::class);
        $repository->method('findAllOrdered')->willReturn($categories);

        // Answers "label.gallery" with "Galerie", the way the gallery domain does in French
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Galerie');

        return new LinkableRouteProvider($repository, $translator);
    }

    // The index takes no parameter, so its key is the route name itself and its label a translation key
    public function testTheIndexIsOfferedWithoutACategory(): void
    {
        $routes = $this->createProvider([])->getLinkableRoutes();

        $this->assertSame(['gallery_index' => ['label' => 'label.gallery', 'translation_domain' => 'gallery']], $routes);
    }

    // A category is a gallery of its own: keyed on its id, labelled with its own title, and reached through the route parameter its slug fills
    public function testEachCategoryIsOfferedAsItsOwnTarget(): void
    {
        $routes = $this->createProvider([
            $this->createCategory(12, 'paysages', 'Paysages'),
            $this->createCategory(7, 'portraits', 'Portraits'),
        ])->getLinkableRoutes();

        $this->assertSame([
            'label' => 'Paysages',
            'translation_domain' => false,
            'picker_label' => 'Galerie - Paysages',
            'route' => 'gallery_category',
            'params' => ['category' => 'paysages'],
        ], $routes['gallery_category.12']);
        $this->assertSame(['category' => 'portraits'], $routes['gallery_category.7']['params']);
    }

    // The back office's select holds these among every page of the site, where a bare "Paysages" says nothing of what it is - the navbar item keeps that bare title
    public function testACategoryIsPrefixedInThePickerOnly(): void
    {
        $routes = $this->createProvider([$this->createCategory(12, 'paysages', 'Paysages')])->getLinkableRoutes();

        $this->assertSame('Galerie - Paysages', $routes['gallery_category.12']['picker_label']);
        $this->assertSame('Paysages', $routes['gallery_category.12']['label']);
    }

    // Keyed on the id rather than on the slug: a menu item saved before a category was renamed keeps pointing at it, the new slug being read here at each render
    public function testACategoryKeyDoesNotDependOnItsSlug(): void
    {
        $routes = $this->createProvider([$this->createCategory(12, 'paysages-de-montagne', 'Paysages de montagne')])->getLinkableRoutes();

        $this->assertArrayHasKey(LinkableRouteProvider::CATEGORY_PREFIX . '12', $routes);
    }
}
