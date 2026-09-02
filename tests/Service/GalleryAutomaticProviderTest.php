<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Contract\AutomaticGalleryInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Service\GalleryAutomaticProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

class GalleryAutomaticProviderTest extends TestCase
{
    /** @param list<AutomaticGalleryInterface> $galleries */
    private function createProvider(
        array $galleries = [],
        ?GalleryCategoryRepository $categoryRepository = null,
        ?GalleryMediaRepository $mediaRepository = null,
    ): GalleryAutomaticProvider {
        return new GalleryAutomaticProvider(
            $galleries,
            $categoryRepository ?? $this->createStub(GalleryCategoryRepository::class),
            $mediaRepository ?? $this->createStub(GalleryMediaRepository::class),
        );
    }

    /** @param list<GalleryMedia> $medias */
    private function createGallery(string $kind, array $medias = [], bool $available = true): AutomaticGalleryInterface
    {
        $gallery = $this->createStub(AutomaticGalleryInterface::class);
        $gallery->method('getKind')->willReturn($kind);
        $gallery->method('isAvailable')->willReturn($available);
        $gallery->method('getMedias')->willReturn($medias);

        return $gallery;
    }

    private function category(int $id): GalleryCategory
    {
        $category = new GalleryCategory();
        new \ReflectionProperty(GalleryCategory::class, 'id')->setValue($category, $id);

        return $category;
    }

    // One call for the two kinds of category, which is the whole reason every screen goes through it: none of them has to know which of the two it is rendering
    public function testTheMediasOfAnOrdinaryGalleryAreReadOffTheCategoryItself(): void
    {
        $category = new GalleryCategory();
        $medias = [new GalleryMedia()];

        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->once())->method('findVisibleByCategory')->with($category)->willReturn($medias);

