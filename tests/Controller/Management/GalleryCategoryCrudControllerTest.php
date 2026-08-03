<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Service\Export\ContentExporter;
use c975L\GalleryBundle\Controller\Management\GalleryCategoryCrudController;
use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Management\GalleryExportProvider;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;
use c975L\GalleryBundle\Tests\Repository\GalleryCategoryRepositoryBySlugFixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\CrudContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Registry\AdminControllerRegistryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Router\AdminRouteGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

class GalleryCategoryCrudControllerTest extends TestCase
{
    private function createContainer(array $services): Container
    {
        $container = new Container();
        foreach ($services as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }

    private function createCsrfTokenManager(bool $valid): CsrfTokenManagerInterface
    {
        $manager = $this->createStub(CsrfTokenManagerInterface::class);
        $manager->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token) => $valid);

        return $manager;
    }

    private function createAuthorizationChecker(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return $checker;
    }

    private function createAdminUrlGenerator(string $generatedUrl = '/management/gallery-categories'): AdminUrlGenerator
    {
        $adminControllers = $this->createStub(AdminControllerRegistryInterface::class);
        $adminControllers->method('getDashboardCount')->willReturn(1);
        $adminControllers->method('getFirstDashboard')->willReturn('App\\Controller\\Management\\DashboardController');
        $adminControllers->method('getFirstDashboardRoute')->willReturn('admin');

        $routeGenerator = $this->createStub(AdminRouteGeneratorInterface::class);
        $routeGenerator->method('findRouteName')->willReturn('admin');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn($generatedUrl);

        return new AdminUrlGenerator(
            $this->createStub(AdminContextProviderInterface::class),
            $urlGenerator,
            $adminControllers,
            $routeGenerator,
            new ArrayAdapter(),
        );
    }

    private function createAdminContext(): AdminContext
    {
        $entityDto = new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, new GalleryCategory());

        return AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto));
    }

    private function createController(
        ?GalleryCategoryRepository $galleryCategoryRepository = null,
        ?ContentExporter $contentExporter = null,
        ?GalleryExportProvider $galleryExportProvider = null,
        ?GalleryRepository $galleryRepository = null,
    ): GalleryCategoryCrudController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $galleryCategoryRepository ??= $this->createStub(GalleryCategoryRepository::class);

        return new GalleryCategoryCrudController(
            $galleryRepository ?? $this->createStub(GalleryRepository::class),
            $galleryCategoryRepository,
            new AsciiSlugger(),
            $translator,
            $this->createAdminUrlGenerator(),
            $contentExporter ?? $this->createStub(ContentExporter::class),
            $galleryExportProvider ?? new GalleryExportProvider($galleryCategoryRepository, sys_get_temp_dir()),
        );
    }

    public function testExportSelectionDeniesAccessBelowRoleAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->exportSelection($this->createAdminContext(), new BatchActionDto('exportSelection', [1], GalleryCategory::class, 'token'));
    }

    public function testExportSelectionThrowsBadRequestWhenEntityFqcnMismatches(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $controller->exportSelection($this->createAdminContext(), new BatchActionDto('exportSelection', [1], \stdClass::class, 'token'));
    }

    public function testExportSelectionRedirectsWhenCsrfTokenIsInvalid(): void
    {
        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->never())->method('findBy');

        $controller = $this->createController(galleryCategoryRepository: $categoryRepository);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
        ]));

        $response = $controller->exportSelection($this->createAdminContext(), new BatchActionDto('exportSelection', [1], GalleryCategory::class, 'invalid'));

        $this->assertSame('/management/gallery-categories', $response->getTargetUrl());
    }

    public function testExportSelectionExportsCategoryWithGalleryPhotosAndCoverIndex(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.jpg', 'bytes-1');
        file_put_contents($projectDir . '/public/uploads/p2.jpg', 'bytes-2');

        $gallery = (new Gallery())->setSlug('main')->setTitle('Galerie')->setPosition(0)->setDefault(true);
        $category = (new GalleryCategory())->setGallery($gallery)->setSlug('voyages')->setTitle('Voyages')->setPosition(0);
        $photo1 = (new GalleryPhoto())->setFilename('uploads/p1.jpg')->setAlt('Photo 1')->setPosition(0);
        $photo2 = (new GalleryPhoto())->setFilename('uploads/p2.jpg')->setAlt('Photo 2')->setPosition(1);
        $category->addPhoto($photo1);
        $category->addPhoto($photo2);
        $category->setCoverPhoto($photo2);

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findBy')->willReturn([$category]);

        $contentExporter = $this->createMock(ContentExporter::class);
        $contentExporter->expects($this->once())
            ->method('export')
            ->with('gallery_category', $this->callback(function (array $items) {
                $item = $items[0];
                $this->assertSame('main', $item['gallerySlug']);
                $this->assertSame('voyages', $item['slug']);
                $this->assertCount(2, $item['photos']);
                $this->assertSame(1, $item['coverPhotoIndex']);
                $this->assertArrayHasKey('file', $item['photos'][1]);
                $this->assertArrayNotHasKey('content', $item['photos'][1]);

                return true;
            }), $this->callback(function (array $files) use ($projectDir) {
                $this->assertCount(2, $files);
                sort($files);
                $this->assertSame([
                    $projectDir . '/public/uploads/p1.jpg',
                    $projectDir . '/public/uploads/p2.jpg',
                ], $files);

                return true;
            }))
            ->willReturn(new BinaryFileResponse(tempnam(sys_get_temp_dir(), 'export_test_')));

        $controller = $this->createController(
            galleryCategoryRepository: $categoryRepository,
            contentExporter: $contentExporter,
            galleryExportProvider: new GalleryExportProvider($categoryRepository, $projectDir),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
        ]));

        $controller->exportSelection($this->createAdminContext(), new BatchActionDto('exportSelection', [1], GalleryCategory::class, 'valid'));

        unlink($projectDir . '/public/uploads/p1.jpg');
        unlink($projectDir . '/public/uploads/p2.jpg');
        rmdir($projectDir . '/public/uploads');
        rmdir($projectDir . '/public');
        rmdir($projectDir);
    }

    // --- configureActions --------------------------------------------------------------------------------

    // Detail adds no information beyond what edit already shows - disabled entirely, and a Cancel action lets the admin back out of a create/edit without saving
    public function testConfigureActionsDisablesDetailAndAddsCancelOnNewAndEdit(): void
    {
        $controller = $this->createController();

        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $this->assertContains(Action::DETAIL, $actions->getAsDto(null)->getDisabledActions());
        $this->assertNotNull($actions->getAsDto(Crud::PAGE_NEW)->getAction(Crud::PAGE_NEW, 'cancel'));
        $this->assertNotNull($actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, 'cancel'));
    }

    // --- persistEntity -------------------------------------------------------------------------------------

    // No Gallery picker is exposed, so a category created from the CRUD lands in the default gallery
    public function testPersistEntitySlugifiesTheSlugAndAssignsTheDefaultGallery(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOrCreateDefault')->willReturn($gallery);

        $category = (new GalleryCategory())->setTitle('Été 2024')->setSlug('Été 2024');
        $controller = $this->createController(new GalleryCategoryRepositoryBySlugFixture([]), galleryRepository: $galleryRepository);
        $controller->persistEntity($this->createEntityManagerExpectingPersist($category), $category);

        $this->assertSame($gallery, $category->getGallery());
        $this->assertSame('ete-2024', $category->getSlug());
    }

    // (gallery, slug) is unique, and two different titles can slugify identically
    public function testPersistEntitySuffixesASlugAlreadyUsedInTheSameGallery(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOrCreateDefault')->willReturn($gallery);

        $existing = (new GalleryCategory())->setGallery($gallery)->setSlug('ete-2024');
        $category = (new GalleryCategory())->setTitle('Ete 2024')->setSlug('Ete 2024');

        $controller = $this->createController(
            new GalleryCategoryRepositoryBySlugFixture(['ete-2024' => $existing]),
            galleryRepository: $galleryRepository,
        );
        $controller->persistEntity($this->createEntityManagerExpectingPersist($category), $category);

        $this->assertSame('ete-2024-2', $category->getSlug());
    }

    private function createEntityManagerExpectingPersist(GalleryCategory $category): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($category);
        $entityManager->expects($this->once())->method('flush');

        return $entityManager;
    }
}
