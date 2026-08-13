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
use c975L\GalleryBundle\Controller\Management\GalleryCategoryCrudController;
use c975L\GalleryBundle\Controller\Management\GalleryMediaUploadController;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Service\GalleryMediaFactory;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use c975L\GalleryBundle\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

// AbstractController only ever calls $this->container->has()/get() with plain service ids, so a bare Symfony\Component\DependencyInjection\Container (implements Psr\Container\ContainerInterface) populated via set() is enough to unit-test createForm()/addFlash()/render() without booting a kernel - same technique as GalleryCategoryCrudControllerTest
class GalleryMediaUploadControllerTest extends TestCase
{
    // The router comes as standard, the public url of each uploaded media being what its 410 is released on (see GalleryUrlRedirector::release)
    private function createContainer(array $services): Container
    {
        $container = new Container();
        $container->set('router', $this->createRouter());

        foreach ($services as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }

    private function createRouter(): RouterInterface
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => '/gallery/' . ($parameters['category'] ?? '') . '/' . ($parameters['slug'] ?? '')
        );

        return $router;
    }

    private function createAuthorizationChecker(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return $checker;
    }

    private function createFormFactory(FormInterface $form, array &$capturedOptions): FormFactoryInterface
    {
        $factory = $this->createStub(FormFactoryInterface::class);
        $factory->method('create')->willReturnCallback(
            function (string $type, mixed $data = null, array $options = []) use ($form, &$capturedOptions): FormInterface {
                $capturedOptions = ['data' => $data, 'options' => $options];

                return $form;
            }
        );

        return $factory;
    }

    private function createSubmittedForm(bool $submitted, bool $valid, mixed $data = null): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn($submitted);
        $form->method('isValid')->willReturn($valid);
        $form->method('getData')->willReturn($data);
        $form->method('createView')->willReturn(new \Symfony\Component\Form\FormView());

        return $form;
    }

    private function createSessionRequestStack(): array
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $requestStack = new RequestStack([$request]);

        return [$requestStack, $session];
    }

    private function createAdminUrlGenerator(string $generatedUrl = '/management/gallery/42/edit'): AdminUrlGeneratorInterface
    {
        $generator = $this->createStub(AdminUrlGeneratorInterface::class);
        $generator->method('setController')->willReturnSelf();
        $generator->method('setAction')->willReturnSelf();
        $generator->method('setEntityId')->willReturnSelf();
        $generator->method('set')->willReturnSelf();
        $generator->method('generateUrl')->willReturn($generatedUrl);

        return $generator;
    }

    private function createCategoryRepository(?GalleryCategory $category): GalleryCategoryRepository
    {
        $repository = $this->createStub(GalleryCategoryRepository::class);
        $repository->method('find')->willReturn($category);

        return $repository;
    }

    private function createUploadedFile(string $clientName = 'media.webp'): UploadedFile
    {
        return new UploadedFile(__FILE__, $clientName, test: true);
    }

    private function createController(
        ?GalleryCategoryRepository $categoryRepository = null,
        ?EntityManagerInterface $entityManager = null,
        ?AdminUrlGeneratorInterface $adminUrlGenerator = null,
        ?RedirectRepository $redirectRepository = null,
    ): GalleryMediaUploadController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new GalleryMediaUploadController(
            $categoryRepository ?? $this->createStub(GalleryCategoryRepository::class),
            new GalleryMediaFactory(new GalleryMediaSlugger(new AsciiSlugger())),
            new UploadLimits(),
            $translator,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $adminUrlGenerator ?? $this->createAdminUrlGenerator(),
            $this->createConfigService(),
            new GalleryUrlRedirector($redirectRepository ?? $this->createStub(RedirectRepository::class)),
        );
    }

    private function createRedirectRepository(array $byFromPath): RedirectRepository
    {
        $redirectRepository = $this->createStub(RedirectRepository::class);
        $redirectRepository->method('findOneByFromPath')->willReturnCallback(
            static fn (string $fromPath): ?Redirect => $byFromPath[$fromPath] ?? null
        );

        return $redirectRepository;
    }

    // The upload screen sits behind the same ConfigBundle "site-role-editor" entry as the CRUDs reaching it
    private function createConfigService(): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ROLE_EDITOR');

        return $configService;
    }

    public function testUploadDeniesAccessBelowTheEditorRole(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->upload(Request::create('/gallery-upload'));
    }

    // The screen has no category picker of its own: reached without one, it has nothing to attach the medias to
    public function testUploadThrowsNotFoundWithoutACategory(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $controller = $this->createController($this->createCategoryRepository(null));
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $controller->upload(Request::create('/gallery-upload'));
    }

    // Past post_max_size php drops $_POST and $_FILES together, csrf token included: the form is never "submitted", so without this the screen would silently redisplay itself and the batch would look like it was never sent
    public function testUploadReportsABatchPhpEmptiedInsteadOfRedisplayingSilently(): void
    {
        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        [$requestStack, $session] = $this->createSessionRequestStack();

        $controller = $this->createController($this->createCategoryRepository($category));
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'request_stack' => $requestStack,
        ]));

        // What php hands over: a POST carrying nothing at all, the browser having sent 500 MB
        $request = Request::create('/gallery-upload?category=5', 'POST', [], [], [], ['CONTENT_LENGTH' => 500_000_000]);

        $response = $controller->upload($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($session->getFlashBag()->has('danger'));
    }

    // A GET on the same screen carries no content, and must not be read as a batch php threw away
    public function testUploadRendersNormallyWhenNothingWasPosted(): void
    {
        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');

        $captured = [];
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<form></form>');

        $controller = $this->createController($this->createCategoryRepository($category));
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'form.factory' => $this->createFormFactory($this->createSubmittedForm(false, false), $captured),
            'twig' => $twig,
        ]));

        $response = $controller->upload(Request::create('/gallery-upload?category=5'));

        $this->assertSame(200, $response->getStatusCode());
    }

    // The category comes from the url, and only its title reaches the form - the field is display-only (see GalleryMediaBatchUploadType)
    public function testUploadPassesTheCategoryTitleToTheForm(): void
    {
        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');

        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('find')->with(5)->willReturn($category);

        $captured = [];
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<form></form>');

        $controller = $this->createController($categoryRepository);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'form.factory' => $this->createFormFactory($this->createSubmittedForm(false, false), $captured),
            'twig' => $twig,
        ]));

        $controller->upload(Request::create('/gallery-upload?category=5'));

        $this->assertSame('Voyages', $captured['options']['category_title']);
    }

    // One media per uploaded file, all sharing the batch's credits and rights, appended after the medias the category already holds
    public function testUploadCreatesOneMediaPerFileAppendedAfterExistingPositions(): void
    {
        $category = new GalleryCategory()->setSlug('voyages')->setTitle('Voyages');
        $category->addMedia(new GalleryMedia()->setPosition(0));
        $category->addMedia(new GalleryMedia()->setPosition(1));

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $entityManager->expects($this->once())->method('flush');

        $data = [
            'files' => [$this->createUploadedFile('a.webp'), $this->createUploadedFile('b.webp')],
            'credits' => 'Studio 975L',
            'rightsReserved' => true,
        ];
        $captured = [];
        [$requestStack] = $this->createSessionRequestStack();

        $controller = $this->createController(
            $this->createCategoryRepository($category),
            $entityManager,
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'form.factory' => $this->createFormFactory($this->createSubmittedForm(true, true, $data), $captured),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->upload(Request::create('/gallery-upload?category=5', 'POST'));

        $this->assertCount(2, $persisted);
        $this->assertSame([2, 3], array_map(static fn (GalleryMedia $media): int => $media->getPosition(), $persisted));
        $this->assertSame($category, $persisted[0]->getCategory());
        $this->assertSame('Studio 975L', $persisted[0]->getCredits());
        $this->assertSame('Studio 975L', $persisted[1]->getCredits());
        $this->assertTrue($persisted[0]->isRightsReserved());
        $this->assertSame('/management/gallery/42/edit', $response->getTargetUrl());
    }

    // Nothing else tells one media of a batch from another, so the original filename seeds the alt rather than leaving it empty
    public function testUploadSeedsTheTitleFromTheOriginalFilename(): void
    {
        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $data = ['files' => [$this->createUploadedFile('col_du-galibier.webp')], 'credits' => null, 'rightsReserved' => false];
        $captured = [];
        [$requestStack] = $this->createSessionRequestStack();

        $controller = $this->createController(
            $this->createCategoryRepository(new GalleryCategory()->setSlug('voyages')),
            entityManager: $entityManager,
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'form.factory' => $this->createFormFactory($this->createSubmittedForm(true, true, $data), $captured),
            'request_stack' => $requestStack,
        ]));

        $controller->upload(Request::create('/gallery-upload?category=5', 'POST'));

        $this->assertSame('Col Du Galibier', $persisted[0]->getTitle());
    }

    // A slug freed by an earlier deletion is still answering 410 (see GalleryMediaCrudController::deleteEntity), and RedirectSubscriber runs before the router: the page would exist while its url kept saying it doesn't
    public function testUploadLiftsTheGoneRowOfASlugUsedAgain(): void
    {
        $gone = new Redirect()->setFromPath('/gallery/voyages/mont-blanc')->setGone(true);
        $removed = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $data = ['files' => [$this->createUploadedFile('mont-blanc.webp')], 'credits' => null, 'rightsReserved' => false];
        $captured = [];
        [$requestStack] = $this->createSessionRequestStack();

        $controller = $this->createController(
            $this->createCategoryRepository(new GalleryCategory()->setSlug('voyages')),
            entityManager: $entityManager,
            redirectRepository: $this->createRedirectRepository(['/gallery/voyages/mont-blanc' => $gone]),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'form.factory' => $this->createFormFactory($this->createSubmittedForm(true, true, $data), $captured),
            'request_stack' => $requestStack,
        ]));

        $controller->upload(Request::create('/gallery-upload?category=5', 'POST'));

        $this->assertSame([$gone], $removed);
    }

    // A row redirecting somewhere is deliberate: uploading a media under its old url must not drop the redirect its visitors follow
    public function testUploadKeepsARowThatStillRedirects(): void
    {
        $redirect = new Redirect()->setFromPath('/gallery/voyages/mont-blanc')->setToUrl('/gallery/voyages/col-du-galibier');
        $removed = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $data = ['files' => [$this->createUploadedFile('mont-blanc.webp')], 'credits' => null, 'rightsReserved' => false];
        $captured = [];
        [$requestStack] = $this->createSessionRequestStack();

        $controller = $this->createController(
            $this->createCategoryRepository(new GalleryCategory()->setSlug('voyages')),
            entityManager: $entityManager,
            redirectRepository: $this->createRedirectRepository(['/gallery/voyages/mont-blanc' => $redirect]),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'form.factory' => $this->createFormFactory($this->createSubmittedForm(true, true, $data), $captured),
            'request_stack' => $requestStack,
        ]));

        $controller->upload(Request::create('/gallery-upload?category=5', 'POST'));

        $this->assertSame([], $removed);
    }

    // Back to the category just filled, whose edit screen is where its medias are listed
    public function testUploadFlashesSuccessAndRedirectsToTheCategoryEditScreen(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');
        new \ReflectionProperty(GalleryCategory::class, 'id')->setValue($category, 42);

        $adminUrlGenerator = $this->createMock(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->expects($this->once())->method('setController')->with(GalleryCategoryCrudController::class)->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setAction')->with(Action::EDIT)->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setEntityId')->with(42)->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/gallery/42/edit');

        $data = ['files' => [$this->createUploadedFile()], 'credits' => null, 'rightsReserved' => false];
        $captured = [];
        [$requestStack, $session] = $this->createSessionRequestStack();

        $controller = $this->createController(
            $this->createCategoryRepository($category),
            adminUrlGenerator: $adminUrlGenerator,
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'form.factory' => $this->createFormFactory($this->createSubmittedForm(true, true, $data), $captured),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->upload(Request::create('/gallery-upload?category=42', 'POST'));

        $this->assertTrue($session->getFlashBag()->has('success'));
        $this->assertSame('/management/gallery/42/edit', $response->getTargetUrl());
    }
}
