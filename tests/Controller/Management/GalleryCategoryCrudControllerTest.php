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
use c975L\GalleryBundle\Contract\GalleryCustomizationProviderInterface;
use c975L\GalleryBundle\Controller\Management\GalleryCategoryCrudController;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Management\GalleryExportProvider;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Service\GalleryCustomizationRegistry;
use c975L\GalleryBundle\Service\GalleryLatestProvider;
use c975L\GalleryBundle\Service\GalleryMediaArchiver;
use c975L\GalleryBundle\Service\GalleryMediaFactory;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use c975L\GalleryBundle\Service\UploadLimits;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use c975L\UiBundle\Form\TrixEditorType;
use c975L\UiBundle\Management\BlockDataExporter;
use c975L\UiBundle\Repository\RatingRepository;
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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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
        $manager->method('getToken')->willReturnCallback(static fn (string $tokenId): CsrfToken => new CsrfToken($tokenId, 'token'));

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

        $requestStack = new RequestStack([$request]);

        return $requestStack;
    }

    private function createController(
        ?GalleryCategoryRepository $galleryCategoryRepository = null,
        ?ContentExporter $contentExporter = null,
        ?GalleryExportProvider $galleryExportProvider = null,
        ?RedirectRepository $redirectRepository = null,
        ?RequestStack $requestStack = null,
        ?GalleryMediaRepository $galleryMediaRepository = null,
        ?GalleryMediaArchiver $galleryMediaArchiver = null,
        ?GalleryLatestProvider $latestProvider = null,
        ?GalleryCustomizationRegistry $customizationRegistry = null,
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
            $requestStack ?? new RequestStack([new Request()]),
            $galleryMediaRepository ?? $this->createStub(GalleryMediaRepository::class),
            $this->createCsrfTokenManager(true),
            $galleryMediaArchiver ?? new GalleryMediaArchiver(sys_get_temp_dir()),
            $latestProvider ?? $this->createStub(GalleryLatestProvider::class),
            $customizationRegistry ?? new GalleryCustomizationRegistry([]),
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

        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages')->setPosition(0);
        $media1 = new GalleryMedia()->setFilename('uploads/p1.jpg')->setTitle('Media 1')->setSlug('media-1')->setPosition(0);
        $media2 = new GalleryMedia()->setFilename('uploads/p2.jpg')->setTitle('Media 2')->setSlug('media-2')->setPosition(1);
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
        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        new \ReflectionProperty(GalleryCategory::class, 'id')->setValue($category, 42);

        foreach ($mediaIds as $mediaId) {
            $media = new GalleryMedia()->setTitle('Media ' . $mediaId);
            new \ReflectionProperty(GalleryMedia::class, 'id')->setValue($media, $mediaId);
            $category->addMedia($media);
        }

        return $category;
    }

    // The state the trash screen's own actions work on - restoring or dropping a media for good only ever reaches one already flagged
    private function createCategoryWithTrashedMedias(int ...$mediaIds): GalleryCategory
    {
        $category = $this->createCategoryWithMedias(...$mediaIds);
        foreach ($category->getMedias() as $media) {
            $media->setIsDeleted(true);
        }

        return $category;
    }

    private function createDeleteMediasRequest(array $mediaIds, string $token = 'valid'): Request
    {
        return new Request(request: ['_token' => $token, 'mediaIds' => array_map(strval(...), $mediaIds)]);
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

    // Only the checked medias go to the trash, and only those of the category the url carries - an id from another category is posted here and must be ignored
    // Nothing is removed at all: the whole point of the trash is that the rows and their files stay until deleteMediasPermanently() is asked for
    public function testDeleteMediasTrashesOnlyTheCheckedMediasOfTheCategory(): void
    {
        $category = $this->createCategoryWithMedias(7, 8, 9);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $response = $controller->deleteMedias($this->createAdminContext($category), $this->createDeleteMediasRequest([7, 9, 999]), $entityManager);

        $trashed = [];
        foreach ($category->getMedias() as $media) {
            if ($media->isDeleted()) {
                $trashed[] = $media->getId();
            }
        }

        $this->assertSame([7, 9], $trashed);
        $this->assertSame('/management/gallery-categories', $response->getTargetUrl());
    }

    // The mirror action, on the same selection form: the checked medias come back to the grid and nothing else moves
    public function testRestoreMediasPutsOnlyTheCheckedMediasBack(): void
    {
        $category = $this->createCategoryWithMedias(7, 8, 9);
        foreach ($category->getMedias() as $media) {
            $media->setIsDeleted(true);
        }

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->restoreMedias($this->createAdminContext($category), $this->createDeleteMediasRequest([7, 9, 999]), $entityManager);

        $stillTrashed = [];
        foreach ($category->getMedias() as $media) {
            if ($media->isDeleted()) {
                $stillTrashed[] = $media->getId();
            }
        }

        $this->assertSame([8], $stillTrashed);
    }

    // An upload made while a media sat in the trash counts nothing of the trash (see GalleryMediaFactory::nextPosition), so what comes back takes the next free rank rather than the one it held
    public function testRestoreMediasGivesTheRestoredMediasARankOfTheirOwn(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);
        $trashed = $category->getMedias()->first();
        $trashed->setIsDeleted(true)->setPosition(0);
        $category->getMedias()->last()->setPosition(0);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->restoreMedias($this->createAdminContext($category), $this->createDeleteMediasRequest([7]), $this->createStub(EntityManagerInterface::class));

        $this->assertSame(1, $trashed->getPosition());
        $this->assertSame(0, $category->getMedias()->last()->getPosition());
    }

    // The one path of the bundle that actually removes a media, and the only one that reaches its files (see GalleryMediaDerivativeCleanupListener)
    public function testDeleteMediasPermanentlyRemovesOnlyTheCheckedMedias(): void
    {
        $category = $this->createCategoryWithTrashedMedias(7, 8, 9);
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

        $controller->deleteMediasPermanently($this->createAdminContext($category), $this->createDeleteMediasRequest([7, 9, 999]), $entityManager, $this->createRatingRepository());

        $this->assertSame([7, 9], $removed);
    }

    // A like hangs off "gallery_media" + id rather than off a relation (see c975L\UiBundle\Entity\Rating), so nothing cascades it: the medias dropped for good take theirs with them, and only those
    public function testDeleteMediasPermanentlyDropsTheLikesOfTheMediasItRemoves(): void
    {
        $category = $this->createCategoryWithTrashedMedias(7, 8, 9);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->deleteMediasPermanently($this->createAdminContext($category), $this->createDeleteMediasRequest([7, 9]), $this->createStub(EntityManagerInterface::class), $this->createRatingRepository());

        $this->assertSame(['gallery_media#7', 'gallery_media#9'], $this->ratingsDeletedFor);
        $this->assertSame(1, $this->ratingsDeleteCalls);
    }

    // The likes are dropped by a query of their own, which no transaction takes back: a flush that fails leaves the medias in place, and they must still have theirs
    public function testDeleteMediasPermanentlyKeepsTheLikesWhenTheFlushFails(): void
    {
        $category = $this->createCategoryWithTrashedMedias(7, 9);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException(new \RuntimeException('flush failed'));

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        try {
            $controller->deleteMediasPermanently($this->createAdminContext($category), $this->createDeleteMediasRequest([7, 9]), $entityManager, $this->createRatingRepository());
            $this->fail('The failing flush should have surfaced');
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->ratingsDeletedFor);
    }

    // The normal grid and the trash share one token, so the selection is kept to the medias of the screen the action belongs to - a post forged from the normal grid would otherwise skip the trash the two-step deletion is built on
    public function testDeleteMediasPermanentlyLeavesMediasThatAreNotInTheTrash(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->deleteMediasPermanently($this->createAdminContext($category), $this->createDeleteMediasRequest([7, 8]), $entityManager, $this->createRatingRepository());
    }

    // The mirror of the check above: a media still showing in the grid is not something the trash screen puts back
    public function testRestoreMediasLeavesMediasThatAreNotInTheTrash(): void
    {
        $category = $this->createCategoryWithMedias(7);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->restoreMedias($this->createAdminContext($category), $this->createDeleteMediasRequest([7]), $this->createStub(EntityManagerInterface::class));

        $this->assertFalse($category->getMedias()->first()->isDeleted());
    }

    // A media already in the trash is not moved there twice - the selection of the normal grid is kept to what it actually shows
    public function testDeleteMediasLeavesMediasAlreadyInTheTrash(): void
    {
        $category = $this->createCategoryWithTrashedMedias(7);
        $category->setCoverMedia($category->getMedias()->first());

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->deleteMedias($this->createAdminContext($category), $this->createDeleteMediasRequest([7]), $this->createStub(EntityManagerInterface::class));

        $this->assertNotNull($category->getCoverMedia());
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

    // The 410 is now the permanent deletion's, not the move to trash's: a media page is declared in the sitemap, but a media that can still be restored has no url to declare gone
    public function testDeleteMediasPermanentlyLeavesEachMediaUrlAnsweringGone(): void
    {
        $category = $this->createCategoryWithTrashedMedias(7, 8);
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

        $controller->deleteMediasPermanently($this->createAdminContext($category), $this->createDeleteMediasRequest([7]), $entityManager, $this->createRatingRepository());

        $redirects = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect));
        $this->assertCount(1, $redirects);
        $this->assertSame('/gallery/voyages/media-7', $redirects[0]->getFromPath());
        $this->assertTrue($redirects[0]->isGone());
    }

    // The move to trash leaves the redirect table alone - the url answers 410 from the row itself while the media can still come back (see GalleryController::resolveCategoryAndMedia)
    public function testDeleteMediasRecordsNoGoneRedirect(): void
    {
        $category = $this->createCategoryWithMedias(7);
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

        $this->assertSame([], array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect)));
    }

    // --- editMedias --------------------------------------------------------------------------------------

    // The field is the one the button pressed names, the other button's control travelling with it and left unread
    private function createEditMediasRequest(array $mediaIds, string $field, array $values = [], string $token = 'valid'): Request
    {
        return new Request(request: array_merge([
            '_edit_token' => $token,
            'field' => $field,
            'mediaIds' => array_map(strval(...), $mediaIds),
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

    // --- downloadMedias ----------------------------------------------------------------------------------

    // The same selection form as the trash and the credits buttons, the pressed one naming which files it wants
    private function createDownloadMediasRequest(array $mediaIds, string $variant = 'highres', string $token = 'valid'): Request
    {
        return new Request(request: [
            '_token' => $token,
            'variant' => $variant,
            'mediaIds' => array_map(strval(...), $mediaIds),
        ]);
    }

    // A category holding one media whose highres file really sits under the given project dir
    private function createCategoryWithFilesIn(string $projectDir): GalleryCategory
    {
        $category = $this->createCategoryWithMedias(7);
        foreach ($category->getMedias() as $media) {
            $media->setSlug('mont-blanc')->setFilename('medias/gallery/voyages/voyages-abc-123.webp');
        }

        $path = $projectDir . '/public/medias/gallery/voyages';
        mkdir($path, 0777, true);
        file_put_contents($path . '/voyages-abc-123-highres.webp', 'file');

        return $category;
    }

    public function testDownloadMediasDeniesAccessBelowTheEditorRole(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->downloadMedias($this->createAdminContext(), $this->createDownloadMediasRequest([1]));
    }

    // The url is reached with no category resolved at all - nothing to download from
    public function testDownloadMediasThrowsNotFoundWithoutACategory(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $context = AdminContext::forTesting(crudContext: CrudContext::forTesting(
            entityDto: new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, null),
        ));

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $controller->downloadMedias($context, $this->createDownloadMediasRequest([1]));
    }

    // A variant nobody's button posts is a forged request, answered exactly as an invalid token is
    public function testDownloadMediasRefusesAnUnknownVariant(): void
    {
        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
        ]));

        $response = $controller->downloadMedias(
            $this->createAdminContext($this->createCategoryWithMedias(7)),
            $this->createDownloadMediasRequest([7], 'thumbnail')
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testDownloadMediasRefusesAnInvalidToken(): void
    {
        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
        ]));

        $response = $controller->downloadMedias(
            $this->createAdminContext($this->createCategoryWithMedias(7)),
            $this->createDownloadMediasRequest([7], token: 'invalid')
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    // The files of the checked medias come back as the archive itself, not as a redirect
    public function testDownloadMediasHandsBackTheArchive(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery-download-test-' . uniqid();
        $category = $this->createCategoryWithFilesIn($projectDir);

        $controller = $this->createController(galleryMediaArchiver: new GalleryMediaArchiver($projectDir));
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $response = $controller->downloadMedias($this->createAdminContext($category), $this->createDownloadMediasRequest([7]));

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));

        unlink($response->getFile()->getPathname());
        new Filesystem()->remove($projectDir);
    }

    // The one selection action that reads the trash too: a photo waiting to be dropped is exactly the one whose originals are worth getting back first
    public function testDownloadMediasReachesTheTrashedMediasToo(): void
    {
        $projectDir = sys_get_temp_dir() . '/gallery-download-trash-test-' . uniqid();
        $category = $this->createCategoryWithFilesIn($projectDir);
        foreach ($category->getMedias() as $media) {
            $media->setIsDeleted(true);
        }

        $controller = $this->createController(galleryMediaArchiver: new GalleryMediaArchiver($projectDir));
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $response = $controller->downloadMedias($this->createAdminContext($category), $this->createDownloadMediasRequest([7]));

        $this->assertInstanceOf(BinaryFileResponse::class, $response);

        unlink($response->getFile()->getPathname());
        new Filesystem()->remove($projectDir);
    }

    // Originals asked of a batch uploaded without keeping any: a message rather than an empty zip
    public function testDownloadMediasFlashesWhenTheSelectionHasNoFile(): void
    {
        $requestStack = $this->createRequestStackWithSession();

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->downloadMedias(
            $this->createAdminContext($this->createCategoryWithMedias(7)),
            $this->createDownloadMediasRequest([7], 'original')
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(['warning' => ['label.gallery_medias_download_empty']], $requestStack->getSession()->getFlashBag()->all());
    }

    // Nothing checked (a submit slipping past the disabled button) asks for no archive and says nothing
    public function testDownloadMediasDoesNothingOnAnEmptySelection(): void
    {
        $requestStack = $this->createRequestStackWithSession();

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->downloadMedias(
            $this->createAdminContext($this->createCategoryWithMedias(7)),
            $this->createDownloadMediasRequest([])
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame([], $requestStack->getSession()->getFlashBag()->all());
    }

    // A selection past the cap is refused before a byte is written, its own size stated
    public function testDownloadMediasRefusesASelectionPastTheCap(): void
    {
        $requestStack = $this->createRequestStackWithSession();

        $archiver = $this->createStub(GalleryMediaArchiver::class);
        $archiver->method('weigh')->willReturn(GalleryMediaArchiver::MAX_TOTAL_BYTES + 1);

        $controller = $this->createController(galleryMediaArchiver: $archiver);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->downloadMedias(
            $this->createAdminContext($this->createCategoryWithMedias(7)),
            $this->createDownloadMediasRequest([7])
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(['danger' => ['label.gallery_medias_download_too_large']], $requestStack->getSession()->getFlashBag()->all());
    }

    // --- saveMediasLayout --------------------------------------------------------------------------------

    // The token travels as a header, the body carrying the layout alone (see gallery-media-sort.js)
    private function createMediasLayoutRequest(array $mediaIds, string $coverMediaId = '', string $token = 'valid'): Request
    {
        return new Request(
            request: [
                'mediaOrder' => array_map(strval(...), $mediaIds),
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

    // The trash screen renders neither the drag handles nor the cover radios, but a post reaching here anyway must not renumber trashed medias over the positions of the ones still online, nor make one of them the cover
    public function testSaveMediasLayoutIgnoresTrashedMedias(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);
        $trashed = $category->getMedias()->first();
        $trashed->setIsDeleted(true)->setPosition(5);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
        ]));

        $controller->saveMediasLayout($this->createAdminContext($category), $this->createMediasLayoutRequest([7, 8], '7'), $this->createStub(EntityManagerInterface::class));

        $this->assertSame(5, $trashed->getPosition());
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
        $this->assertFalse($delete->isDisplayed($this->createEntityDto(new GalleryCategory()->setUncategorized(true))));
    }

    private function createEntityDto(GalleryCategory $category): EntityDto
    {
        return new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, $category);
    }

    // "Delete" only moves a category to the trash now, so the button says so rather than promising a removal it does not perform
    public function testConfigureActionsLabelsTheDeleteActionAsAMoveToTheTrash(): void
    {
        $actions = $this->createController()->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $label = $actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, Action::DELETE)->getLabel();

        $this->assertInstanceOf(TranslatableMessage::class, $label);
        $this->assertSame('action.move_to_trash', $label->getMessage());
    }

    // In the trash a category is off the site and takes no upload, so the actions assuming the opposite go away and the two that only mean anything there appear - "exportSelection" deliberately stays, a category being carried elsewhere or kept aside before it is dropped for good
    public function testConfigureActionsAddsTheTrashActionsOnTheTrashView(): void
    {
        $controller = $this->createController(requestStack: new RequestStack([new Request(query: ['trash' => 1])]));

        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $index = $actions->getAsDto(Crud::PAGE_INDEX);
        $disabled = $actions->getAsDto(null)->getDisabledActions();

        $this->assertNotNull($index->getAction(Crud::PAGE_INDEX, 'restore'));
        $this->assertNotNull($index->getAction(Crud::PAGE_INDEX, 'deletePermanently'));
        $this->assertContains(Action::NEW, $disabled);
        $this->assertContains(Action::DELETE, $disabled);
        $this->assertContains('uploadMedias', $disabled);
        $this->assertNotContains('exportSelection', $disabled);
    }

    // The galleries screen carries neither, so it always takes two deliberate steps to lose one - only the way into the trash, which is there whatever the screen
    public function testConfigureActionsAddsNoTrashActionsOnTheGalleriesView(): void
    {
        $actions = $this->createController()->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $index = $actions->getAsDto(Crud::PAGE_INDEX);

        $this->assertNull($index->getAction(Crud::PAGE_INDEX, 'restore'));
        $this->assertNull($index->getAction(Crud::PAGE_INDEX, 'deletePermanently'));
        $this->assertNotNull($index->getAction(Crud::PAGE_INDEX, 'trash'));
        $this->assertNotContains(Action::NEW, $actions->getAsDto(null)->getDisabledActions());
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

    // The public page of a category, one click from the row and from its own screen - the same action a Page carries in SiteBundle. Icon-only on the index, labelled on the edit screen, and opening in a new tab in both
    public function testConfigureActionsOpensTheCategoryOnTheSite(): void
    {
        $controller = $this->createController();

        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $indexAction = $actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'viewOnSite');
        $editAction = $actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, 'viewOnSite');

        $this->assertNotNull($indexAction);
        $this->assertFalse($indexAction->getLabel());
        // Icon-only, so the label it dropped comes back as the button's tooltip (see EasyAdminActionHelper::toIconOnly)
        $this->assertSame('_blank', $indexAction->getHtmlAttributes()['target']);
        $this->assertArrayHasKey('title', $indexAction->getHtmlAttributes());
        $this->assertNotNull($editAction);
        $this->assertSame('action.view_on_site', $editAction->getLabel()->getMessage());
        $this->assertSame('gallery', $editAction->getLabel()->getDomain());
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
    public function testConfigureFieldsEditsTheSummaryWithTheEcosystemRichTextEditor(): void
    {
        $summary = $this->findFieldDto($this->createController()->configureFields(Crud::PAGE_EDIT), 'summarySocialNetwork');

        $this->assertNotNull($summary);
        $this->assertSame(TrixEditorType::class, $summary->getFormType());
        $this->assertFalse($summary->isDisplayedOn(Crud::PAGE_INDEX));
    }

    // Labelled from ConfigBundle's own domain, the very key a Page's field carries: the same role deserves the same name across the back office
    public function testConfigureFieldsLabelsTheSummaryFromTheConfigDomain(): void
    {
        $summary = $this->findFieldDto($this->createController()->configureFields(Crud::PAGE_EDIT), 'summarySocialNetwork');

        $this->assertNotNull($summary);
        $this->assertSame('label.summary_social_network', $summary->getLabel()->getMessage());
        $this->assertSame('config', $summary->getLabel()->getDomain());
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
        $category = new GalleryCategory()->setSlug('voyages');
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
        $category = new GalleryCategory()->setSlug('voyages');
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
        $category = new GalleryCategory()->setSlug('voyages');

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

        new \ReflectionMethod(GalleryCategoryCrudController::class, 'addMediaBatch')->invoke($this->createController(), $builder);

        $this->assertIsCallable($listener);

        return $listener;
    }

    // --- the automatic gallery -----------------------------------------------------------------------------

    // Its screen lists the last additions of the whole gallery, cut into the days they arrived on, and offers no upload: its medias belong to the categories that actually received them
    public function testTheAutomaticGalleryListsTheLatestMediasGroupedByDay(): void
    {
        $category = new GalleryCategory()->setSlug('latest')->setAutomatic(true);
        new \ReflectionProperty(GalleryCategory::class, 'id')->setValue($category, 42);
        $media = new GalleryMedia();
        new \ReflectionProperty(GalleryMedia::class, 'id')->setValue($media, 7);

        $latestProvider = $this->createStub(GalleryLatestProvider::class);
        $latestProvider->method('getMedias')->willReturn([$media]);
        $latestProvider->method('getMediasByDay')->willReturn([['day' => new \DateTimeImmutable('2026-08-19'), 'medias' => [$media]]]);

        $entityDto = new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, $category);

        $parameters = $this->createControllerWithRouter(latestProvider: $latestProvider)->configureResponseParameters(KeyValueStore::new([
            'pageName' => Crud::PAGE_EDIT,
            'entity' => $entityDto,
        ]));

        $this->assertTrue($parameters->get('medias_automatic'));
        $this->assertSame([$media], $parameters->get('medias'));
        $this->assertCount(1, $parameters->get('medias_by_day'));
        $this->assertNull($parameters->get('media_upload_url'));
        $this->assertSame([7 => '/management/gallery-categories'], $parameters->get('media_edit_urls'));
    }

    // The selection acts on the very list the screen listed, which the category holds none of - a media that has since left the last days of additions is simply dropped
    public function testTheAutomaticGalleryAppliesCreditsToTheMediasItShows(): void
    {
        $category = new GalleryCategory()->setSlug('latest')->setAutomatic(true);
        $media = new GalleryMedia()->setTitle('Media 7');
        new \ReflectionProperty(GalleryMedia::class, 'id')->setValue($media, 7);

        $latestProvider = $this->createStub(GalleryLatestProvider::class);
        $latestProvider->method('getMedias')->willReturn([$media]);

        $controller = $this->createController(latestProvider: $latestProvider);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->editMedias(
            $this->createAdminContext($category),
            $this->createEditMediasRequest([7, 999], 'credits', ['credits' => '(c) 975L']),
            $this->createStub(EntityManagerInterface::class)
        );

        $this->assertSame('(c) 975L', $media->getCredits());
    }

    // A photo trashed from the automatic gallery leaves the gallery that actually holds it, cover included - the screen it was checked on holds nothing
    public function testTrashingFromTheAutomaticGalleryClearsTheCoverOfTheOwningCategory(): void
    {
        $owner = $this->createCategoryWithMedias(7);
        $media = $owner->getMedias()->first();
        $owner->setCoverMedia($media);

        $automatic = new GalleryCategory()->setSlug('latest')->setAutomatic(true);
        $latestProvider = $this->createStub(GalleryLatestProvider::class);
        $latestProvider->method('getMedias')->willReturn([$media]);

        $controller = $this->createController(latestProvider: $latestProvider);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->deleteMedias(
            $this->createAdminContext($automatic),
            $this->createDeleteMediasRequest([7]),
            $this->createStub(EntityManagerInterface::class)
        );

        $this->assertTrue($media->isDeleted());
        $this->assertNull($owner->getCoverMedia());
    }

    // --- configureResponseParameters -----------------------------------------------------------------------

    // The edit screen lists the category's medias, each thumbnail opening the media edit form - and carrying the category, which is what sends the admin back here once the media is saved or deleted
    public function testConfigureResponseParametersBuildsAnEditUrlPerMediaOfTheCategory(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');
        new \ReflectionProperty(GalleryCategory::class, 'id')->setValue($category, 42);
        $media = new GalleryMedia();
        new \ReflectionProperty(GalleryMedia::class, 'id')->setValue($media, 7);
        $category->addMedia($media);

        $entityDto = new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, $category);

        $parameters = $this->createControllerWithRouter(galleryMediaRepository: $this->createMediaRepositoryHolding([$media]))->configureResponseParameters(KeyValueStore::new([
            'pageName' => Crud::PAGE_EDIT,
            'entity' => $entityDto,
        ]));

        $this->assertSame([7 => '/management/gallery-categories'], $parameters->get('media_edit_urls'));
    }

    // The upload button is rendered with the medias rather than in the toolbar, where it sat above the blocks collection and its own "add" button
    public function testConfigureResponseParametersHandsTheUploadUrlToTheEditScreen(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');
        new \ReflectionProperty(GalleryCategory::class, 'id')->setValue($category, 42);

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

    // The grid is handed its list rather than reading category.medias, which holds the trashed ones too - and the count is what the heading's way into the trash carries
    public function testConfigureResponseParametersHandsTheEditScreenTheMediasThatAreShowing(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);
        $shown = $category->getMedias()->first();
        $category->getMedias()->last()->setIsDeleted(true);

        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findByCategory')->willReturn([$shown]);

        $parameters = $this->createEditScreenParameters($category, $mediaRepository);

        $this->assertSame([$shown], $parameters->get('medias'));
        $this->assertFalse($parameters->get('medias_trash'));
        $this->assertSame(1, $parameters->get('medias_trash_count'));
    }

    // The same screen under "mediasTrash" shows the trashed medias and nothing else - one screen serving both lists, as the index switches between the galleries and theirs
    public function testConfigureResponseParametersHandsTheTrashViewTheTrashedMediasOnly(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);
        $trashed = $category->getMedias()->last();
        $trashed->setIsDeleted(true);

        $parameters = $this->createEditScreenParameters($category, trash: true);

        $this->assertSame([$trashed], $parameters->get('medias'));
        $this->assertTrue($parameters->get('medias_trash'));
    }

    // The edit screen's own parameters, the medias' grid being handed its list from here
    private function createEditScreenParameters(GalleryCategory $category, ?GalleryMediaRepository $galleryMediaRepository = null, bool $trash = false): KeyValueStore
    {
        $controller = $this->createController(
            requestStack: new RequestStack([new Request(query: $trash ? ['mediasTrash' => 1] : [])]),
            galleryMediaRepository: $galleryMediaRepository,
        );
        $controller->setContainer($this->createContainer(['router' => $this->createRouter()]));

        return $controller->configureResponseParameters(KeyValueStore::new([
            'pageName' => Crud::PAGE_EDIT,
            'entity' => new EntityDto(GalleryCategory::class, new ClassMetadata(GalleryCategory::class), null, $category),
        ]));
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
        $event = new FormEvent($this->createFormHolding(new GalleryCategory()->setTitle('Voyages')), ['slug' => 'archives', 'title' => 'Voyages d\'été']);
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

        new \ReflectionMethod(GalleryCategoryCrudController::class, 'addSlugNormalizer')->invoke($this->createController(), $builder);

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

    // The ordinary case: a site declaring no fields of its own gets no field at all on the screen, which is what makes this cost such an app nothing
    public function testConfigureFieldsAddsNoDataFieldWhenTheSiteDeclaresNone(): void
    {
        $this->assertNull($this->findFieldDto($this->createController()->configureFields(Crud::PAGE_EDIT), 'data'));
    }

    // And a site declaring one gets it rendered from the very form type it returned, kept out of the grid like the rest of the payload
    public function testConfigureFieldsRendersTheFormTheSiteDeclaresForACategory(): void
    {
        $registry = new GalleryCustomizationRegistry([$this->createCustomizationProvider(TextType::class)]);

        $data = $this->findFieldDto($this->createController(customizationRegistry: $registry)->configureFields(Crud::PAGE_EDIT), 'data');

        $this->assertNotNull($data);
        $this->assertSame(TextType::class, $data->getFormType());
        $this->assertSame('@c975LGallery/management/field_data.html.twig', $data->getTemplatePath());
        $this->assertFalse($data->isDisplayedOn(Crud::PAGE_INDEX));
    }

    // Only the category side is read here, a media's own form being the other controller's business
    private function createCustomizationProvider(?string $categoryFormType): GalleryCustomizationProviderInterface
    {
        $provider = $this->createStub(GalleryCustomizationProviderInterface::class);
        $provider->method('getCategoryDataFormType')->willReturn($categoryFormType);
        $provider->method('getMediaDataFormType')->willReturn(null);

        return $provider;
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
        $category = new GalleryCategory()->setTitle('Voyages d\'été')->setSlug('voyages-d-ete');
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
        $category = new GalleryCategory()->setTitle('Voyages d\'été')->setSlug('voyages-d-ete');
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
        $category = new GalleryCategory()->setTitle('Voyages')->setSlug('voyages');

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
        $category = new GalleryCategory()->setTitle('Voyages')->setSlug('voyages');
        $reverse = new Redirect()->setFromPath('/gallery/voyages')->setToUrl('/gallery/archives');
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
        $category = new GalleryCategory()->setTitle('Voyages d\'été')->setSlug('voyages-d-ete');
        $existing = new Redirect()->setFromPath('/gallery/voyages')->setToUrl('/gallery/somewhere-else');
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
        $category = new GalleryCategory()->setTitle('Voyages')->setSlug('voyages');
        $category->addMedia(new GalleryMedia()->setSlug('mont-blanc'));

        $gone = [
            '/gallery/voyages' => new Redirect()->setFromPath('/gallery/voyages')->setGone(true),
            '/gallery/voyages/*' => new Redirect()->setFromPath('/gallery/voyages/*')->setGone(true),
            '/gallery/voyages/mont-blanc' => new Redirect()->setFromPath('/gallery/voyages/mont-blanc')->setGone(true),
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
        $category = new GalleryCategory()->setTitle('Voyages')->setSlug('voyages');
        $redirect = new Redirect()->setFromPath('/gallery/voyages')->setToUrl('/gallery/vacances');
        $removed = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $this->createControllerWithRouter($this->createRedirectRepository(['/gallery/voyages' => $redirect]))->persistEntity($entityManager, $category);

        $this->assertSame([], $removed);
    }

    // --- deleteEntity ------------------------------------------------------------------------------------

    // The category is only flagged: no removal, so neither the cascade on its medias nor GalleryMediaDerivativeCleanupListener ever runs, and not one file leaves the disk
    public function testDeleteEntityOnlyMovesTheCategoryToTheTrash(): void
    {
        $category = new GalleryCategory()->setTitle('Voyages')->setSlug('voyages');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->once())->method('flush');

        $this->createControllerWithRouter($this->createRedirectRepository())->deleteEntity($entityManager, $category);

        $this->assertTrue($category->isDeleted());
    }

    // A category that can still be restored has no url to declare gone - the 410 comes from the row itself while it sits in the trash (see GalleryController::resolveCategory)
    public function testDeleteEntityRecordsNoGoneRedirect(): void
    {
        $category = new GalleryCategory()->setTitle('Voyages')->setSlug('voyages');
        $persisted = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createControllerWithRouter($this->createRedirectRepository())->deleteEntity($entityManager, $category);

        $this->assertSame([], array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect)));
    }

    // --- deletePermanently -------------------------------------------------------------------------------

    // The category page and every media page under it are declared in the sitemap (see GallerySitemapProvider), so the urls are left answering 410 - the medias through a single wildcard row rather than one row each
    public function testDeletePermanentlyLeavesTheCategoryAndEverythingUnderItAnsweringGone(): void
    {
        $category = new GalleryCategory()->setTitle('Voyages')->setSlug('voyages');
        $persisted = [];
        $removed = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $controller = $this->createController(redirectRepository: $this->createRedirectRepository());
        $controller->setContainer($this->createContainer([
            'router' => $this->createRouter(),
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->deletePermanently($this->createAdminContext($category), new Request(['token' => 'token']), $entityManager, $this->createRatingRepository());

        $redirects = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect));
        $this->assertSame(['/gallery/voyages', '/gallery/voyages/*'], array_map(static fn (Redirect $redirect): ?string => $redirect->getFromPath(), $redirects));
        $this->assertNull($redirects[0]->getToUrl());
        $this->assertTrue($redirects[0]->isGone());
        $this->assertTrue($redirects[1]->isGone());
        $this->assertSame([$category], $removed);
    }

    // The medias go with the category's own cascade, and their likes hang off nothing that cascades: they are dropped here or they are left behind for good
    public function testDeletePermanentlyDropsTheLikesOfEveryMediaItTakesWithIt(): void
    {
        $category = $this->createCategoryWithMedias(7, 8);

        $controller = $this->createController(redirectRepository: $this->createRedirectRepository());
        $controller->setContainer($this->createContainer([
            'router' => $this->createRouter(),
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->deletePermanently($this->createAdminContext($category), new Request(['token' => 'token']), $this->createStub(EntityManagerInterface::class), $this->createRatingRepository());

        $this->assertSame(['gallery_media#7', 'gallery_media#8'], $this->ratingsDeletedFor);
    }

    // Only the admin role drops a gallery for good, the rest of the screens sitting at the editor's
    public function testDeletePermanentlyDeniesAccessBelowTheAdminRole(): void
    {
        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $this->expectException(AccessDeniedException::class);

        $controller->deletePermanently(
            $this->createAdminContext(new GalleryCategory()->setTitle('Voyages')->setSlug('voyages')),
            new Request(['token' => 'token']),
            $this->createStub(EntityManagerInterface::class),
            $this->createRatingRepository(),
        );
    }

    // The action is reached by a GET, so nothing but the token tells a click on the trash screen apart from a request an <img> fired on a logged-in admin
    public function testDeletePermanentlyRemovesNothingWhenCsrfTokenIsInvalid(): void
    {
        $category = new GalleryCategory()->setTitle('Voyages')->setSlug('voyages');
        $category->setIsDeleted(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'router' => $this->createRouter(),
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
        ]));

        $controller->deletePermanently($this->createAdminContext($category), new Request(), $entityManager, $this->createRatingRepository());
    }

    // --- restore -----------------------------------------------------------------------------------------

    // Putting a category back lifts the flag and frees the url, a "gone" row an earlier permanent deletion left there shadowing it for good otherwise
    public function testRestoreLiftsTheFlagAndReleasesTheGoneRows(): void
    {
        $category = new GalleryCategory()->setTitle('Voyages')->setSlug('voyages');
        $category->setIsDeleted(true);

        $gone = [
            '/gallery/voyages' => new Redirect()->setFromPath('/gallery/voyages')->setGone(true),
            '/gallery/voyages/*' => new Redirect()->setFromPath('/gallery/voyages/*')->setGone(true),
        ];
        $removed = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $controller = $this->createController(redirectRepository: $this->createRedirectRepository($gone));
        $controller->setContainer($this->createContainer([
            'router' => $this->createRouter(),
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'request_stack' => $this->createRequestStackWithSession(),
        ]));

        $controller->restore($this->createAdminContext($category), new Request(['token' => 'token']), $entityManager);

        $this->assertFalse($category->isDeleted());
        $this->assertSame(array_values($gone), $removed);
    }

    // Same GET as deletePermanently(), and the same token standing between a click and a forged request
    public function testRestoreLiftsNothingWhenCsrfTokenIsInvalid(): void
    {
        $category = new GalleryCategory()->setTitle('Voyages')->setSlug('voyages');
        $category->setIsDeleted(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'router' => $this->createRouter(),
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
        ]));

        $controller->restore($this->createAdminContext($category), new Request(), $entityManager);

        $this->assertTrue($category->isDeleted());
    }

    // The public urls the redirect is built from are generated, the first segment being the configured route prefix (see GalleryRoutePrefix) - here the default one
    // A media route carries a slug below the category, so the two are told apart by the parameters they are given rather than by the route name a stub does not resolve
    private function createRouter(): RouterInterface
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters = []): string => '/gallery/' . ($parameters['category'] ?? '') . (isset($parameters['slug']) ? '/' . $parameters['slug'] : ''));

        return $router;
    }

    private function createControllerWithRouter(?RedirectRepository $redirectRepository = null, ?GalleryMediaRepository $galleryMediaRepository = null, ?GalleryLatestProvider $latestProvider = null): GalleryCategoryCrudController
    {
        $controller = $this->createController(redirectRepository: $redirectRepository, galleryMediaRepository: $galleryMediaRepository, latestProvider: $latestProvider);
        $controller->setContainer($this->createContainer(['router' => $this->createRouter()]));

        return $controller;
    }

    // The list a category's edit screen is handed for its grid, and the very one the edit urls are built off (see GalleryCategoryCrudController::mediasShown)
    /** @param list<GalleryMedia> $medias */
    private function createMediaRepositoryHolding(array $medias): GalleryMediaRepository
    {
        $repository = $this->createStub(GalleryMediaRepository::class);
        $repository->method('findByCategory')->willReturn($medias);

        return $repository;
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

    // The likes of the medias whose ratings were dropped, as "type#id" - filled by createRatingRepository()
    /** @var list<string> */
    private array $ratingsDeletedFor = [];

    // How many queries dropped them: a gallery of two thousand photos is one, not two thousand
    private int $ratingsDeleteCalls = 0;

    private function createRatingRepository(): RatingRepository
    {
        $repository = $this->createStub(RatingRepository::class);
        $repository
            ->method('deleteForOwners')
            ->willReturnCallback(function (string $ownerType, array $ownerIds): int {
                ++$this->ratingsDeleteCalls;
                foreach ($ownerIds as $ownerId) {
                    $this->ratingsDeletedFor[] = $ownerType . '#' . $ownerId;
                }

                return 0;
            })
        ;

        return $repository;
    }
}
