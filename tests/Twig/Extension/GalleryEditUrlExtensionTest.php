<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Twig\Extension;

use c975L\GalleryBundle\Controller\Management\GalleryCategoryCrudController;
use c975L\GalleryBundle\Controller\Management\GalleryMediaCrudController;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Twig\Extension\GalleryEditUrlExtension;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GalleryEditUrlExtensionTest extends TestCase
{
    public function testGetFunctionsExposesTheTwoEditUrlFunctions(): void
    {
        $names = array_map(
            static fn ($function): string => $function->getName(),
            (new GalleryEditUrlExtension($this->createStub(AdminUrlGeneratorInterface::class)))->getFunctions()
        );

        $this->assertSame(['gallery_category_edit_url', 'gallery_media_edit_url'], $names);
    }

    public function testGetCategoryEditUrlOpensTheCategoryEditScreen(): void
    {
        $generator = $this->createAdminUrlGenerator('/management/gallery/8/edit');
        $generator->expects($this->once())->method('setController')->with(GalleryCategoryCrudController::class)->willReturnSelf();
        $generator->expects($this->once())->method('setAction')->with(Action::EDIT)->willReturnSelf();
        $generator->expects($this->once())->method('setEntityId')->with(8)->willReturnSelf();
        $generator->expects($this->never())->method('set');

        $category = new GalleryCategory();
        (new \ReflectionProperty(GalleryCategory::class, 'id'))->setValue($category, 8);

        $this->assertSame('/management/gallery/8/edit', (new GalleryEditUrlExtension($generator))->getCategoryEditUrl($category));
    }

    public function testGetCategoryEditUrlReturnsNothingForACategoryWithoutAnId(): void
    {
        $generator = $this->createAdminUrlGenerator();
        $generator->expects($this->never())->method('generateUrl');

        $this->assertNull((new GalleryEditUrlExtension($generator))->getCategoryEditUrl(new GalleryCategory()));
    }

    public function testGetMediaEditUrlOpensTheMediaEditScreenUnderItsCategory(): void
    {
        $generator = $this->createAdminUrlGenerator('/management/gallery-media/68/edit?category=8');
        $generator->expects($this->once())->method('setController')->with(GalleryMediaCrudController::class)->willReturnSelf();
        $generator->expects($this->once())->method('setAction')->with(Action::EDIT)->willReturnSelf();
        $generator->expects($this->once())->method('setEntityId')->with(68)->willReturnSelf();
        $generator->expects($this->once())->method('set')->with('category', 8)->willReturnSelf();

        $url = (new GalleryEditUrlExtension($generator))->getMediaEditUrl($this->createMedia(68, 8));

        $this->assertSame('/management/gallery-media/68/edit?category=8', $url);
    }

    // A media whose category was deleted still opens its own edit screen - there is simply no category to come back to
    public function testGetMediaEditUrlCarriesNoCategoryWhenTheMediaHasNone(): void
    {
        $generator = $this->createAdminUrlGenerator('/management/gallery-media/68/edit');
        $generator->expects($this->never())->method('set');

        $url = (new GalleryEditUrlExtension($generator))->getMediaEditUrl($this->createMedia(68, null));

        $this->assertSame('/management/gallery-media/68/edit', $url);
    }

    // An in-memory media (a fixture preview, an entity not persisted yet) has no edit screen to point at
    public function testGetMediaEditUrlReturnsNothingForAMediaWithoutAnId(): void
    {
        $generator = $this->createAdminUrlGenerator();
        $generator->expects($this->never())->method('generateUrl');

        $this->assertNull((new GalleryEditUrlExtension($generator))->getMediaEditUrl(new GalleryMedia()));
    }

    /**
     * @return AdminUrlGeneratorInterface&MockObject
     */
    private function createAdminUrlGenerator(string $generatedUrl = '/management/gallery-media/68/edit'): AdminUrlGeneratorInterface
    {
        $generator = $this->createMock(AdminUrlGeneratorInterface::class);
        $generator->method('unsetAll')->willReturnSelf();
        $generator->method('setController')->willReturnSelf();
        $generator->method('setAction')->willReturnSelf();
        $generator->method('setEntityId')->willReturnSelf();
        $generator->method('set')->willReturnSelf();
        $generator->method('generateUrl')->willReturn($generatedUrl);

        return $generator;
    }

    private function createMedia(int $id, ?int $categoryId): GalleryMedia
    {
        $media = new GalleryMedia();
        (new \ReflectionProperty(GalleryMedia::class, 'id'))->setValue($media, $id);

        if (null !== $categoryId) {
            $category = new GalleryCategory();
            (new \ReflectionProperty(GalleryCategory::class, 'id'))->setValue($category, $categoryId);
            $media->setCategory($category);
        }

        return $media;
    }
}
