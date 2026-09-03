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
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Service\GalleryBlockCacheInvalidator;
use c975L\GalleryBundle\Service\GalleryBlockCacheTagProvider;
use c975L\GalleryBundle\Service\GalleryLatestProvider;
use c975L\UiBundle\Entity\Block;
use PHPUnit\Framework\TestCase;

// What lets this bundle's two kinds be cached at all: the tag their entry carries, and the cases where caching is declined - a draw an entry would freeze, and a list moving on its own as the days go by
class GalleryBlockCacheTagProviderTest extends TestCase
{
    public function testBothKindsOfTheBundleHaveTheirResolver(): void
    {
        $this->assertSame(['gallery_categories', 'gallery_medias'], array_keys($this->resolvers()));
    }

    public function testAGalleryIsCachedUnderTheTagItsRowsDrop(): void
    {
        $resolvers = $this->resolvers();

        $this->assertSame([GalleryBlockCacheInvalidator::CACHE_TAG_GALLERIES], $resolvers['gallery_categories'](new Block()));
        $this->assertSame([GalleryBlockCacheInvalidator::CACHE_TAG_GALLERIES], $resolvers['gallery_medias']($this->block(['categorySlug' => 'bois'])));
    }

    // A cached entry would freeze the draw until a photograph is saved, which is the opposite of what asking for a random order says
    public function testAGalleryDrawnAtRandomDeclinesItsCacheEntry(): void
    {
        $resolvers = $this->resolvers();

        $this->assertNull($resolvers['gallery_medias']($this->block(['categorySlug' => 'bois', 'random' => true])));
        $this->assertNotNull($resolvers['gallery_medias']($this->block(['categorySlug' => 'bois', 'random' => false])));
    }

    // Its photographs are those of the last days: they leave it as the days go by, with nothing saved to drop the entry on
    public function testTheGalleryOfTheLastAdditionsDeclinesItsCacheEntry(): void
    {
        $resolvers = $this->resolvers(true, $this->category('dernieres-photos', GalleryCategory::AUTOMATIC_LATEST));

        $this->assertNull($resolvers['gallery_medias']($this->block(['categorySlug' => 'dernieres-photos'])));
    }

    // The gallery of the prints is automatic too, and holds what is flagged as being on sale: a list of rows, dropped like any other
    public function testTheGalleryOfThePrintsIsCachedLikeAnyOther(): void
    {
        $resolvers = $this->resolvers(false, $this->category('tirages', GalleryCategory::AUTOMATIC_PRINTABLE));

        $this->assertSame([GalleryBlockCacheInvalidator::CACHE_TAG_GALLERIES], $resolvers['gallery_medias']($this->block(['categorySlug' => 'tirages'])));
    }

    // The listing draws each gallery with a thumbnail taken from what it holds, the one of the last additions included
    public function testTheListingDeclinesItsEntryWhileTheLastAdditionsAreOffered(): void
    {
        $this->assertNull($this->resolvers(true)['gallery_categories'](new Block()));
    }

    // A gallery nobody picked a cover for draws its tile at random on each render, which a cached entry would freeze into one single draw
    public function testTheListingDeclinesItsEntryWhileAGalleryHasNoCover(): void
    {
        $resolvers = $this->resolvers(false, $this->category('bois'));

        $this->assertNull($resolvers['gallery_categories'](new Block()));
    }

    public function testTheListingIsCachedOnceEveryGalleryHasItsCover(): void
    {
        $resolvers = $this->resolvers(false, $this->category('bois')->setCoverMedia(new GalleryMedia()));

        $this->assertSame([GalleryBlockCacheInvalidator::CACHE_TAG_GALLERIES], $resolvers['gallery_categories'](new Block()));
    }

    // An automatic gallery shows its newest photograph rather than one taken at random, and carries no cover to pick
    public function testAnAutomaticGalleryWithoutACoverLeavesTheListingCached(): void
    {
        $resolvers = $this->resolvers(false, $this->category('tirages', GalleryCategory::AUTOMATIC_PRINTABLE));

        $this->assertSame([GalleryBlockCacheInvalidator::CACHE_TAG_GALLERIES], $resolvers['gallery_categories'](new Block()));
    }

    private function resolvers(bool $latestAvailable = false, ?GalleryCategory $category = null): array
    {
        $repository = $this->createStub(GalleryCategoryRepository::class);
        $repository->method('findAllOrdered')->willReturn(null === $category ? [] : [$category]);

        $latest = $this->createStub(GalleryLatestProvider::class);
        $latest->method('isAvailable')->willReturn($latestAvailable);

        return new GalleryBlockCacheTagProvider($repository, $latest)->getCacheTagResolvers();
    }

    private function category(string $slug, ?string $automaticKind = null): GalleryCategory
    {
        $category = new GalleryCategory();
        $category->setSlug($slug);
        $category->setAutomaticKind($automaticKind);

        return $category;
    }

    private function block(array $data): Block
    {
        $block = new Block();
        $block->setData($data);

        return $block;
    }
}
