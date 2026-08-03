<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Controller\Management;

use c975L\GalleryBundle\Controller\Management\GalleryPhotoCrudController;
use c975L\GalleryBundle\Controller\Management\GalleryPhotoUploadController;
use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryPhotoRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

// AbstractController only ever calls $this->container->has()/get() with plain service ids, so a bare Symfony\Component\DependencyInjection\Container (implements Psr\Container\ContainerInterface) populated via set() is enough to unit-test createForm()/addFlash()/render() without booting a kernel - same technique as GalleryCategoryCrudControllerTest
class GalleryPhotoUploadControllerTest extends TestCase
{
    private function createContainer(array $services): Container
    {
        $container = new Container();
        foreach ($services as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
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

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return [$requestStack, $session];
    }

    private function createAdminUrlGenerator(string $generatedUrl = '/management/gallery-photos'): AdminUrlGeneratorInterface
    {
        $generator = $this->createStub(AdminUrlGeneratorInterface::class);
        $generator->method('setController')->willReturnSelf();
        $generator->method('setAction')->willReturnSelf();
        $generator->method('set')->willReturnSelf();
        $generator->method('generateUrl')->willReturn($generatedUrl);

        return $generator;
    }

    private function createController(
        ?GalleryRepository $galleryRepository = null,
        ?GalleryCategoryRepository $categoryRepository = null,
        ?GalleryPhotoRepository $photoRepository = null,
        ?EntityManagerInterface $entityManager = null,
        ?AdminUrlGeneratorInterface $adminUrlGenerator = null,
    ): GalleryPhotoUploadController {
        return new GalleryPhotoUploadController(
            $galleryRepository ?? $this->createStub(GalleryRepository::class),
            $categoryRepository ?? $this->createStub(GalleryCategoryRepository::class),
            $photoRepository ?? $this->createStub(GalleryPhotoRepository::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $adminUrlGenerator ?? $this->createAdminUrlGenerator(),
        );
    }

    public function testUploadDeniesAccessBelowRoleAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->upload(Request::create('/gallery/upload'));
    }

    // Lets "Ajouter des photos" from an already-filtered index preselect the same category on the upload form
    public function testUploadPreselectsCategoryFromTheQueryParameter(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $category = (new GalleryCategory())->setSlug('voyages');

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOrCreateDefault')->willReturn($gallery);

        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('find')->with('5')->willReturn($category);

        $captured = [];
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<form></form>');

        $controller = $this->createController(galleryRepository: $galleryRepository, categoryRepository: $categoryRepository);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'form.factory' => $this->createFormFactory($this->createSubmittedForm(false, false), $captured),
            'twig' => $twig,
        ]));

        $controller->upload(Request::create('/gallery/upload?category=5'));

        $this->assertSame($category, $captured['data']['category']);
        $this->assertSame($gallery, $captured['options']['gallery']);
    }

    // A row added then left untouched (no file) must not block persisting the others
    public function testUploadSkipsPhotoRowsWithNoFileAndAppendsAfterExistingPositions(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $category = (new GalleryCategory())->setSlug('voyages');
        $withFile = new GalleryPhoto();
        $withFile->setFile(new \Symfony\Component\HttpFoundation\File\UploadedFile(__FILE__, 'photo.webp', test: true));
        $withoutFile = new GalleryPhoto();

        $photoRepository = $this->createStub(GalleryPhotoRepository::class);
        $photoRepository->method('findByCategory')->willReturn([(new GalleryPhoto())->setPosition(0), (new GalleryPhoto())->setPosition(1)]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($withFile);
        $entityManager->expects($this->once())->method('flush');

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOrCreateDefault')->willReturn($gallery);

        $data = ['category' => $category, 'credits' => 'Studio 975L', 'rightsReserved' => true, 'photos' => [$withFile, $withoutFile]];
        $captured = [];
        [$requestStack] = $this->createSessionRequestStack();

        $controller = $this->createController(
            galleryRepository: $galleryRepository,
            photoRepository: $photoRepository,
            entityManager: $entityManager,
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'form.factory' => $this->createFormFactory($this->createSubmittedForm(true, true, $data), $captured),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->upload(Request::create('/gallery/upload', 'POST'));

        $this->assertSame(2, $withFile->getPosition());
        $this->assertSame('Studio 975L', $withFile->getCredits());
        $this->assertTrue($withFile->isRightsReserved());
        $this->assertSame($category, $withFile->getCategory());
        $this->assertSame('/management/gallery-photos', $response->getTargetUrl());
    }

    // No category picked at upload time - falls back to the gallery's catch-all "Non classé" category (see GalleryCategoryRepository::findOrCreateUncategorized)
    public function testUploadFallsBackToUncategorizedCategoryWhenNoneSelected(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $uncategorized = (new GalleryCategory())->setSlug('non-classe')->setUncategorized(true);
        $photo = new GalleryPhoto();
        $photo->setFile(new \Symfony\Component\HttpFoundation\File\UploadedFile(__FILE__, 'photo.webp', test: true));

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOrCreateDefault')->willReturn($gallery);

        $categoryRepository = $this->createMock(GalleryCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('findOrCreateUncategorized')->with($gallery)->willReturn($uncategorized);

        $photoRepository = $this->createStub(GalleryPhotoRepository::class);
        $photoRepository->method('findByCategory')->willReturn([]);

        $data = ['category' => null, 'credits' => null, 'rightsReserved' => false, 'photos' => [$photo]];
        $captured = [];
        [$requestStack] = $this->createSessionRequestStack();

        $controller = $this->createController(
            galleryRepository: $galleryRepository,
            categoryRepository: $categoryRepository,
            photoRepository: $photoRepository,
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'form.factory' => $this->createFormFactory($this->createSubmittedForm(true, true, $data), $captured),
            'request_stack' => $requestStack,
        ]));

        $controller->upload(Request::create('/gallery/upload', 'POST'));

        $this->assertSame($uncategorized, $photo->getCategory());
    }

    public function testUploadFlashesSuccessAndRedirectsToThePhotoIndexFilteredOnTheCategory(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $category = (new GalleryCategory())->setSlug('voyages');
        $idProperty = new \ReflectionProperty(GalleryCategory::class, 'id');
        $idProperty->setValue($category, 42);
        $photo = new GalleryPhoto();
        $photo->setFile(new \Symfony\Component\HttpFoundation\File\UploadedFile(__FILE__, 'photo.webp', test: true));

        $galleryRepository = $this->createStub(GalleryRepository::class);
        $galleryRepository->method('findOrCreateDefault')->willReturn($gallery);
        $photoRepository = $this->createStub(GalleryPhotoRepository::class);
        $photoRepository->method('findByCategory')->willReturn([]);

        $adminUrlGenerator = $this->createMock(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->expects($this->once())->method('setController')->with(GalleryPhotoCrudController::class)->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('set')->with('filters[category][value]', 42)->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/gallery-photos?filters%5Bcategory%5D%5Bvalue%5D=42');

        $data = ['category' => $category, 'credits' => null, 'rightsReserved' => false, 'photos' => [$photo]];
        $captured = [];
        [$requestStack, $session] = $this->createSessionRequestStack();

        $controller = $this->createController(
            galleryRepository: $galleryRepository,
            photoRepository: $photoRepository,
            adminUrlGenerator: $adminUrlGenerator,
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'form.factory' => $this->createFormFactory($this->createSubmittedForm(true, true, $data), $captured),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->upload(Request::create('/gallery/upload', 'POST'));

        $this->assertTrue($session->getFlashBag()->has('success'));
        $this->assertSame('/management/gallery-photos?filters%5Bcategory%5D%5Bvalue%5D=42', $response->getTargetUrl());
    }
}
