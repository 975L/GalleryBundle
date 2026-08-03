<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Entity;

use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use PHPUnit\Framework\TestCase;

class GalleryTest extends TestCase
{
    public function testToStringReturnsTitleOrEmptyString(): void
    {
        $this->assertSame('', (string) new Gallery());
        $this->assertSame('Galerie', (string) (new Gallery())->setTitle('Galerie'));
    }

    public function testSetPositionFallsBackToZeroWhenNull(): void
    {
        $gallery = (new Gallery())->setPosition(3);

        $gallery->setPosition(null);

        $this->assertSame(0, $gallery->getPosition());
    }

    public function testSetDefaultFallsBackToFalseWhenNull(): void
    {
        $gallery = (new Gallery())->setDefault(true);

        $gallery->setDefault(null);

        $this->assertFalse($gallery->isDefault());
    }

    public function testAddCategorySetsBothSidesOfTheRelationOnlyOnce(): void
    {
        $gallery = new Gallery();
        $category = new GalleryCategory();

        $gallery->addCategory($category);
        $gallery->addCategory($category);

        $this->assertCount(1, $gallery->getCategories());
        $this->assertSame($gallery, $category->getGallery());
    }

    public function testRemoveCategoryClearsBothSidesOfTheRelation(): void
    {
        $gallery = new Gallery();
        $category = new GalleryCategory();
        $gallery->addCategory($category);

        $gallery->removeCategory($category);

        $this->assertCount(0, $gallery->getCategories());
        $this->assertNull($category->getGallery());
    }

    // A category already reassigned to another gallery must not be de-parented by the gallery it no longer belongs to
    public function testRemoveCategoryDoesNotClearGalleryWhenItAlreadyBelongsElsewhere(): void
    {
        $gallery = new Gallery();
        $otherGallery = new Gallery();
        $category = new GalleryCategory();
        $gallery->addCategory($category);
        $category->setGallery($otherGallery);

        $gallery->removeCategory($category);

        $this->assertSame($otherGallery, $category->getGallery());
    }
}