        $this->assertSame($medias, $this->createProvider(mediaRepository: $mediaRepository)->getMedias($category));
    }

    public function testTheMediasOfAnAutomaticGalleryComeFromTheGalleryOfItsKind(): void
    {
        $medias = [new GalleryMedia(), new GalleryMedia()];
        $category = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_PRINTABLE);

        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->never())->method('findVisibleByCategory');

        $provider = $this->createProvider(
            [$this->createGallery(GalleryCategory::AUTOMATIC_LATEST), $this->createGallery(GalleryCategory::AUTOMATIC_PRINTABLE, $medias)],
            mediaRepository: $mediaRepository,
        );

        $this->assertSame($medias, $provider->getMedias($category));
    }

    // A category flagged with a kind no provider answers is rendered as the ordinary, empty gallery it has become - never with a fatal error on a public page
    public function testAKindNoGalleryAnswersFallsBackOnTheCategoryItself(): void
    {
        $category = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_PRINTABLE);

        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->once())->method('findVisibleByCategory')->with($category)->willReturn([]);

        $this->assertSame([], $this->createProvider(mediaRepository: $mediaRepository)->getMedias($category));
    }

    // Nobody creates an automatic gallery: it is written the first time it is asked for, and read back every time after
    public function testTheCategoryIsWrittenForAnInstalledKind(): void
    {
        $category = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())
            ->method('findOrCreateAutomatic')
            ->with(GalleryCategory::AUTOMATIC_LATEST)
            ->willReturn($category)
        ;

        $provider = $this->createProvider([$this->createGallery(GalleryCategory::AUTOMATIC_LATEST)], $categoryRepository);

        $this->assertSame($category, $provider->ensureCategory(GalleryCategory::AUTOMATIC_LATEST));
    }

    // A site that never opened the shop must not grow a gallery of prints, and an unknown kind writes nothing at all
    public function testNothingIsWrittenForAGalleryTheSiteDoesNotWant(): void
    {
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->never())->method('findOrCreateAutomatic');

        $provider = $this->createProvider([$this->createGallery(GalleryCategory::AUTOMATIC_PRINTABLE, available: false)], $categoryRepository);

        $this->assertNull($provider->ensureCategory(GalleryCategory::AUTOMATIC_PRINTABLE));
        $this->assertNull($provider->ensureCategory(GalleryCategory::AUTOMATIC_LATEST));
    }

    public function testEveryInstalledKindIsWrittenAtOnce(): void
    {
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->exactly(2))->method('findOrCreateAutomatic')->willReturn(new GalleryCategory());

        $this->createProvider([
            $this->createGallery(GalleryCategory::AUTOMATIC_LATEST),
            $this->createGallery(GalleryCategory::AUTOMATIC_PRINTABLE),
        ], $categoryRepository)->ensureCategories();
    }

    // The list a screen read carries it already: nothing is written, and it is simply handed the medias it shows
    public function testPrepareHandsTheMediasToTheCategoryTheListAlreadyCarries(): void
    {
        $automatic = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->never())->method('findOrCreateAutomatic');

        $provider = $this->createProvider(
            [$this->createGallery(GalleryCategory::AUTOMATIC_LATEST, [new GalleryMedia(), new GalleryMedia()])],
            $categoryRepository,
        );
        $categories = $provider->prepare([new GalleryCategory(), $automatic]);

        $this->assertCount(2, $categories);
        $this->assertSame(2, $automatic->getMediasCount());
    }

    // The very first render, on a site that has never had one: it is written, and the list is read back rather than sorted here, so it comes out in the database's own alphabetical order
    public function testPrepareWritesTheCategoryAndReadsTheListBack(): void
    {
        $automatic = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST)->setTitle('Derniers ajouts');
        $arles = new GalleryCategory()->setTitle('Arles');
        $venise = new GalleryCategory()->setTitle('Venise');
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOrCreateAutomatic')->willReturn($automatic);
        $categoryRepository->method('findAllOrdered')->willReturn([$arles, $automatic, $venise]);

        $categories = $this->createProvider([$this->createGallery(GalleryCategory::AUTOMATIC_LATEST)], $categoryRepository)
            ->prepare([$arles, $venise]);

        $this->assertSame([$arles, $automatic, $venise], $categories);
    }

    // Two kinds installed, and a site that only wants one of them: the other leaves no row and no tile
    public function testPrepareOnlyAddsTheGalleriesTheSiteWants(): void
    {
        $latest = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOrCreateAutomatic')->willReturn($latest);
        $categoryRepository->method('findAllOrdered')->willReturn([$latest]);

        $categories = $this->createProvider([
            $this->createGallery(GalleryCategory::AUTOMATIC_LATEST),
            $this->createGallery(GalleryCategory::AUTOMATIC_PRINTABLE, available: false),
        ], $categoryRepository)->prepare([]);

        $this->assertSame([$latest], $categories);
    }

    // The row outlives the feature: emptying "gallery-latest-days" turns the kind off, and the gallery written back when it was on has to leave the index rather than stay on it holding nothing
    public function testPrepareDropsACategoryWhoseKindIsNoLongerOffered(): void
    {
        $latest = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $ordinary = new GalleryCategory();

        $categories = $this->createProvider([$this->createGallery(GalleryCategory::AUTOMATIC_LATEST, available: false)])
            ->prepare([$ordinary, $latest]);

        $this->assertSame([$ordinary], $categories);
    }

    // A bundle removed takes its kind with it, and the gallery it flagged stays on the site as the ordinary one it has become (see GalleryAutomaticProvider::gallery)
    public function testPrepareKeepsACategoryNoProviderAnswersFor(): void
    {
        $orphan = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);

        $categories = $this->createProvider()->prepare([$orphan]);

        $this->assertSame([$orphan], $categories);
    }

    // Moving it to the trash is how an admin is rid of it, so it must not come back on the site by the very call that would have written it
    public function testPrepareLeavesATrashedCategoryOutOfTheList(): void
    {
        $automatic = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $automatic->setIsDeleted(true);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOrCreateAutomatic')->willReturn($automatic);

        $categories = $this->createProvider([$this->createGallery(GalleryCategory::AUTOMATIC_LATEST)], $categoryRepository)
            ->prepare([new GalleryCategory()]);

        $this->assertCount(1, $categories);
    }

    // Masking it is the other way an admin takes it off the site, and it must not come back either - the list it is missing from is precisely the one that dropped it (see GalleryCategoryRepository::findAllOrdered)
    public function testPrepareLeavesAHiddenCategoryOutOfTheList(): void
    {
        $automatic = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST)->setHidden(true);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOrCreateAutomatic')->willReturn($automatic);

        $categories = $this->createProvider([$this->createGallery(GalleryCategory::AUTOMATIC_LATEST)], $categoryRepository)
            ->prepare([new GalleryCategory()]);

        $this->assertCount(1, $categories);
    }

    // Every listed category draws its tile and prints its count from the same collection, and reading it category by category is a query each on the very page that lists them all
    public function testPrepareReadsTheMediasOfEveryListedCategoryInOneQuery(): void
    {
        $withoutCover = $this->category(1);
        $withCover = $this->category(2)->setCoverMedia(new GalleryMedia());
        $media = new GalleryMedia();

        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->once())
            ->method('findVisibleByCategories')
            ->with([$withoutCover, $withCover])
            ->willReturn([1 => [$media]])
        ;

        $this->createProvider(mediaRepository: $mediaRepository)->prepare([$withoutCover, $withCover]);

        $this->assertSame($media, $withoutCover->getCoverOrRandomMedia());
        $this->assertSame(1, $withoutCover->getMediasCount());
        $this->assertSame(0, $withCover->getMediasCount());
    }

    // A cover an admin put in the trash is no cover: the category falls back on its medias, so it needs the list just as much as one that never had a cover at all
    public function testPrepareAlsoReadsTheMediasOfACategoryWhoseCoverIsTrashed(): void
    {
        $trashedCover = new GalleryMedia();
        $trashedCover->setIsDeleted(true);
        $category = $this->category(1)->setCoverMedia($trashedCover);
        $other = $this->category(2);

        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->once())->method('findVisibleByCategories')->with([$category, $other])->willReturn([]);

        $this->createProvider(mediaRepository: $mediaRepository)->prepare([$category, $other]);

        $this->assertNull($category->getCoverOrRandomMedia());
    }

    // The block showing one gallery goes through prepare() too: reading it ahead saves no query at all, the lazy relation running exactly the one this would
    public function testPrepareAsksNothingAheadForASingleCategory(): void
    {
        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->never())->method('findVisibleByCategories');

        $this->createProvider(mediaRepository: $mediaRepository)->prepare([$this->category(1)]);
    }

    public function testHydrateOnlyFillsTheAutomaticCategories(): void
    {
        $automatic = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $normal = new GalleryCategory();

        $provider = $this->createProvider([
            $this->createGallery(GalleryCategory::AUTOMATIC_LATEST, [new GalleryMedia(), new GalleryMedia()]),
        ]);
        $provider->hydrate([$automatic, $normal]);

        $this->assertSame(2, $automatic->getMediasCount());
        $this->assertSame(0, $normal->getMediasCount());
    }

    // The back-office rows show a thumbnail and a media count, both read from the relation - one query per row without this
    public function testHydrateReadsTheMediasOfTheRowsItIsHandedInOneQuery(): void
    {
        $automatic = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $first = $this->category(1);
        $second = $this->category(2);
        $media = new GalleryMedia();

        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->once())
            ->method('findVisibleByCategories')
            ->with([$first, $second])
            ->willReturn([2 => [$media]])
        ;

        $this->createProvider([$this->createGallery(GalleryCategory::AUTOMATIC_LATEST)], mediaRepository: $mediaRepository)
            ->hydrate([$automatic, $first, $second]);

        $this->assertSame(0, $first->getMediasCount());
        $this->assertSame($media, $second->getCoverOrRandomMedia());
    }

    // A photo opened from an automatic gallery is browsed among its medias: its neighbours are the ones it stands between there, not the ones filed next to it
    public function testFindPreviousAndNextWalksTheGalleryItIsBrowsedFrom(): void
    {
        $medias = [new GalleryMedia(), new GalleryMedia(), new GalleryMedia()];
        $category = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $provider = $this->createProvider([$this->createGallery(GalleryCategory::AUTOMATIC_LATEST, $medias)]);

        $result = $provider->findPreviousAndNext($medias[1], $category);

        $this->assertSame($medias[0], $result['previous']);
        $this->assertSame($medias[2], $result['next']);
    }

    // Circular like a category's own navigation, so walking an automatic gallery never dead-ends
    public function testFindPreviousAndNextWrapsAroundAtBothEnds(): void
    {
        $medias = [new GalleryMedia(), new GalleryMedia()];
        $category = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $provider = $this->createProvider([$this->createGallery(GalleryCategory::AUTOMATIC_LATEST, $medias)]);

        $this->assertSame($medias[1], $provider->findPreviousAndNext($medias[0], $category)['previous']);
        $this->assertSame($medias[0], $provider->findPreviousAndNext($medias[1], $category)['next']);
    }

    // A media that has since left the list is not walked among them at all - the page falls back on its own category's navigation, which is where it will still be tomorrow
    public function testFindPreviousAndNextReturnsNothingForAMediaOutsideTheList(): void
    {
        $category = new GalleryCategory()->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $provider = $this->createProvider([$this->createGallery(GalleryCategory::AUTOMATIC_LATEST, [new GalleryMedia()])]);

        $this->assertNull($provider->findPreviousAndNext(new GalleryMedia(), $category));
    }

    // The lists only ever describe the request being rendered - a worker runtime keeping the services alive must not serve the next one the medias of the previous
    public function testResetDropsTheListOfEveryGalleryThatKeepsOne(): void
    {
        $resettable = new class implements AutomaticGalleryInterface, ResetInterface {
            public int $resets = 0;

            public function getKind(): string
            {
                return GalleryCategory::AUTOMATIC_LATEST;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getMedias(): array
            {
                return [];
            }

            public function reset(): void
            {
                ++$this->resets;
            }
        };

        $this->createProvider([$resettable, $this->createGallery(GalleryCategory::AUTOMATIC_PRINTABLE)])->reset();

        $this->assertSame(1, $resettable->resets);
    }
}
