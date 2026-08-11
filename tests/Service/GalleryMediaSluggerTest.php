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
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

class GalleryMediaSluggerTest extends TestCase
{
    private function createSlugger(): GalleryMediaSlugger
    {
        return new GalleryMediaSlugger(new AsciiSlugger());
    }

    private function createMedia(GalleryCategory $category, ?string $title, ?string $slug = null): GalleryMedia
    {
        $media = new GalleryMedia()->setTitle($title)->setSlug($slug);
        $category->addMedia($media);

        return $media;
    }

    public function testTheSlugIsTheLowercasedAsciiFormOfTheTitle(): void
    {
        $media = $this->createMedia(new GalleryCategory(), "Col du Galibier, l'été");

        $this->assertSame('col-du-galibier-l-ete', $this->createSlugger()->generate($media));
    }

    public function testAssignWritesTheSlugOnTheMedia(): void
    {
        $media = $this->createMedia(new GalleryCategory(), 'Mont Blanc');

        $this->createSlugger()->assign($media);

        $this->assertSame('mont-blanc', $media->getSlug());
    }

    // A collision is suffixed rather than refused: a batch straight off a camera legitimately carries the same name twice
    public function testACollisionWithinTheCategoryIsSuffixed(): void
    {
        $category = new GalleryCategory();
        $this->createMedia($category, 'Mont Blanc', 'mont-blanc');
        $this->createMedia($category, 'Mont Blanc', 'mont-blanc-2');
        $media = $this->createMedia($category, 'Mont Blanc');

        $this->assertSame('mont-blanc-3', $this->createSlugger()->generate($media));
    }

    // The slug is the media's url segment under its category, so two categories are free to both hold the same one
    public function testTheSameSlugInAnotherCategoryIsNotACollision(): void
    {
        $this->createMedia(new GalleryCategory(), 'Mont Blanc', 'mont-blanc');
        $media = $this->createMedia(new GalleryCategory(), 'Mont Blanc');

        $this->assertSame('mont-blanc', $this->createSlugger()->generate($media));
    }

    // Regenerating a media's own slug must not collide with the slug it already carries
    public function testAMediaDoesNotCollideWithItself(): void
    {
        $category = new GalleryCategory();
        $media = $this->createMedia($category, 'Mont Blanc', 'mont-blanc');

        $this->assertSame('mont-blanc', $this->createSlugger()->generate($media));
    }

    // An import carries the slug the media already had, honoured so a round-trip through an export leaves the public urls where they were
    public function testAPreferredSlugIsHonouredWhenItIsFree(): void
    {
        $media = $this->createMedia(new GalleryCategory(), 'Mont Blanc');

        $this->assertSame('col-du-galibier', $this->createSlugger()->generate($media, 'col-du-galibier'));
    }

    public function testAPreferredSlugIsSuffixedWhenItIsTaken(): void
    {
        $category = new GalleryCategory();
        $this->createMedia($category, 'Col du Galibier', 'col-du-galibier');
        $media = $this->createMedia($category, 'Mont Blanc');

        $this->assertSame('col-du-galibier-2', $this->createSlugger()->generate($media, 'col-du-galibier'));
    }

    // An untitled media still needs a url and a filename of its own, so it falls back rather than ending up with an empty slug
    public function testAnUntitledMediaFallsBackAndIsStillMadeUnique(): void
    {
        $category = new GalleryCategory();
        $slugger = $this->createSlugger();

        $first = $this->createMedia($category, null);
        $slugger->assign($first);
        $second = $this->createMedia($category, '');
        $slugger->assign($second);

        $this->assertSame(['media', 'media-2'], [$first->getSlug(), $second->getSlug()]);
    }

    // A title made only of what a slug can't carry leaves nothing to build one from
    public function testATitleThatSlugifiesToNothingFallsBackToo(): void
    {
        $media = $this->createMedia(new GalleryCategory(), '!!! ???');

        $this->assertSame('media', $this->createSlugger()->generate($media));
    }

    // A media not yet attached to a category has no sibling to collide with
    public function testAMediaWithoutACategoryIsStillSlugged(): void
    {
        $media = new GalleryMedia()->setTitle('Mont Blanc');

        $this->assertSame('mont-blanc', $this->createSlugger()->generate($media));
    }
}
