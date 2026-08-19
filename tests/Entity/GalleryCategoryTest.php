<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Entity;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\UiBundle\Contract\HasBlocksInterface;
use c975L\UiBundle\Entity\Block;
use PHPUnit\Framework\TestCase;

class GalleryCategoryTest extends TestCase
{
    public function testToStringReturnsTitleOrEmptyString(): void
    {
        $this->assertSame('', (string) new GalleryCategory());
        $this->assertSame('Voyages', (string) new GalleryCategory()->setTitle('Voyages'));
    }

    public function testSetPositionFallsBackToZeroWhenNull(): void
    {
        $category = new GalleryCategory()->setPosition(3);

        $category->setPosition(null);

        $this->assertSame(0, $category->getPosition());
    }

    public function testSetUncategorizedFallsBackToFalseWhenNull(): void
    {
        $category = new GalleryCategory()->setUncategorized(true);

        $category->setUncategorized(null);

        $this->assertFalse($category->isUncategorized());
    }

    public function testAddMediaSetsBothSidesOfTheRelationOnlyOnce(): void
    {
        $category = new GalleryCategory();
        $media = new GalleryMedia();

        $category->addMedia($media);
        $category->addMedia($media);

        $this->assertCount(1, $category->getMedias());
        $this->assertSame($category, $media->getCategory());
    }

    // What the back-office category listing shows instead of the medias themselves
    public function testGetMediasCountCountsTheCategoryMedias(): void
    {
        $category = new GalleryCategory();

        $this->assertSame(0, $category->getMediasCount());

        $category->addMedia(new GalleryMedia());
        $category->addMedia(new GalleryMedia());

        $this->assertSame(2, $category->getMediasCount());
    }

    // The face the index tile, the admin listing and the page's og:image all read
    public function testGetCoverOrRandomMediaPrefersTheCover(): void
    {
        $category = new GalleryCategory();
        $cover = new GalleryMedia();
        $category->addMedia(new GalleryMedia())->addMedia($cover)->setCoverMedia($cover);

        $this->assertSame($cover, $category->getCoverOrRandomMedia());
    }

    // No cover picked yet: the category still has a face, taken among its own medias
    public function testGetCoverOrRandomMediaFallsBackToOneOfTheMedias(): void
    {
        $category = new GalleryCategory();
        $first = new GalleryMedia();
        $second = new GalleryMedia();
        $category->addMedia($first)->addMedia($second);

        $this->assertContains($category->getCoverOrRandomMedia(), [$first, $second]);
    }

    // An empty category has nothing to show - the callers each draw their own placeholder
    public function testGetCoverOrRandomMediaReturnsNullWhenTheCategoryIsEmpty(): void
    {
        $this->assertNull(new GalleryCategory()->getCoverOrRandomMedia());
    }

    // The number is read next to the category everywhere the grid is - one that kept counting the trash would never match what it names
    public function testGetMediasCountLeavesTheTrashedMediasOut(): void
    {
        $category = new GalleryCategory();
        $trashed = new GalleryMedia();
        $trashed->setIsDeleted(true);
        $category->addMedia(new GalleryMedia())->addMedia($trashed);

        $this->assertSame(1, $category->getMediasCount());
    }

    // A photo taken off the site must not come back through the tile that stands for the whole category
    public function testGetCoverOrRandomMediaNeverDrawsATrashedMedia(): void
    {
        $category = new GalleryCategory();
        $shown = new GalleryMedia();
        $trashed = new GalleryMedia();
        $trashed->setIsDeleted(true);
        $category->addMedia($trashed)->addMedia($shown);

        $this->assertSame($shown, $category->getCoverOrRandomMedia());
    }

    // Same for a cover that has since been trashed: the category falls back on one it still shows rather than on the picked one
    public function testGetCoverOrRandomMediaFallsBackWhenTheCoverIsTrashed(): void
    {
        $category = new GalleryCategory();
        $cover = new GalleryMedia();
        $cover->setIsDeleted(true);
        $shown = new GalleryMedia();
        $category->addMedia($cover)->addMedia($shown)->setCoverMedia($cover);

        $this->assertSame($shown, $category->getCoverOrRandomMedia());
    }

    // Every media trashed leaves the category without a face, exactly as an empty one
    public function testGetCoverOrRandomMediaReturnsNullWhenEveryMediaIsTrashed(): void
    {
        $category = new GalleryCategory();
        $trashed = new GalleryMedia();
        $trashed->setIsDeleted(true);
        $category->addMedia($trashed);

        $this->assertNull($category->getCoverOrRandomMedia());
    }

    public function testRemoveMediaClearsBothSidesOfTheRelation(): void
    {
        $category = new GalleryCategory();
        $media = new GalleryMedia();
        $category->addMedia($media);

        $category->removeMedia($media);

        $this->assertCount(0, $category->getMedias());
        $this->assertNull($media->getCategory());
    }

    // A media already reassigned to another category must not be de-parented by the category it no longer belongs to
    public function testRemoveMediaDoesNotClearCategoryWhenItAlreadyBelongsElsewhere(): void
    {
        $category = new GalleryCategory();
        $otherCategory = new GalleryCategory();
        $media = new GalleryMedia();
        $category->addMedia($media);
        $media->setCategory($otherCategory);

        $category->removeMedia($media);

        $this->assertSame($otherCategory, $media->getCategory());
    }

    // What makes a category's editorial heading composable in the back-office with UiBundle's own block kinds
    public function testCategoryOwnsBlocks(): void
    {
        $category = new GalleryCategory();
        $block = new Block();

        $this->assertInstanceOf(HasBlocksInterface::class, $category);
        $this->assertCount(0, $category->getBlocks());

        $category->addBlock($block);
        $this->assertCount(1, $category->getBlocks());

        $category->addBlock($block);
        $this->assertCount(1, $category->getBlocks(), 'the same block is never added twice');

        $category->removeBlock($block);
        $this->assertCount(0, $category->getBlocks());
    }

    // The automatic gallery holds none of the medias it shows, so its count and its tile come from the list it is handed (see GalleryLatestProvider)
    public function testAnAutomaticCategoryCountsTheMediasItIsHanded(): void
    {
        $category = new GalleryCategory()->setAutomatic(true);
        $category->setAutomaticMedias([new GalleryMedia(), new GalleryMedia()]);

        $this->assertSame(2, $category->getMediasCount());
    }

    // Its face is its newest photo, not one of them at random: what it stands for is precisely what has just arrived
    public function testAnAutomaticCategoryShowsItsNewestMedia(): void
    {
        $newest = new GalleryMedia();
        $category = new GalleryCategory()->setAutomatic(true);
        $category->setAutomaticMedias([$newest, new GalleryMedia(), new GalleryMedia()]);

        $this->assertSame($newest, $category->getCoverOrRandomMedia());
    }

    // A list handed to a category that isn't the automatic one changes nothing: it counts and pictures its own medias
    public function testANormalCategoryIgnoresAHandedList(): void
    {
        $category = new GalleryCategory();
        $category->setAutomaticMedias([new GalleryMedia(), new GalleryMedia()]);

        $this->assertSame(0, $category->getMediasCount());
    }

    // BlockRelocator renumbers what's left after a block has been moved out
    public function testReorderBlocksRenumbersFromZero(): void
    {
        $category = new GalleryCategory();
        $first = new Block()->setPosition(3);
        $second = new Block()->setPosition(7);
        $category->addBlock($first)->addBlock($second);

        $category->reorderBlocks();

        $this->assertSame(0, $first->getPosition());
        $this->assertSame(1, $second->getPosition());
    }
}
