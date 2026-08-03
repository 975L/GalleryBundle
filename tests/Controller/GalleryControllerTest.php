<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Controller;

use c975L\GalleryBundle\Controller\GalleryController;
use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryPhotoRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

// Only 'twig' is ever fetched, so a bare Container is enough and no kernel boot is needed
class GalleryControllerTest extends TestCase
{
    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity::class, 'id');
        $property->setValue($entity, $id);
    }

    private function createController(
        ?GalleryRepository $galleryRepository = null,
        ?GalleryCategoryRepository $categoryRepository = null,
        ?GalleryPhotoRepository $photoRepository = null,
    ): GalleryController {
        $controller = new GalleryController(
            $galleryRepository ?? $this->createStub(GalleryRepository::class),
            $categoryRepository ?? $this->createStub(GalleryCategoryRepository::class),
            $photoRepository ?? $this->createStub(GalleryPhotoRepository::class),
        );

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(static fn (string $view): string => $view);

        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    public function testIndexRendersCategoriesOfTheDefaultGallery(): void
    {
        $gallery = new Gallery();
        $category = new GalleryCategory();
        $gallery->addCategory($category);

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findDefault')->willReturn($gallery);

        $controller = $this->createController(galleryRepository: $galleryRepository);

        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('@c975LGallery/gallery/index.html.twig', $response->getContent());
    }

    // Fresh install, no default Gallery configured yet - an empty list, not an error
    public function testIndexRendersEmptyCategoriesWhenNoDefaultGalleryExists(): void
    {
        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findDefault')->willReturn(null);

        $twig = $this->createStub(Environment::class);
        $capturedParameters = null;
        $twig->method('render')->willReturnCallback(
            function (string $view, array $parameters = []) use (&$capturedParameters): string {
                $capturedParameters = $parameters;

                return $view;
            }
        );

        $controller = new GalleryController(
            $galleryRepository,
            $this->createStub(GalleryCategoryRepository::class),
            $this->createStub(GalleryPhotoRepository::class),
        );
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $controller->index();

        $this->assertSame([], $capturedParameters['categories']);
    }

    public function testCategoryRendersItsPhotosGrid(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $category = (new GalleryCategory())->setSlug('voyages');
        $photos = [new GalleryPhoto(), new GalleryPhoto()];

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findDefault')->willReturn($gallery);
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('findOneBySlug')->with($gallery, 'voyages')->willReturn($category);
        $photoRepository = $this->createMock(GalleryPhotoRepository::class);
        $photoRepository->expects($this->once())->method('findByCategory')->with($category)->willReturn($photos);

        $twig = $this->createStub(Environment::class);
        $capturedParameters = null;
        $twig->method('render')->willReturnCallback(
            function (string $view, array $parameters = []) use (&$capturedParameters): string {
                $capturedParameters = $parameters;

                return $view;
            }
        );

        $controller = new GalleryController($galleryRepository, $categoryRepository, $photoRepository);
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $response = $controller->category('voyages');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($category, $capturedParameters['category']);
        $this->assertSame($photos, $capturedParameters['photos']);
    }

    public function testCategoryThrowsNotFoundWhenSlugIsUnknown(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findDefault')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn(null);

        $controller = $this->createController(galleryRepository: $galleryRepository, categoryRepository: $categoryRepository);

        $this->expectException(NotFoundHttpException::class);
        $controller->category('unknown');
    }

    public function testCategoryThrowsNotFoundWhenNoDefaultGalleryExists(): void
    {
        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findDefault')->willReturn(null);

        $controller = $this->createController(galleryRepository: $galleryRepository);

        $this->expectException(NotFoundHttpException::class);
        $controller->category('voyages');
    }

    public function testPhotoRendersMediumViewWithPreviousAndNext(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $category = (new GalleryCategory())->setSlug('voyages');
        $photo = new GalleryPhoto();
        $photo->setCategory($category);
        $this->setId($photo, 5);
        $previousNext = ['previous' => new GalleryPhoto(), 'next' => new GalleryPhoto()];

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findDefault')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);
        $photoRepository = $this->createMock(GalleryPhotoRepository::class);
        $photoRepository->expects($this->once())->method('find')->with(5)->willReturn($photo);
        $photoRepository->expects($this->once())->method('findPreviousAndNext')->with($photo)->willReturn($previousNext);

        $twig = $this->createStub(Environment::class);
        $capturedParameters = null;
        $twig->method('render')->willReturnCallback(
            function (string $view, array $parameters = []) use (&$capturedParameters): string {
                $capturedParameters = $parameters;

                return $view;
            }
        );

        $controller = new GalleryController($galleryRepository, $categoryRepository, $photoRepository);
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $response = $controller->photo('voyages', 5);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($photo, $capturedParameters['photo']);
        $this->assertSame($previousNext, $capturedParameters['previousNext']);
    }

    public function testPhotoThrowsNotFoundWhenIdIsUnknown(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $category = (new GalleryCategory())->setSlug('voyages');

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findDefault')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);
        $photoRepository = $this->createStub(GalleryPhotoRepository::class);
        $photoRepository->method('find')->willReturn(null);

        $controller = $this->createController(
            galleryRepository: $galleryRepository,
            categoryRepository: $categoryRepository,
            photoRepository: $photoRepository,
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->photo('voyages', 999);
    }

    // A photo id browsed under a category slug it doesn't actually belong to must not resolve - prevents cross-category id fishing
    public function testPhotoThrowsNotFoundWhenPhotoBelongsToADifferentCategory(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $category = (new GalleryCategory())->setSlug('voyages');
        $otherCategory = (new GalleryCategory())->setSlug('portraits');
        $photo = new GalleryPhoto();
        $photo->setCategory($otherCategory);
        $this->setId($photo, 5);

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findDefault')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);
        $photoRepository = $this->createMock(GalleryPhotoRepository::class);
        $photoRepository->expects($this->once())->method('find')->with(5)->willReturn($photo);

        $controller = $this->createController(
            galleryRepository: $galleryRepository,
            categoryRepository: $categoryRepository,
            photoRepository: $photoRepository,
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->photo('voyages', 5);
    }

    public function testPhotoHrRendersHighResViewWithPreviousAndNext(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $category = (new GalleryCategory())->setSlug('voyages');
        $photo = new GalleryPhoto();
        $photo->setCategory($category);
        $this->setId($photo, 7);
        $previousNext = ['previous' => new GalleryPhoto(), 'next' => new GalleryPhoto()];

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findDefault')->willReturn($gallery);
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);
        $photoRepository = $this->createMock(GalleryPhotoRepository::class);
        $photoRepository->expects($this->once())->method('find')->with(7)->willReturn($photo);
        $photoRepository->expects($this->once())->method('findPreviousAndNext')->with($photo)->willReturn($previousNext);

        $twig = $this->createStub(Environment::class);
        $capturedParameters = null;
        $twig->method('render')->willReturnCallback(
            function (string $view, array $parameters = []) use (&$capturedParameters): string {
                $capturedParameters = $parameters;

                return $view;
            }
        );

        $controller = new GalleryController($galleryRepository, $categoryRepository, $photoRepository);
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $response = $controller->photoHr('voyages', 7);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($photo, $capturedParameters['photo']);
        $this->assertSame($previousNext, $capturedParameters['previousNext']);
    }
}
