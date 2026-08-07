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
use c975L\GalleryBundle\Management\GalleryBlockOwnerResolver;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use PHPUnit\Framework\TestCase;

class GalleryBlockOwnerResolverTest extends TestCase
{
    public function testSupportsOnlyItsOwnOwnerType(): void
    {
        $resolver = new GalleryBlockOwnerResolver($this->createStub(GalleryCategoryRepository::class));

        $this->assertTrue($resolver->supports(GalleryBlockOwnerResolver::TYPE_CATEGORY));
        $this->assertFalse($resolver->supports('page'));
    }

    public function testFindReturnsTheCategory(): void
    {
        $category = new GalleryCategory();
        $repository = $this->createStub(GalleryCategoryRepository::class);
        $repository->method('find')->willReturn($category);

        $resolver = new GalleryBlockOwnerResolver($repository);

        $this->assertSame($category, $resolver->find(GalleryBlockOwnerResolver::TYPE_CATEGORY, 1));
    }

    // Another bundle's owner type must never hit this bundle's repository
    public function testFindReturnsNullForAnotherBundlesOwnerType(): void
    {
        $repository = $this->createMock(GalleryCategoryRepository::class);
        $repository->expects($this->never())->method('find');

        $resolver = new GalleryBlockOwnerResolver($repository);

        $this->assertNull($resolver->find('page', 1));
    }
}
