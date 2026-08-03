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
use c975L\GalleryBundle\Entity\GalleryPhoto;
use PHPUnit\Framework\TestCase;

class GalleryCategoryTest extends TestCase
{
    public function testToStringReturnsTitleOrEmptyString(): void
    {
        $this->assertSame('', (string) new GalleryCategory());
        $this->assertSame('Voyages', (string) (new GalleryCategory())->setTitle('Voyages'));
    }

    public function testSetPositionFallsBackToZeroWhenNull(): void
    {
        $category = (new GalleryCategory())->setPosition(3);

        $category->setPosition(null);

        $this->assertSame(0, $category->getPosition());
    }

    public function testSetUncategorizedFallsBackToFalseWhenNull(): void
    {
        $category = (new GalleryCategory())->setUncategorized(true);

        $category->setUncategorized(null);

        $this->assertFalse($category->isUncategorized());
    }

    public function testAddPhotoSetsBothSidesOfTheRelationOnlyOnce(): void
    {
        $category = new GalleryCategory();
        $photo = new GalleryPhoto();

        $category->addPhoto($photo);
        $category->addPhoto($photo);

        $this->assertCount(1, $category->getPhotos());
        $this->assertSame($category, $photo->getCategory());
    }

    public function testRemovePhotoClearsBothSidesOfTheRelation(): void
    {
        $category = new GalleryCategory();
        $photo = new GalleryPhoto();
        $category->addPhoto($photo);

        $category->removePhoto($photo);

        $this->assertCount(0, $category->getPhotos());
        $this->assertNull($photo->getCategory());
    }

    // A photo already reassigned to another category must not be de-parented by the category it no longer belongs to
    public function testRemovePhotoDoesNotClearCategoryWhenItAlreadyBelongsElsewhere(): void
    {
        $category = new GalleryCategory();
        $otherCategory = new GalleryCategory();
        $photo = new GalleryPhoto();
        $category->addPhoto($photo);
        $photo->setCategory($otherCategory);

        $category->removePhoto($photo);

        $this->assertSame($otherCategory, $photo->getCategory());
    }
}
