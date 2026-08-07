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
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

// Only 'twig' is ever fetched, so a bare Container is enough and no kernel boot is needed
class GalleryControllerTest extends TestCase
{
    private function createController(
        ?GalleryCategoryRepository $categoryRepository = null,
        ?GalleryMediaRepository $mediaRepository = null,
    ): GalleryController {
        $controller = new GalleryController(
            $categoryRepository ?? $this->createStub(GalleryCategoryRepository::class),
            $mediaRepository ?? $this->createStub(GalleryMediaRepository::class),
        );

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(static fn (string $view): string => $view);

        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    public function testIndexRendersEveryCategory(): void
    {
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findAllOrdered')->willReturn([new GalleryCategory()]);

        $controller = $this->createController(categoryRepository: $categoryRepository);

        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('@c975LGallery/gallery/index.html.twig', $response->getContent());
    }

    // Fresh install, no category created yet - an empty list, not an error
    public function testIndexRendersEmptyCategoriesWhenNoneExists(): void
    {
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findAllOrdered')->willReturn([]);

        $twig = $this->createStub(Environment::class);
        $capturedParameters = null;
        $twig->method('render')->willReturnCallback(
            function (string $view, array $parameters = []) use (&$capturedParameters): string {
                $capturedParameters = $parameters;

                return $view;
            }
        );

        $controller = new GalleryController(
            $categoryRepository,
            $this->createStub(GalleryMediaRepository::class),
        );
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $controller->index();

        $this->assertSame([], $capturedParameters['categories']);
    }

    public function testCategoryRendersItsMediasGrid(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $medias = [new GalleryMedia(), new GalleryMedia()];

        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('findOneBySlug')->with('voyages')->willReturn($category);
        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->once())->method('findByCategory')->with($category)->willReturn($medias);

        $twig = $this->createStub(Environment::class);
        $capturedParameters = null;
        $twig->method('render')->willReturnCallback(
            function (string $view, array $parameters = []) use (&$capturedParameters): string {
                $capturedParameters = $parameters;

                return $view;
            }
        );

        $controller = new GalleryController($categoryRepository, $mediaRepository);
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $response = $controller->category('voyages');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($category, $capturedParameters['category']);
        $this->assertSame($medias, $capturedParameters['medias']);
    }

    public function testCategoryThrowsNotFoundWhenSlugIsUnknown(): void
    {
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn(null);

        $controller = $this->createController(categoryRepository: $categoryRepository);

        $this->expectException(NotFoundHttpException::class);
        $controller->category('unknown');
    }

    public function testMediaRendersMediumViewWithPreviousAndNext(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $media = (new GalleryMedia())->setSlug('col-du-galibier');
        $media->setCategory($category);
        $previousNext = ['previous' => new GalleryMedia(), 'next' => new GalleryMedia()];

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);
        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->once())->method('findOneBySlugInCategory')->with($category, 'col-du-galibier')->willReturn($media);
        $mediaRepository->expects($this->once())->method('findPreviousAndNext')->with($media)->willReturn($previousNext);

        $twig = $this->createStub(Environment::class);
        $capturedParameters = null;
        $twig->method('render')->willReturnCallback(
            function (string $view, array $parameters = []) use (&$capturedParameters): string {
                $capturedParameters = $parameters;

                return $view;
            }
        );

        $controller = new GalleryController($categoryRepository, $mediaRepository);
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $response = $controller->media('voyages', 'col-du-galibier');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($media, $capturedParameters['media']);
        $this->assertSame($previousNext, $capturedParameters['previousNext']);
    }

    public function testMediaThrowsNotFoundWhenSlugIsUnknown(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);
        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findOneBySlugInCategory')->willReturn(null);

        $controller = $this->createController(
            categoryRepository: $categoryRepository,
            mediaRepository: $mediaRepository,
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->media('voyages', 'unknown');
    }

    // A media slug browsed under a category it doesn't belong to must not resolve - the category is part of the lookup, a slug only being unique within one
    public function testMediaIsLookedUpWithinTheCategoryOfTheUrl(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);
        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->once())
            ->method('findOneBySlugInCategory')
            ->with($category, 'col-du-galibier')
            ->willReturn(null);

        $controller = $this->createController(
            categoryRepository: $categoryRepository,
            mediaRepository: $mediaRepository,
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->media('voyages', 'col-du-galibier');
    }

    // The high resolution is served by the lightbox from the stored file's own url, so no route may hand out a page for it - a leftover one would keep the two navigations this bundle deliberately merged into one
    public function testNoRouteServesAHighResolutionPage(): void
    {
        $routes = [];
        foreach ((new \ReflectionClass(GalleryController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attribute) {
                $arguments = $attribute->getArguments();
                $routes[] = (string) ($arguments['path'] ?? $arguments[0] ?? '');
            }
        }

        $this->assertNotEmpty($routes, 'No route was read, this test no longer checks anything.');
        $this->assertSame([], array_values(array_filter($routes, static fn (string $path): bool => str_ends_with($path, '/hr'))));
    }
}
