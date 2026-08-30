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
use c975L\GalleryBundle\Service\GalleryAutomaticProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

// Only 'twig' is ever fetched, so a bare Container is enough and no kernel boot is needed
class GalleryControllerTest extends TestCase
{
    private function createController(
        ?GalleryCategoryRepository $categoryRepository = null,
        ?GalleryMediaRepository $mediaRepository = null,
        ?GalleryAutomaticProvider $automaticProvider = null,
    ): GalleryController {
        $controller = new GalleryController(
            $categoryRepository ?? $this->createStub(GalleryCategoryRepository::class),
            $mediaRepository ?? $this->createStub(GalleryMediaRepository::class),
            $automaticProvider ?? $this->createAutomaticProvider(),
        );

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(static fn (string $view): string => $view);

        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    // The index is handed back the list it read, the automatic galleries being already in it here
    private function createAutomaticProvider(array $medias = []): GalleryAutomaticProvider
    {
        $automaticProvider = $this->createStub(GalleryAutomaticProvider::class);
        $automaticProvider->method('prepare')->willReturnArgument(0);
        $automaticProvider->method('getMedias')->willReturn($medias);

        return $automaticProvider;
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
            $this->createAutomaticProvider(),
        );
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $controller->index();

        $this->assertSame([], $capturedParameters['categories']);
    }

    // The breadcrumb's count is taken from the list the index already reads, not from a query of its own
    public function testIndexCountsTheCategoriesItLists(): void
    {
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('findAllOrdered')->willReturn([new GalleryCategory(), new GalleryCategory()]);
        $categoryRepository->expects($this->never())->method('countVisible');

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
            $this->createAutomaticProvider(),
        );
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $controller->index();

