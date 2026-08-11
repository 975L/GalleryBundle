<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Twig\Extension;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Twig\Extension\GalleryBlockExtension;
use PHPUnit\Framework\TestCase;

class GalleryBlockExtensionTest extends TestCase
{
    public function testGetFunctionsExposesTheTwoBlockFunctions(): void
    {
        $names = array_map(
            static fn ($function): string => $function->getName(),
            $this->extension()->getFunctions()
        );

        $this->assertSame(['gallery_block_categories', 'gallery_block_medias'], $names);
    }

    public function testGetCategoriesReturnsEveryCategoryInOrder(): void
    {
        $categories = $this->extension(null, [], [
            new GalleryCategory()->setSlug('one'),
            new GalleryCategory()->setSlug('two'),
        ])->getCategories();

        $this->assertCount(2, $categories);
        $this->assertSame('one', $categories[0]->getSlug());
    }

    public function testGetCategoriesCapsTheListAtMax(): void
    {
        $extension = $this->extension(null, [], [
            new GalleryCategory()->setSlug('one'),
            new GalleryCategory()->setSlug('two'),
        ]);

        $this->assertCount(1, $extension->getCategories(1));
    }

    // A site that has never created a category still renders the block, empty
    public function testGetCategoriesReturnsNoneWithoutAnyCategory(): void
    {
        $this->assertSame([], $this->extension()->getCategories());
    }

    public function testGetMediasReturnsTheCategoryAndItsMedias(): void
    {
        $category = new GalleryCategory()->setSlug('summer');
        $medias = [new GalleryMedia(), new GalleryMedia()];

        $result = $this->extension($category, $medias)->getMedias('summer');

        $this->assertSame($category, $result['category']);
        $this->assertCount(2, $result['medias']);
    }

    public function testGetMediasCapsTheMediasAtMax(): void
    {
        $category = new GalleryCategory()->setSlug('summer');
        $medias = [new GalleryMedia(), new GalleryMedia()];

        $result = $this->extension($category, $medias)->getMedias('summer', 1);

        $this->assertCount(1, $result['medias']);
    }

    // The draw covers the whole category, not only the medias the maximum would have kept in the stored order
    public function testGetMediasDrawsAtRandomFromTheWholeCategory(): void
    {
        $category = new GalleryCategory()->setSlug('summer');
        $medias = array_map(static fn (int $number): GalleryMedia => new GalleryMedia()->setTitle((string) $number), range(1, 20));
        $extension = $this->extension($category, $medias);

        $drawn = [];
        for ($i = 0; $i < 30; ++$i) {
            $result = $extension->getMedias('summer', 1, true);
            $this->assertCount(1, $result['medias']);
            $drawn[(string) $result['medias'][0]->getTitle()] = true;
        }

        $this->assertGreaterThan(1, \count($drawn));
    }

    public function testGetMediasKeepsTheStoredOrderWithoutRandom(): void
    {
        $category = new GalleryCategory()->setSlug('summer');
        $medias = [new GalleryMedia()->setTitle('first'), new GalleryMedia()->setTitle('second')];

        $result = $this->extension($category, $medias)->getMedias('summer');

        $this->assertSame(['first', 'second'], array_map(static fn (GalleryMedia $media): ?string => $media->getTitle(), $result['medias']));
    }

    // What a block pointing at a category renamed or deleted since returns - its template then renders nothing at all
    public function testGetMediasReturnsNoCategoryWhenTheSlugNoLongerResolves(): void
    {
        $result = $this->extension()->getMedias('gone');

        $this->assertNull($result['category']);
        $this->assertSame([], $result['medias']);
    }

    public function testGetMediasReturnsNoCategoryWithoutASlug(): void
    {
        $result = $this->extension()->getMedias(null);

        $this->assertNull($result['category']);
    }

    // What several gallery blocks on the same page cost: one read of the category list for the whole page, not one per block
    public function testTheCategoryListIsReadOnceForTheWholeRequest(): void
    {
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('findAllOrdered')->willReturn([
            new GalleryCategory()->setSlug('photos'),
            new GalleryCategory()->setSlug('videos'),
        ]);
        $extension = new GalleryBlockExtension($categoryRepository, $this->createStub(GalleryMediaRepository::class));

        $this->assertSame('photos', $extension->getMedias('photos')['category']?->getSlug());
        $this->assertSame('videos', $extension->getMedias('videos')['category']?->getSlug());
        $extension->getCategories();
    }

    // Under a worker runtime the extension outlives the request, so the next one has to see the categories as they are then
    public function testResetMakesTheListBeReadAgain(): void
    {
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->exactly(2))->method('findAllOrdered')->willReturn([]);
        $extension = new GalleryBlockExtension($categoryRepository, $this->createStub(GalleryMediaRepository::class));

        $extension->getCategories();
        $extension->reset();
        $extension->getCategories();
    }

    // The ordered list is what every block resolves its slug against, so a test giving a single category is giving the list that category alone
    private function extension(?GalleryCategory $category = null, array $medias = [], array $categories = []): GalleryBlockExtension
    {
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findAllOrdered')->willReturn([] !== $categories ? $categories : array_values(array_filter([$category])));

        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findByCategory')->willReturn($medias);

        return new GalleryBlockExtension($categoryRepository, $mediaRepository);
    }
}
