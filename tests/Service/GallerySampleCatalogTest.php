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
use c975L\UiBundle\Registry\PlaceholderMediaRegistry;
use PHPUnit\Framework\TestCase;

// The one dataset the showcase renders and a demo site is seeded with - what holds here is what both of them get
class GallerySampleCatalogTest extends TestCase
{
    private GallerySampleCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new GallerySampleCatalog($this->createStub(PlaceholderMediaRegistry::class));
    }

    /** @return list<array<string, mixed>> */
    private function medias(): array
    {
        return array_merge(...array_column($this->catalog->getCategories(), 'medias'));
    }

    // A category slug is a unique column, and a media slug is half of where its file lands on disk
    public function testCategoryAndMediaSlugsAreUnique(): void
    {
        $categories = array_column($this->catalog->getCategories(), 'slug');
        $medias = array_column($this->medias(), 'slug');

        $this->assertSame(array_unique($categories), $categories);
        $this->assertSame(array_unique($medias), $medias);
    }

    // The showcase reads three categories, and four medias of the first: a thinner catalog leaves one of its two kinds half drawn
    public function testTheCatalogIsWideEnoughForTheShowcase(): void
    {
        $categories = $this->catalog->getCategories();

        $this->assertGreaterThanOrEqual(3, count($categories));
        $this->assertGreaterThanOrEqual(4, count($categories[0]['medias']));
    }

    // Read through the "gallery" domain rather than written as sentences, so a demo site seeded in Spanish reads as a Spanish gallery
    public function testEveryVisibleTextIsATranslationKey(): void
    {
        $this->assertStringStartsWith('label.', GallerySampleCatalog::CREDITS_KEY);

        foreach ($this->catalog->getCategories() as $category) {
            $this->assertStringStartsWith('label.', $category['title'], $category['slug']);
        }

        foreach ($this->medias() as $media) {
            $this->assertStringStartsWith('label.', $media['title'], $media['slug']);
        }
    }

    // A gallery of one photograph per category says nothing of what a gallery looks like
    public function testEveryCategoryCarriesSeveralMedias(): void
    {
        foreach ($this->catalog->getCategories() as $category) {
            $this->assertGreaterThan(1, count($category['medias']), $category['slug']);
        }
    }

    // The photograph the site declares for that very media, so the showcase and the demo site show the same one under the same title
    public function testThePhotographDeclaredForTheMediaWins(): void
    {
        $catalog = $this->catalogDeclaring(['gallery/lac-gele' => ['showcase/gallery/lac-gele-1.webp', 'showcase/gallery/lac-gele-2.webp']]);

        // One media is one photograph: the second declared file has nothing to be
        $this->assertSame('showcase/gallery/lac-gele-1.webp', $catalog->photograph('lac-gele', ['pool/one.webp'], 0));
    }

    // Nothing declared for that slug, so back to the generic pool, rotated over the medias
    public function testAnUndeclaredMediaFallsBackOnTheRotatedPool(): void
    {
        $catalog = $this->catalogDeclaring([]);
        $pool = ['pool/one.webp', 'pool/two.webp'];

        $this->assertSame('pool/one.webp', $catalog->photograph('lac-gele', $pool, 0));
        $this->assertSame('pool/two.webp', $catalog->photograph('cretes-au-matin', $pool, 1));
        $this->assertSame('pool/one.webp', $catalog->photograph('brume-sur-le-lac', $pool, 2));
    }

    /**
     * @param array<string, list<string>> $keyedImages
     */
    private function catalogDeclaring(array $keyedImages): GallerySampleCatalog
    {
        $registry = $this->createStub(PlaceholderMediaRegistry::class);
        $registry->method('getImagesFor')->willReturnCallback(static fn (string $key): array => $keyedImages[$key] ?? []);

        return new GallerySampleCatalog($registry);
    }
}