        $this->assertSame(2, $capturedParameters['categoriesCount']);
    }

    public function testCategoryRendersItsMediasGrid(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');
        $medias = [new GalleryMedia(), new GalleryMedia()];

        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('findOneBySlug')->with('voyages')->willReturn($category);
        $categoryRepository->method('countVisible')->willReturn(4);
        $mediaRepository = $this->createStub(GalleryMediaRepository::class);

        $twig = $this->createStub(Environment::class);
        $capturedParameters = null;
        $twig->method('render')->willReturnCallback(
            function (string $view, array $parameters = []) use (&$capturedParameters): string {
                $capturedParameters = $parameters;

                return $view;
            }
        );

        // An ordinary gallery's medias come from the coordinator too, which reads them off the category itself (see GalleryAutomaticProvider::getMedias)
        $controller = new GalleryController($categoryRepository, $mediaRepository, $this->createAutomaticProvider($medias));
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $response = $controller->category('voyages');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($category, $capturedParameters['category']);
        $this->assertSame(4, $capturedParameters['categoriesCount']);
        $this->assertSame($medias, $capturedParameters['medias']);
    }

    // The automatic gallery is served by this very route and this very template: only its list comes from the last days of additions instead of from a relation it has none of
    public function testCategoryOfTheAutomaticGalleryRendersTheLatestMedias(): void
    {
        $category = new GalleryCategory()->setSlug('latest')->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $latest = [new GalleryMedia(), new GalleryMedia()];

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);
        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->expects($this->never())->method('findByCategory');

        $automaticProvider = $this->createAutomaticProvider($latest);

        $twig = $this->createStub(Environment::class);
        $capturedParameters = null;
        $twig->method('render')->willReturnCallback(
            function (string $view, array $parameters = []) use (&$capturedParameters): string {
                $capturedParameters = $parameters;

                return $view;
            }
        );

        $controller = new GalleryController($categoryRepository, $mediaRepository, $automaticProvider);
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $controller->category('latest');

        $this->assertSame($latest, $capturedParameters['medias']);
    }

    // Opened from the last additions, a photo is walked among them: the neighbours come from that list and the trail leads back to it, the url staying the media's own
    public function testMediaBrowsedFromTheLatestGalleryWalksItsList(): void
    {
        $automatic = new GalleryCategory()->setSlug('latest')->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $category = new GalleryCategory()->setSlug('objets');
        $media = new GalleryMedia()->setSlug('objet-1')->setCategory($category);
        $neighbours = ['previous' => new GalleryMedia(), 'next' => new GalleryMedia()];

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturnCallback(
            static fn (string $slug): ?GalleryCategory => 'latest' === $slug ? $automatic : $category
        );
        $mediaRepository = $this->createMock(GalleryMediaRepository::class);
        $mediaRepository->method('findOneBySlugInCategory')->willReturn($media);
        $mediaRepository->expects($this->never())->method('findPreviousAndNext');

        $automaticProvider = $this->createAutomaticProvider();
        $automaticProvider->method('findPreviousAndNext')->willReturn($neighbours);

        $capturedParameters = $this->renderMedia($categoryRepository, $mediaRepository, $automaticProvider, new Request(['from' => 'latest']));

        $this->assertSame($neighbours, $capturedParameters['previousNext']);
        $this->assertSame($automatic, $capturedParameters['browsedFrom']);
        $this->assertSame($category, $capturedParameters['category']);
    }

    // A media that has since left the last additions is browsed as its own category's, whatever the url says it was opened from
    public function testMediaFallsBackOnItsCategoryWhenItLeftTheLatestGallery(): void
    {
        $automatic = new GalleryCategory()->setSlug('latest')->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $category = new GalleryCategory()->setSlug('objets');
        $media = new GalleryMedia()->setSlug('objet-1')->setCategory($category);
        $neighbours = ['previous' => new GalleryMedia(), 'next' => new GalleryMedia()];

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturnCallback(
            static fn (string $slug): ?GalleryCategory => 'latest' === $slug ? $automatic : $category
        );
        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findOneBySlugInCategory')->willReturn($media);
        $mediaRepository->method('findPreviousAndNext')->willReturn($neighbours);

        $automaticProvider = $this->createAutomaticProvider();
        $automaticProvider->method('findPreviousAndNext')->willReturn(null);

        $capturedParameters = $this->renderMedia($categoryRepository, $mediaRepository, $automaticProvider, new Request(['from' => 'latest']));

        $this->assertSame($neighbours, $capturedParameters['previousNext']);
        $this->assertNull($capturedParameters['browsedFrom']);
    }

    // A "from" naming a normal gallery changes nothing: that one holds its medias, so the url already says which gallery is being walked
    public function testMediaIgnoresAFromNamingAGalleryHoldingItsOwnMedias(): void
    {
        $category = new GalleryCategory()->setSlug('objets');
        $media = new GalleryMedia()->setSlug('objet-1')->setCategory($category);

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);
        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findOneBySlugInCategory')->willReturn($media);
        $mediaRepository->method('findPreviousAndNext')->willReturn(['previous' => $media, 'next' => $media]);

        $automaticProvider = $this->createMock(GalleryAutomaticProvider::class);
        $automaticProvider->expects($this->never())->method('findPreviousAndNext');

        $capturedParameters = $this->renderMedia($categoryRepository, $mediaRepository, $automaticProvider, new Request(['from' => 'objets']));

        $this->assertNull($capturedParameters['browsedFrom']);
    }

    // A "from" naming a masked automatic gallery is ignored the way a trashed one is: the breadcrumb would then walk the visitor through a gallery that answers 404
    public function testMediaIgnoresAFromNamingAHiddenGallery(): void
    {
        $automatic = new GalleryCategory()->setSlug('latest')->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST)->setHidden(true);
        $category = new GalleryCategory()->setSlug('objets');
        $media = new GalleryMedia()->setSlug('objet-1')->setCategory($category);

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturnCallback(
            static fn (string $slug): ?GalleryCategory => 'latest' === $slug ? $automatic : $category
        );
        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findOneBySlugInCategory')->willReturn($media);
        $mediaRepository->method('findPreviousAndNext')->willReturn(['previous' => $media, 'next' => $media]);

        $automaticProvider = $this->createMock(GalleryAutomaticProvider::class);
        $automaticProvider->expects($this->never())->method('findPreviousAndNext');

        $capturedParameters = $this->renderMedia($categoryRepository, $mediaRepository, $automaticProvider, new Request(['from' => 'latest']));

        $this->assertNull($capturedParameters['browsedFrom']);
    }

    /** @return array<string, mixed> */
    private function renderMedia(
        GalleryCategoryRepository $categoryRepository,
        GalleryMediaRepository $mediaRepository,
        GalleryAutomaticProvider $automaticProvider,
        Request $request,
    ): array {
        $twig = $this->createStub(Environment::class);
        $capturedParameters = [];
        $twig->method('render')->willReturnCallback(
            function (string $view, array $parameters = []) use (&$capturedParameters): string {
                $capturedParameters = $parameters;

                return $view;
            }
        );

        $controller = new GalleryController($categoryRepository, $mediaRepository, $automaticProvider);
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $controller->media('objets', 'objet-1', $request);

        return $capturedParameters;
    }

    public function testCategoryThrowsNotFoundWhenSlugIsUnknown(): void
    {
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn(null);

        $controller = $this->createController(categoryRepository: $categoryRepository);

        $this->expectException(NotFoundHttpException::class);
        $controller->category('unknown');
    }

    // A category in the trash says the url held something and no longer does, rather than the 404 a crawler retries for months - the same answer SiteBundle serves for a trashed Page, and one that only lasts as long as the category can still be restored
    public function testCategoryThrowsGoneWhenTheCategoryIsInTheTrash(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');
        $category->setIsDeleted(true);

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);

        $controller = $this->createController(categoryRepository: $categoryRepository);

        $this->expectException(GoneHttpException::class);
        $controller->category('voyages');
    }

    // A masked gallery answers 404 and not 410, exactly as a masked media does: masking is reversible, where 410 tells a crawler the url is gone for good
    public function testCategoryThrowsNotFoundWhenTheCategoryIsHidden(): void
    {
        $category = new GalleryCategory()->setSlug('voyages')->setHidden(true);

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);

        $controller = $this->createController(categoryRepository: $categoryRepository);

        $this->expectException(NotFoundHttpException::class);
        $controller->category('voyages');
    }

    // The photographs of a masked gallery are off the site with it, their own page being resolved through their category's
    public function testMediaThrowsNotFoundWhenItsCategoryIsHidden(): void
    {
        $category = new GalleryCategory()->setSlug('voyages')->setHidden(true);
        $media = new GalleryMedia()->setSlug('col-du-galibier')->setCategory($category);

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);

        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findOneBySlugInCategory')->willReturn($media);

        $controller = $this->createController(categoryRepository: $categoryRepository, mediaRepository: $mediaRepository);

        $this->expectException(NotFoundHttpException::class);
        $controller->media('voyages', 'col-du-galibier', new Request());
    }

    // A media has a trash of its own, so it answers 410 under a category that is perfectly online
    public function testMediaThrowsGoneWhenTheMediaIsInTheTrash(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');
        $media = new GalleryMedia()->setSlug('col-du-galibier');
        $media->setCategory($category);
        $media->setIsDeleted(true);

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);

        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findOneBySlugInCategory')->willReturn($media);

        $controller = $this->createController(categoryRepository: $categoryRepository, mediaRepository: $mediaRepository);

        $this->expectException(GoneHttpException::class);
        $controller->media('voyages', 'col-du-galibier', new Request());
    }

    public function testMediaRendersMediumViewWithPreviousAndNext(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');
        $media = new GalleryMedia()->setSlug('col-du-galibier');
        $media->setCategory($category);
        $previousNext = ['previous' => new GalleryMedia(), 'next' => new GalleryMedia()];

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);
        $categoryRepository->method('countVisible')->willReturn(4);
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

        $controller = new GalleryController($categoryRepository, $mediaRepository, $this->createAutomaticProvider());
        $container = new Container();
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $response = $controller->media('voyages', 'col-du-galibier', new Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($media, $capturedParameters['media']);
        $this->assertSame(4, $capturedParameters['categoriesCount']);
        $this->assertSame($previousNext, $capturedParameters['previousNext']);
    }

    public function testMediaThrowsNotFoundWhenSlugIsUnknown(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findOneBySlug')->willReturn($category);
        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findOneBySlugInCategory')->willReturn(null);

        $controller = $this->createController(
            categoryRepository: $categoryRepository,
            mediaRepository: $mediaRepository,
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->media('voyages', 'unknown', new Request());
    }

    // A media slug browsed under a category it doesn't belong to must not resolve - the category is part of the lookup, a slug only being unique within one
    public function testMediaIsLookedUpWithinTheCategoryOfTheUrl(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');

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
        $controller->media('voyages', 'col-du-galibier', new Request());
    }

    // The high resolution is served by the lightbox from the stored file's own url, so no route may hand out a page for it - a leftover one would keep the two navigations this bundle deliberately merged into one
    public function testNoRouteServesAHighResolutionPage(): void
    {
        $routes = [];
        foreach (new \ReflectionClass(GalleryController::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attribute) {
                $arguments = $attribute->getArguments();
                $routes[] = (string) ($arguments['path'] ?? $arguments[0] ?? '');
            }
        }

        $this->assertNotEmpty($routes, 'No route was read, this test no longer checks anything.');
        $this->assertSame([], array_values(array_filter($routes, static fn (string $path): bool => str_ends_with($path, '/hr'))));
    }
}
