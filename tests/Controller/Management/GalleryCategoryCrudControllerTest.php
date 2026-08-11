<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Entity\Redirect;
use c975L\ConfigBundle\Repository\RedirectRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ContentExporter;
use c975L\GalleryBundle\Controller\Management\GalleryCategoryCrudController;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Management\GalleryExportProvider;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Service\GalleryMediaFactory;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use c975L\GalleryBundle\Service\UploadLimits;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use c975L\UiBundle\Form\TrixEditorType;
use c975L\UiBundle\Management\BlockDataExporter;
use c975L\UiBundle\Service\BlockMoveRowAttrBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\CrudContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\RequestContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Registry\AdminControllerRegistryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Router\AdminRouteGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Translation\TranslatableMessage;
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

    private function createAdminContext(?GalleryCategory $category = null): AdminContext
    {
        $entityDto = new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, $category ?? new GalleryCategory());

        return AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto));
    }

    // addFlash() needs a session-backed request_stack service
    private function createRequestStackWithSession(): RequestStack
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    private function createController(
        ?GalleryCategoryRepository $galleryCategoryRepository = null,
        ?ContentExporter $contentExporter = null,
        ?GalleryExportProvider $galleryExportProvider = null,
        ?RedirectRepository $redirectRepository = null,
    ): GalleryCategoryCrudController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $galleryCategoryRepository ??= $this->createStub(GalleryCategoryRepository::class);

        return new GalleryCategoryCrudController(
            $galleryCategoryRepository,
            new AsciiSlugger(),
            $translator,
            $this->createAdminUrlGenerator(),
            $contentExporter ?? $this->createStub(ContentExporter::class),
            $galleryExportProvider ?? new GalleryExportProvider($galleryCategoryRepository, new BlockDataExporter(sys_get_temp_dir()), sys_get_temp_dir()),
            $this->createStub(AdminContextProviderInterface::class),
            $this->createStub(BlockMoveRowAttrBuilder::class),
            new GalleryMediaFactory(new GalleryMediaSlugger(new AsciiSlugger())),
            new UploadLimits(),
            new GalleryUrlRedirector($redirectRepository ?? $this->createStub(RedirectRepository::class)),
            $this->createConfigService(),
        );
    }

    // Every screen of this CRUD sits behind ConfigBundle's "site-role-editor" entry
    private function createConfigService(): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ROLE_EDITOR');

        return $configService;
    }

    public function testExportSelectionDeniesAccessBelowTheEditorRole(): void
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

    public function testExportSelectionExportsCategoryWithItsMediasAndCoverIndex(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery_export_test_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/public/uploads', 0777, true);
        file_put_contents($projectDir . '/public/uploads/p1.jpg', 'bytes-1');
        file_put_contents($projectDir . '/public/uploads/p2.jpg', 'bytes-2');

        $category = (new GalleryCategory())->setSlug('voyages')->setTitle('Voyages')->setPosition(0);
        $media1 = (new GalleryMedia())->setFilename('uploads/p1.jpg')->setTitle('Media 1')->setSlug('media-1')->setPosition(0);
        $media2 = (new GalleryMedia())->setFilename('uploads/p2.jpg')->setTitle('Media 2')->setSlug('media-2')->setPosition(1);
        $category->addMedia($media1);
        $category->addMedia($media2);
        $category->setCoverMedia($media2);

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findBy')->willReturn([$category]);

        $contentExporter = $this->createMock(ContentExporter::class);
        $contentExporter->expects($this->once())
            ->method('export')
            ->with('gallery_category', $this->callback(function (array $items) {
                $item = $items[0];
                $this->assertSame('voyages', $item['slug']);
                $this->assertCount(2, $item['medias']);
                $this->assertSame(1, $item['coverMediaIndex']);
                $this->assertArrayHasKey('file', $item['medias'][1]);
                $this->assertArrayNotHasKey('content', $item['medias'][1]);

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
            galleryExportProvider: new GalleryExportProvider($categoryRepository, new BlockDataExporter($projectDir), $projectDir),
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

    // --- deleteMedias ------------------------------------------------------------------------------------

    // A category and its medias, ids set the way Doctrine would - the posted ids are matched against them
    private function createCategoryWithMedias(int ...$mediaIds): GalleryCategory
    {
        $category = (new GalleryCategory())->setSlug('voyages')->setTitle('Voyages');
        (new \ReflectionProperty(GalleryCategory::class, 'id'))->setValue($category, 42);

        foreach ($mediaIds as $mediaId) {
            $media = (new GalleryMedia())->setTitle('Media ' . $mediaId);
            (new \ReflectionProperty(GalleryMedia::class, 'id'))->setValue($media, $mediaId);
            $category->addMedia($media);
        }

        return $category;
    }

    private function createDeleteMediasRequest(array $mediaIds, string $token = 'valid'): Request
    {
        return new Request(request: ['_token' => $token, 'mediaIds' => array_map('strval', $mediaIds)]);
    }

    public function testDeleteMediasDeniesAccessBelowTheEditorRole(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->deleteMedias($this->createAdminContext(), $this->createDeleteMediasRequest([1]), $this->createStub(EntityManagerInterface::class));
    }

    // The url is reached with no category resolved at all - nothing to delete medias from
    public function testDeleteMediasThrowsNotFoundWithoutACategory(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $entityDto = new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, null);
        $context = AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto));

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $controller->deleteMedias($context, $this->createDeleteMediasRequest([1]), $this->createStub(EntityManagerInterface::class));
    }

    public function testDeleteMediasRemovesNothingWhenCsrfTokenIsInvalid(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
        ]));

        $response = $controller->deleteMedias(
            $this->createAdminContext($this->createCategoryWithMedias(7)),
            $this->createDeleteMediasRequest([7], 'invalid'),
            $entityManager
        );

        $this->assertSame('/management/gallery-categories', $response->getTargetUrl());
    }

    // Only the checked medias go, and only those of the category the url carries - an id from another category is posted here and must be ignored
    public function testDeleteMediasRemovesOnlyTheCheckedMediasOfTheCategory(): void
    {
        $category = $this->createCategoryWithMedias(7, 8, 9);
        $removed = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(static function (GalleryMedia $media) use (&$removed): void {
            $removed[] = $media->getId();
        });
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $response = $controller->deleteMedias($this->createAdminContext($category), $this->createDeleteMediasRequest([7, 9, 999]), $entityManager);

        $this->assertSame([7, 9], $removed);
        $this->assertSame('/management/gallery-categories', $response->getTargetUrl());
    }

    // The category would keep pointing at a cover that is gone, the join column's "on delete set null" only reaching the row
    public function testDeleteMediasClearsTheCoverItPointedAt(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);
        $category->setCoverMedia($category->getMedias()->first());

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->deleteMedias($this->createAdminContext($category), $this->createDeleteMediasRequest([7]), $this->createStub(EntityManagerInterface::class));

        $this->assertNull($category->getCoverMedia());
    }

    // Nothing checked (a submit slipping past the disabled button) leaves the screen untouched, flash included
    public function testDeleteMediasFlashesNothingWhenTheSelectionIsEmpty(): void
    {
        $requestStack = $this->createRequestStackWithSession();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $requestStack,
        ]));

        $controller->deleteMedias($this->createAdminContext($this->createCategoryWithMedias(7)), $this->createDeleteMediasRequest([]), $entityManager);

        $this->assertSame([], $requestStack->getSession()->getFlashBag()->all());
    }

    // Same 410 the media CRUD leaves behind when it deletes one at a time (see GalleryMediaCrudControllerTest), a media page being declared in the sitemap whichever screen removes it
    public function testDeleteMediasLeavesEachDeletedMediaUrlAnsweringGone(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);
        foreach ($category->getMedias() as $media) {
            $media->setSlug('media-' . $media->getId());
        }

        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
            'router' => $this->createRouter(),
        ]));

        $controller->deleteMedias($this->createAdminContext($category), $this->createDeleteMediasRequest([7]), $entityManager);

        $redirects = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect));
        $this->assertCount(1, $redirects);
        $this->assertSame('/gallery/voyages/media-7', $redirects[0]->getFromPath());
        $this->assertTrue($redirects[0]->isGone());
    }

    // --- editMedias --------------------------------------------------------------------------------------

    // The field is the one the button pressed names, the other button's control travelling with it and left unread
    private function createEditMediasRequest(array $mediaIds, string $field, array $values = [], string $token = 'valid'): Request
    {
        return new Request(request: array_merge([
            '_edit_token' => $token,
            'field' => $field,
            'mediaIds' => array_map('strval', $mediaIds),
        ], $values));
    }

    public function testEditMediasDeniesAccessBelowTheEditorRole(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->editMedias($this->createAdminContext(), $this->createEditMediasRequest([1], 'credits'), $this->createStub(EntityManagerInterface::class));
    }

    public function testEditMediasThrowsNotFoundWithoutACategory(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $entityDto = new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, null);
        $context = AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto));

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $controller->editMedias($context, $this->createEditMediasRequest([1], 'credits'), $this->createStub(EntityManagerInterface::class));
    }

    public function testEditMediasChangesNothingWhenCsrfTokenIsInvalid(): void
    {
        $category = $this->createCategoryWithMedias(7);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
        ]));

        $response = $controller->editMedias(
            $this->createAdminContext($category),
            $this->createEditMediasRequest([7], 'credits', ['credits' => '(c) 975L'], 'invalid'),
            $entityManager
        );

        $this->assertNull($category->getMedias()->first()->getCredits());
        $this->assertSame('/management/gallery-categories', $response->getTargetUrl());
    }

    // Anything but the fields the toolbar offers is refused outright, whatever the media's own setters would accept
    public function testEditMediasChangesNothingOnAnUnknownField(): void
    {
        $category = $this->createCategoryWithMedias(7);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
        ]));

        $response = $controller->editMedias(
            $this->createAdminContext($category),
            $this->createEditMediasRequest([7], 'title', ['title' => 'Renamed']),
            $entityManager
        );

        $this->assertSame('Media 7', $category->getMedias()->first()->getTitle());
        $this->assertSame('/management/gallery-categories', $response->getTargetUrl());
    }

    // Only the checked medias take the credits, and only those of the category the url carries - an id from another category is posted here and must be ignored
    public function testEditMediasWritesTheCreditsOnTheCheckedMediasOnly(): void
    {
        $category = $this->createCategoryWithMedias(7, 8, 9);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $response = $controller->editMedias(
            $this->createAdminContext($category),
            $this->createEditMediasRequest([7, 9, 999], 'credits', ['credits' => '(c) 975L']),
            $entityManager
        );

        $credits = $category->getMedias()->map(static fn (GalleryMedia $media): ?string => $media->getCredits())->toArray();
        $this->assertSame(['(c) 975L', null, '(c) 975L'], array_values($credits));
        $this->assertSame('/management/gallery-categories', $response->getTargetUrl());
    }

    // An empty box clears the credits rather than being ignored - the only way to blank them on a whole selection
    public function testEditMediasClearsTheCreditsWhenTheBoxIsEmpty(): void
    {
        $category = $this->createCategoryWithMedias(7);
        $category->getMedias()->first()->setCredits('(c) 975L');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->editMedias(
            $this->createAdminContext($category),
            $this->createEditMediasRequest([7], 'credits', ['credits' => '']),
            $this->createStub(EntityManagerInterface::class)
        );

        $this->assertNull($category->getMedias()->first()->getCredits());
    }

    // The credits box travels with the "rights reserved" button and must be left alone by it
    public function testEditMediasWritesTheRightsReservedWithoutTouchingTheCredits(): void
    {
        $category = $this->createCategoryWithMedias(7);
        $category->getMedias()->first()->setCredits('(c) 975L');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->editMedias(
            $this->createAdminContext($category),
            $this->createEditMediasRequest([7], 'rightsReserved', ['rightsReserved' => '1', 'credits' => 'Typed but not applied']),
            $this->createStub(EntityManagerInterface::class)
        );

        $this->assertTrue($category->getMedias()->first()->isRightsReserved());
        $this->assertSame('(c) 975L', $category->getMedias()->first()->getCredits());
    }

    // Unchecked is a value of its own - the same button takes the rights back off a selection
    public function testEditMediasUnsetsTheRightsReservedWhenTheBoxIsUnchecked(): void
    {
        $category = $this->createCategoryWithMedias(7);
        $category->getMedias()->first()->setRightsReserved(true);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->editMedias(
            $this->createAdminContext($category),
            $this->createEditMediasRequest([7], 'rightsReserved'),
            $this->createStub(EntityManagerInterface::class)
        );

        $this->assertFalse($category->getMedias()->first()->isRightsReserved());
    }

    // Nothing checked (a submit slipping past the disabled buttons) leaves the screen untouched, flash included
    public function testEditMediasFlashesNothingWhenTheSelectionIsEmpty(): void
    {
        $requestStack = $this->createRequestStackWithSession();

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $requestStack,
        ]));

        $controller->editMedias(
            $this->createAdminContext($this->createCategoryWithMedias(7)),
            $this->createEditMediasRequest([], 'credits', ['credits' => '(c) 975L']),
            $this->createStub(EntityManagerInterface::class)
        );

        $this->assertSame([], $requestStack->getSession()->getFlashBag()->all());
    }

    // --- saveMediasLayout --------------------------------------------------------------------------------

    // The token travels as a header, the body carrying the layout alone (see gallery-media-sort.js)
    private function createMediasLayoutRequest(array $mediaIds, string $coverMediaId = '', string $token = 'valid'): Request
    {
        return new Request(
            request: [
                'mediaOrder' => array_map('strval', $mediaIds),
                'coverMediaId' => $coverMediaId,
            ],
            server: ['HTTP_X_CSRF_TOKEN' => $token],
        );
    }

    public function testSaveMediasLayoutDeniesAccessBelowTheEditorRole(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->saveMediasLayout($this->createAdminContext(), $this->createMediasLayoutRequest([1]), $this->createStub(EntityManagerInterface::class));
    }

    // The url is reached with no category resolved at all - nothing to lay out
    public function testSaveMediasLayoutThrowsNotFoundWithoutACategory(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $entityDto = new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, null);
        $context = AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto));

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $controller->saveMediasLayout($context, $this->createMediasLayoutRequest([1]), $this->createStub(EntityManagerInterface::class));
    }

    public function testSaveMediasLayoutChangesNothingWhenCsrfTokenIsInvalid(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
        ]));

        $response = $controller->saveMediasLayout(
            $this->createAdminContext($category),
            $this->createMediasLayoutRequest([8, 7], '8', 'invalid'),
            $entityManager
        );

        $this->assertNull($category->getCoverMedia());
        $this->assertSame(419, $response->getStatusCode());
    }

    // Renumbered from 0 following the order posted, an id belonging to another category being ignored just as it is on a deletion
    public function testSaveMediasLayoutRenumbersTheMediasInThePostedOrder(): void
    {
        $category = $this->createCategoryWithMedias(7, 8, 9);
        foreach ($category->getMedias() as $index => $media) {
            $media->setPosition($index * 10);
        }

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
        ]));

        $controller->saveMediasLayout($this->createAdminContext($category), $this->createMediasLayoutRequest([9, 999, 7, 8]), $entityManager);

        $positions = [];
        foreach ($category->getMedias() as $media) {
            $positions[$media->getId()] = $media->getPosition();
        }

        $this->assertSame([7 => 1, 8 => 2, 9 => 0], $positions);
    }

    public function testSaveMediasLayoutSetsTheCoverPicked(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
        ]));

        $response = $controller->saveMediasLayout($this->createAdminContext($category), $this->createMediasLayoutRequest([7, 8], '8'), $this->createStub(EntityManagerInterface::class));

        $this->assertSame(8, $category->getCoverMedia()?->getId());
        $this->assertSame(200, $response->getStatusCode());
    }

    // What the "random cover" radio posts, and what the public components fall back to a random media on
    public function testSaveMediasLayoutClearsTheCoverWhenNoMediaIsPicked(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);
        $category->setCoverMedia($category->getMedias()->first());

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
        ]));

        $controller->saveMediasLayout($this->createAdminContext($category), $this->createMediasLayoutRequest([7, 8]), $this->createStub(EntityManagerInterface::class));

        $this->assertNull($category->getCoverMedia());
    }

    // A category must never be left pointing at a media it doesn't hold
    public function testSaveMediasLayoutIgnoresACoverFromAnotherCategory(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
        ]));

        $controller->saveMediasLayout($this->createAdminContext($category), $this->createMediasLayoutRequest([7, 8], '999'), $this->createStub(EntityManagerInterface::class));

        $this->assertNull($category->getCoverMedia());
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

    // A gallery is dropped from its own screen, not only from the row button one screen above - except the catch-all "Non classé", which every media uploaded without a real category falls back to
    public function testConfigureActionsAddsADeleteActionOnTheEditScreen(): void
    {
        $controller = $this->createController();

        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $delete = $actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, Action::DELETE);

        $this->assertNotNull($delete);
        $this->assertTrue($delete->isDisplayed($this->createEntityDto(new GalleryCategory())));
        $this->assertFalse($delete->isDisplayed($this->createEntityDto((new GalleryCategory())->setUncategorized(true))));
    }

    private function createEntityDto(GalleryCategory $category): EntityDto
    {
        return new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, $category);
    }

    // Medias are only ever added from the category they belong to, so each row carries its own upload link (GalleryMediaCrudController has no upload button at all)
    public function testConfigureActionsAddsAnUploadMediasActionOnEachRow(): void
    {
        $controller = $this->createController();

        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $uploadMedias = $actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'uploadMedias');

        $this->assertNotNull($uploadMedias);
        $this->assertFalse($uploadMedias->isGlobalAction());
        // Icon-only, like the edit and delete actions it sits next to
        $this->assertFalse($uploadMedias->getLabel());
    }

    // The edit screen's toolbar sits above the blocks collection, so an upload button there left "Add a UiBlock" as the one under the hand of an admin meaning to add a media - it is rendered down with the medias instead (see gallery_category_edit.html.twig)
    public function testConfigureActionsAddsNoUploadMediasActionOnTheEditScreen(): void
    {
        $controller = $this->createController();

        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $this->assertNull($actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, 'uploadMedias'));
    }

    // The row actions are icon-only, so they cost less width side by side than the dropdown they'd otherwise be folded into
    public function testConfigureCrudShowsTheEntityActionsInlined(): void
    {
        $crud = $this->createController()->configureCrud(Crud::new());

        $this->assertFalse($crud->getAsDto()->showEntityActionsAsDropdown());
    }

    // --- configureFields -----------------------------------------------------------------------------------

    // The listing shows how many medias a category holds instead of the medias themselves, which are managed from the category's own edit screen
    public function testConfigureFieldsCountsTheMediasOnTheIndexWithoutOfferingToSortOnIt(): void
    {
        $fields = iterator_to_array($this->createController()->configureFields(Crud::PAGE_INDEX));

        $mediasCount = null;
        foreach ($fields as $field) {
            if ('mediasCount' === $field->getAsDto()->getProperty()) {
                $mediasCount = $field->getAsDto();
            }
        }

        $this->assertNotNull($mediasCount);
        $this->assertFalse($mediasCount->isSortable());
    }

    // The watermark position's placeholder is translated in the form's own domain, EasyAdmin's here - a plain key would be rendered as it stands, "choice_translation_domain" only covering the choices
    public function testConfigureFieldsCarriesTheDomainOnTheWatermarkPositionPlaceholder(): void
    {
        $placeholder = null;
        foreach ($this->createController()->configureFields(Crud::PAGE_NEW) as $field) {
            $dto = $field->getAsDto();
            if ('watermarkPosition' === $dto->getProperty()) {
                $placeholder = $dto->getFormTypeOption('placeholder');
            }
        }

        $this->assertInstanceOf(TranslatableMessage::class, $placeholder);
        $this->assertSame('label.gallery_watermark_position_default', $placeholder->getMessage());
        $this->assertSame('gallery', $placeholder->getDomain());
    }

    // The editor every other rich-text field of the ecosystem uses, not EasyAdmin's own: its widget is where the rephrase button is wired, and a different form block would leave this field the only one without it
    public function testConfigureFieldsEditsTheDescriptionWithTheEcosystemRichTextEditor(): void
    {
        $description = $this->findFieldDto($this->createController()->configureFields(Crud::PAGE_EDIT), 'description');

        $this->assertNotNull($description);
        $this->assertSame(TrixEditorType::class, $description->getFormType());
        $this->assertFalse($description->isDisplayedOn(Crud::PAGE_INDEX));
    }

    // A category is created to hold medias, so the creation form takes the same batch as the upload screen - only there, an existing category having its own screen for it
    public function testConfigureFieldsOffersTheMediaBatchOnlyWhenCreating(): void
    {
        $batch = [];
        foreach ($this->createController()->configureFields(Crud::PAGE_NEW) as $field) {
            $dto = $field->getAsDto();
            if (\in_array($dto->getProperty(), ['files', 'credits', 'rightsReserved'], true)) {
                $batch[$dto->getProperty()] = $dto;
            }
        }

        $this->assertSame(['files', 'credits', 'rightsReserved'], array_keys($batch));

        foreach ($batch as $property => $dto) {
            $this->assertTrue($dto->isDisplayedOn(Crud::PAGE_NEW), sprintf('The "%s" field must be offered when creating', $property));
            $this->assertFalse($dto->isDisplayedOn(Crud::PAGE_EDIT), sprintf('The "%s" field must not be offered when editing', $property));
        }
    }

    // A category has no files, credits or rights of its own: what is submitted is a batch, turned into medias afterwards (see addMediaBatch)
    public function testTheMediaBatchFieldsAreNotMappedOnTheCategory(): void
    {
        $fields = iterator_to_array($this->createController()->configureFields(Crud::PAGE_NEW));

        foreach ($fields as $field) {
            $dto = $field->getAsDto();
            if (\in_array($dto->getProperty(), ['files', 'credits', 'rightsReserved'], true)) {
                $this->assertFalse($dto->getFormTypeOption('mapped'), sprintf('The "%s" field must not be mapped', $dto->getProperty()));
            }
        }
    }

    // --- new ---------------------------------------------------------------------------------------------

    // Past post_max_size php drops the whole request: without this the category wouldn't be created and the screen would silently redisplay itself, the batch looking like it was never sent
    public function testNewReportsABatchPhpEmptiedInsteadOfRedisplayingSilently(): void
    {
        $requestStack = $this->createRequestStackWithSession();

        $controller = $this->createController();
        $controller->setContainer($this->createContainer(['request_stack' => $requestStack]));

        // What php hands over: a POST carrying nothing at all, the browser having sent 500 MB
        $request = Request::create('/management/gallery/new', 'POST', [], [], [], ['CONTENT_LENGTH' => 500_000_000]);
        $response = $controller->new(AdminContext::forTesting(requestContext: RequestContext::forTesting($request)));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertTrue($requestStack->getSession()->getFlashBag()->has('danger'));
    }

    // --- media batch -------------------------------------------------------------------------------------

    // The files picked on the creation form become the category's medias right away, saved with it by the cascade on GalleryCategory::$medias
    public function testTheFilesPickedAtCreationBecomeTheMediasOfTheCategory(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $event = new FormEvent(
            $this->createBatchForm([new UploadedFile(__FILE__, 'col_du-galibier.webp', test: true)], 'Studio 975L', true),
            $category
        );

        ($this->captureMediaBatchListener())($event);

        $this->assertCount(1, $category->getMedias());
        $this->assertSame('Col Du Galibier', $category->getMedias()->first()->getTitle());
        $this->assertSame('Studio 975L', $category->getMedias()->first()->getCredits());
        $this->assertTrue($category->getMedias()->first()->isRightsReserved());
    }

    // The watermark is answered on this screen too, a category being filled the same way whichever screen does it - and the corner picked here reaches the media, which is what the listener that stamps reads
    public function testTheWatermarkPickedAtCreationReachesTheMedias(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $event = new FormEvent(
            $this->createBatchForm(
                [new UploadedFile(__FILE__, 'col_du-galibier.webp', test: true)],
                null,
                false,
                watermark: true,
                watermarkPosition: VichWatermarkableInterface::POSITION_TOP_LEFT,
            ),
            $category
        );

        ($this->captureMediaBatchListener())($event);

        $this->assertTrue($category->getMedias()->first()->wantsWatermark());
        $this->assertSame(VichWatermarkableInterface::POSITION_TOP_LEFT, $category->getMedias()->first()->getWatermarkPosition());
    }

    // A creation form submitted without a single file is a category and nothing else
    public function testNoMediaIsCreatedWhenNoFileWasPicked(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');

        ($this->captureMediaBatchListener())(new FormEvent($this->createBatchForm(null, null, false), $category));

        $this->assertTrue($category->getMedias()->isEmpty());
    }

    private function createBatchForm(?array $files, ?string $credits, bool $rightsReserved, ?string $titleRoot = null, bool $keepOriginals = false, bool $watermark = false, ?string $watermarkPosition = null): FormInterface
    {
        $submitted = [
            'files' => $files,
            'credits' => $credits,
            'rightsReserved' => $rightsReserved,
            'titleRoot' => $titleRoot,
            'keepOriginals' => $keepOriginals,
            'watermark' => $watermark,
            'watermarkPosition' => $watermarkPosition,
        ];

        $children = [];
        foreach ($submitted as $name => $data) {
            $child = $this->createStub(FormInterface::class);
            $child->method('getData')->willReturn($data);
            $children[$name] = $child;
        }

        $form = $this->createStub(FormInterface::class);
        $form->method('get')->willReturnCallback(static fn (string $name): FormInterface => $children[$name]);

        return $form;
    }

    private function captureMediaBatchListener(): callable
    {
        $listener = null;
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('addEventListener')->willReturnCallback(function (string $eventName, callable $callback) use (&$listener, $builder) {
            if (FormEvents::POST_SUBMIT === $eventName) {
                $listener = $callback;
            }

            return $builder;
        });

        (new \ReflectionMethod(GalleryCategoryCrudController::class, 'addMediaBatch'))->invoke($this->createController(), $builder);

        $this->assertIsCallable($listener);

        return $listener;
    }

    // --- configureResponseParameters -----------------------------------------------------------------------

    // The edit screen lists the category's medias, each thumbnail opening the media edit form - and carrying the category, which is what sends the admin back here once the media is saved or deleted
    public function testConfigureResponseParametersBuildsAnEditUrlPerMediaOfTheCategory(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        (new \ReflectionProperty(GalleryCategory::class, 'id'))->setValue($category, 42);
        $media = new GalleryMedia();
        (new \ReflectionProperty(GalleryMedia::class, 'id'))->setValue($media, 7);
        $category->addMedia($media);

        $entityDto = new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, $category);

        $parameters = $this->createControllerWithRouter()->configureResponseParameters(KeyValueStore::new([
            'pageName' => Crud::PAGE_EDIT,
            'entity' => $entityDto,
        ]));

        $this->assertSame([7 => '/management/gallery-categories'], $parameters->get('media_edit_urls'));
    }

    // The upload button is rendered with the medias rather than in the toolbar, where it sat above the blocks collection and its own "add" button
    public function testConfigureResponseParametersHandsTheUploadUrlToTheEditScreen(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        (new \ReflectionProperty(GalleryCategory::class, 'id'))->setValue($category, 42);

        $entityDto = new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, $category);

        $parameters = $this->createControllerWithRouter()->configureResponseParameters(KeyValueStore::new([
            'pageName' => Crud::PAGE_EDIT,
            'entity' => $entityDto,
        ]));

        $this->assertSame('/gallery/42', $parameters->get('media_upload_url'));
    }

    // Nothing to list anywhere else, and the batch actions go through here too with no page name at all
    public function testConfigureResponseParametersLeavesTheOtherPagesUntouched(): void
    {
        $parameters = $this->createController()->configureResponseParameters(KeyValueStore::new(['pageName' => Crud::PAGE_INDEX]));

        $this->assertNull($parameters->get('media_edit_urls'));
        $this->assertNull($parameters->get('upload_limits'));
    }

    // The creation screen weighs the files picked against the server's own ceilings before sending them, and has no other way to reach them
    public function testConfigureResponseParametersHandsTheUploadLimitsToTheCreationScreen(): void
    {
        $parameters = $this->createController()->configureResponseParameters(KeyValueStore::new(['pageName' => Crud::PAGE_NEW]));

        $this->assertInstanceOf(UploadLimits::class, $parameters->get('upload_limits'));
    }

    // --- slug normalization --------------------------------------------------------------------------------

    // A slug already taken is now reported by the entity's unique constraint instead of being silently suffixed, which requires the submitted slug to be normalized before validation runs - hence PRE_SUBMIT rather than persistEntity
    public function testTheSubmittedSlugIsSlugifiedBeforeValidation(): void
    {
        $event = new FormEvent($this->createStub(FormInterface::class), ['slug' => 'Été 2024 !', 'title' => 'Été 2024']);
        ($this->captureSlugNormalizer())($event);

        $this->assertSame(['slug' => 'ete-2024', 'title' => 'Été 2024'], $event->getData());
    }

    public function testNothingIsNormalizedWhenNoSlugIsSubmitted(): void
    {
        $event = new FormEvent($this->createStub(FormInterface::class), ['title' => 'Été 2024']);
        ($this->captureSlugNormalizer())($event);

        $this->assertSame(['title' => 'Été 2024'], $event->getData());
    }

    // EasyAdmin's SlugField stops following its target field once the slug holds a value, so an edit form would otherwise keep the old slug for a renamed category - which is exactly what the "title-confirm" warning announces will change
    public function testTheSlugOfARenamedCategoryFollowsItsNewTitle(): void
    {
        $event = new FormEvent($this->createFormHolding($this->createCategoryWithMedias()), ['slug' => 'voyages', 'title' => 'Voyages d\'été']);
        ($this->captureSlugNormalizer())($event);

        $this->assertSame(['slug' => 'voyages-d-ete', 'title' => 'Voyages d\'été'], $event->getData());
    }

    public function testTheSlugIsLeftAloneWhenTheTitleIsUnchanged(): void
    {
        $event = new FormEvent($this->createFormHolding($this->createCategoryWithMedias()), ['slug' => 'archives', 'title' => 'Voyages']);
        ($this->captureSlugNormalizer())($event);

        $this->assertSame(['slug' => 'archives', 'title' => 'Voyages'], $event->getData());
    }

    // A category being created has no url to preserve, and its slug is the one the admin picked on the form
    public function testTheSlugOfACategoryBeingCreatedDoesNotFollowTheTitle(): void
    {
        $event = new FormEvent($this->createFormHolding((new GalleryCategory())->setTitle('Voyages')), ['slug' => 'archives', 'title' => 'Voyages d\'été']);
        ($this->captureSlugNormalizer())($event);

        $this->assertSame(['slug' => 'archives', 'title' => 'Voyages d\'été'], $event->getData());
    }

    private function createFormHolding(GalleryCategory $category): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getData')->willReturn($category);

        return $form;
    }

    private function captureSlugNormalizer(): callable
    {
        $listener = null;
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('addEventListener')->willReturnCallback(function (string $eventName, callable $callback) use (&$listener, $builder) {
            if (FormEvents::PRE_SUBMIT === $eventName) {
                $listener = $callback;
            }

            return $builder;
        });

        (new \ReflectionMethod(GalleryCategoryCrudController::class, 'addSlugNormalizer'))->invoke($this->createController(), $builder);

        $this->assertIsCallable($listener);

        return $listener;
    }

    // --- title-confirm and slug help -----------------------------------------------------------------------

    // The modal the controller reuses is only rendered on the edit/index/detail pages, so the attributes must stay off the creation form
    public function testConfigureFieldsWarnsBeforeTheTitleOfAnExistingCategoryIsEdited(): void
    {
        $title = $this->findFieldDto($this->createController()->configureFields(Crud::PAGE_EDIT), 'title');

        $this->assertSame('title-confirm', $title?->getFormTypeOptions()['attr']['data-controller'] ?? null);
        $this->assertSame('confirm.title_change', $title?->getFormTypeOptions()['attr']['data-title-confirm-message-value'] ?? null);
    }

    public function testConfigureFieldsDoesNotWarnOnTheCreationForm(): void
    {
        $title = $this->findFieldDto($this->createController()->configureFields(Crud::PAGE_NEW), 'title');

        $this->assertArrayNotHasKey('data-controller', $title?->getFormTypeOptions()['attr'] ?? []);
    }

    public function testConfigureFieldsExplainsWhatTheSlugIsFor(): void
    {
        $slug = $this->findFieldDto($this->createController()->configureFields(Crud::PAGE_EDIT), 'slug');

        $help = $slug?->getHelp();
        $this->assertInstanceOf(TranslatableMessage::class, $help);
        $this->assertSame('label.slug_help', $help->getMessage());
    }

    private function findFieldDto(iterable $fields, string $property): ?FieldDto
    {
        foreach ($fields as $field) {
            if ($property === $field->getAsDto()->getProperty()) {
                return $field->getAsDto();
            }
        }

        return null;
    }

    // --- updateEntity --------------------------------------------------------------------------------------

    public function testUpdateEntityRedirectsTheOldUrlToTheNewOne(): void
    {
        $category = (new GalleryCategory())->setTitle('Voyages d\'été')->setSlug('voyages-d-ete');
        $persisted = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($this->createUnitOfWorkHolding(['slug' => 'voyages']));
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createControllerWithRouter($this->createRedirectRepository())->updateEntity($entityManager, $category);

        $redirects = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect));
        $this->assertCount(2, $redirects);
        $this->assertSame('/gallery/voyages', $redirects[0]->getFromPath());
        $this->assertSame('/gallery/voyages-d-ete', $redirects[0]->getToUrl());
        $this->assertTrue($redirects[0]->isPermanent());
    }

    // The category's slug is the segment above each of its medias, so its rename moves their urls too - a wildcard row sends them to the renamed category rather than leaving each media to 404
    public function testUpdateEntityRedirectsTheMediaUrlsUnderTheOldSlugToo(): void
    {
        $category = (new GalleryCategory())->setTitle('Voyages d\'été')->setSlug('voyages-d-ete');
        $persisted = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($this->createUnitOfWorkHolding(['slug' => 'voyages']));
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createControllerWithRouter($this->createRedirectRepository())->updateEntity($entityManager, $category);

        $redirects = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect));
        $this->assertSame('/gallery/voyages/*', $redirects[1]->getFromPath());
        $this->assertSame('/gallery/voyages-d-ete', $redirects[1]->getToUrl());
    }

    public function testUpdateEntityAddsNoRedirectWhenTheSlugIsUnchanged(): void
    {
        $category = (new GalleryCategory())->setTitle('Voyages')->setSlug('voyages');

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($this->createUnitOfWorkHolding(['slug' => 'voyages']));
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->assertNotInstanceOf(Redirect::class, $entity);
        });

        $this->createControllerWithRouter($this->createRedirectRepository())->updateEntity($entityManager, $category);
    }

    // Renaming a category back to what it was would otherwise leave the two rows pointing at each other
    public function testUpdateEntityDropsTheRedirectComingBackTheOtherWay(): void
    {
        $category = (new GalleryCategory())->setTitle('Voyages')->setSlug('voyages');
        $reverse = (new Redirect())->setFromPath('/gallery/voyages')->setToUrl('/gallery/archives');
        $removed = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($this->createUnitOfWorkHolding(['slug' => 'archives']));
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $this->createControllerWithRouter($this->createRedirectRepository(['/gallery/voyages' => $reverse]))->updateEntity($entityManager, $category);

        // Both rows written by the rename point back at the same url, so both find the same reverse row - Doctrine ignores the second remove(), the row is dropped once
        $this->assertSame([$reverse], array_values(array_unique($removed, \SORT_REGULAR)));
    }

    // A second rename reuses the row the first one left behind rather than adding another one for the same old url
    public function testUpdateEntityReusesTheRedirectTheOldSlugAlreadyHad(): void
    {
        $category = (new GalleryCategory())->setTitle('Voyages d\'été')->setSlug('voyages-d-ete');
        $existing = (new Redirect())->setFromPath('/gallery/voyages')->setToUrl('/gallery/somewhere-else');
        $persisted = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($this->createUnitOfWorkHolding(['slug' => 'voyages']));
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createControllerWithRouter($this->createRedirectRepository(['/gallery/voyages' => $existing]))->updateEntity($entityManager, $category);

        $this->assertContains($existing, $persisted);
        $this->assertSame('/gallery/voyages-d-ete', $existing->getToUrl());
    }

    // --- persistEntity -----------------------------------------------------------------------------------

    // A slug freed by an earlier deletion is still answering 410, and RedirectSubscriber runs before the router - the wildcard goes too, and so does the url of each media the creation form brought along
    public function testPersistEntityLiftsTheGoneRowsOfASlugCreatedAgain(): void
    {
        $category = (new GalleryCategory())->setTitle('Voyages')->setSlug('voyages');
        $category->addMedia((new GalleryMedia())->setSlug('mont-blanc'));

        $gone = [
            '/gallery/voyages' => (new Redirect())->setFromPath('/gallery/voyages')->setGone(true),
            '/gallery/voyages/*' => (new Redirect())->setFromPath('/gallery/voyages/*')->setGone(true),
            '/gallery/voyages/mont-blanc' => (new Redirect())->setFromPath('/gallery/voyages/mont-blanc')->setGone(true),
        ];
        $removed = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $this->createControllerWithRouter($this->createRedirectRepository($gone))->persistEntity($entityManager, $category);

        $this->assertSame(array_values($gone), $removed);
    }

    // A row redirecting somewhere is deliberate: creating a category under its old url must not drop the redirect its visitors follow
    public function testPersistEntityKeepsARowThatStillRedirects(): void
    {
        $category = (new GalleryCategory())->setTitle('Voyages')->setSlug('voyages');
        $redirect = (new Redirect())->setFromPath('/gallery/voyages')->setToUrl('/gallery/vacances');
        $removed = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $this->createControllerWithRouter($this->createRedirectRepository(['/gallery/voyages' => $redirect]))->persistEntity($entityManager, $category);

        $this->assertSame([], $removed);
    }

    // --- deleteEntity ------------------------------------------------------------------------------------

    // The category page and every media page under it are declared in the sitemap (see GallerySitemapProvider), so the urls are left answering 410 - the medias through a single wildcard row rather than one row each
    public function testDeleteEntityLeavesTheCategoryAndEverythingUnderItAnsweringGone(): void
    {
        $category = (new GalleryCategory())->setTitle('Voyages')->setSlug('voyages');
        $persisted = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createControllerWithRouter($this->createRedirectRepository())->deleteEntity($entityManager, $category);

        $redirects = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect));
        $this->assertSame(['/gallery/voyages', '/gallery/voyages/*'], array_map(static fn (Redirect $redirect): ?string => $redirect->getFromPath(), $redirects));
        $this->assertNull($redirects[0]->getToUrl());
        $this->assertTrue($redirects[0]->isGone());
        $this->assertTrue($redirects[1]->isGone());
    }

    // The public urls the redirect is built from are generated, the first segment being the configured route prefix (see GalleryRoutePrefix) - here the default one
    // A media route carries a slug below the category, so the two are told apart by the parameters they are given rather than by the route name a stub does not resolve
    private function createRouter(): RouterInterface
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters = []): string => '/gallery/' . ($parameters['category'] ?? '') . (isset($parameters['slug']) ? '/' . $parameters['slug'] : ''));

        return $router;
    }

    private function createControllerWithRouter(?RedirectRepository $redirectRepository = null): GalleryCategoryCrudController
    {
        $controller = $this->createController(redirectRepository: $redirectRepository);
        $controller->setContainer($this->createContainer(['router' => $this->createRouter()]));

        return $controller;
    }

    private function createUnitOfWorkHolding(array $originalData): UnitOfWork
    {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturn($originalData);

        return $unitOfWork;
    }

    /** @param array<string, Redirect> $byFromPath */
    private function createRedirectRepository(array $byFromPath = []): RedirectRepository
    {
        $repository = $this->createStub(RedirectRepository::class);
        $repository->method('findOneByFromPath')->willReturnCallback(static fn (string $fromPath): ?Redirect => $byFromPath[$fromPath] ?? null);

        return $repository;
    }
}
