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
use c975L\GalleryBundle\Controller\Management\GalleryMediaCrudController;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use c975L\GalleryBundle\Service\UploadLimits;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\RequestContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\SlugType;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Form\Type\VichFileType;
use Vich\UploaderBundle\Form\Type\VichImageType;

class GalleryMediaCrudControllerTest extends TestCase
{
    private function createController(
        ?AdminUrlGeneratorInterface $adminUrlGenerator = null,
        ?RedirectRepository $redirectRepository = null,
    ): GalleryMediaCrudController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new GalleryMediaCrudController(
            $adminUrlGenerator ?? $this->createAdminUrlGenerator(),
            $translator,
            new GalleryMediaSlugger(new AsciiSlugger()),
            new GalleryUrlRedirector($redirectRepository ?? $this->createRedirectRepository()),
            $this->createConfigService(),
            // Fixed ceilings rather than the machine's own php.ini, so the video field's limit is the same on every runner
            new UploadLimits('20', '64M', '128M'),
        );
    }

    // The media edit screen sits behind ConfigBundle's "site-role-editor" entry
    private function createConfigService(): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ROLE_EDITOR');

        return $configService;
    }

    private function createRedirectRepository(array $byFromPath = []): RedirectRepository
    {
        $redirectRepository = $this->createStub(RedirectRepository::class);
        $redirectRepository->method('findOneByFromPath')->willReturnCallback(
            static fn (string $fromPath): ?Redirect => $byFromPath[$fromPath] ?? null
        );

        return $redirectRepository;
    }

    // The public urls the redirect is built from are generated, the first segment being the configured route prefix (see GalleryRoutePrefix) - here the default one
    private function createControllerWithRouter(?RedirectRepository $redirectRepository = null): GalleryMediaCrudController
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => '/gallery/' . ($parameters['category'] ?? '') . '/' . ($parameters['slug'] ?? '')
        );

        $container = new Container();
        $container->set('router', $router);

        $controller = $this->createController(redirectRepository: $redirectRepository);
        $controller->setContainer($container);

        return $controller;
    }

    private function createUnitOfWorkHolding(array $originalData): UnitOfWork
    {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturn($originalData);

        return $unitOfWork;
    }

    private function createEntityManager(array $originalData, array &$persisted): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($this->createUnitOfWorkHolding($originalData));
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        return $entityManager;
    }

    private function createAdminUrlGenerator(string $generatedUrl = '/management/gallery'): AdminUrlGeneratorInterface
    {
        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('setEntityId')->willReturnSelf();
        $adminUrlGenerator->method('unset')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn($generatedUrl);

        return $adminUrlGenerator;
    }

    // --- index -------------------------------------------------------------------------------------------

    // There is no all-medias listing: the redirects EasyAdmin sends here after a save or a delete land on the category the media was reached from
    public function testIndexRedirectsToTheEditScreenOfTheCategoryCarriedInTheQuery(): void
    {
        $adminUrlGenerator = $this->createMock(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->expects($this->once())->method('setController')->with(GalleryCategoryCrudController::class)->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('unset')->with('category')->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setAction')->with(Action::EDIT)->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setEntityId')->with(4)->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/gallery/4/edit');

        $context = AdminContext::forTesting(requestContext: RequestContext::forTesting(Request::create('/management/gallery-media?category=4')));

        $response = $this->createController($adminUrlGenerator)->index($context);

        $this->assertSame('/management/gallery/4/edit', $response->getTargetUrl());
    }

    // Reached by hand, without a category to go back to, the whole gallery is the next best landing
    public function testIndexRedirectsToTheCategoryListingWithoutACategory(): void
    {
        $adminUrlGenerator = $this->createMock(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('unset')->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setAction')->with(Action::INDEX)->willReturnSelf();
        $adminUrlGenerator->expects($this->never())->method('setEntityId');
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/gallery');

        $context = AdminContext::forTesting(requestContext: RequestContext::forTesting(Request::create('/management/gallery-media')));

        $response = $this->createController($adminUrlGenerator)->index($context);

        $this->assertSame('/management/gallery', $response->getTargetUrl());
    }

    // --- configureActions --------------------------------------------------------------------------------

    // Medias are only ever created in bulk, from a category's own "add medias" action (see GalleryCategoryCrudController) - never one at a time, and never from here, where no category is picked
    public function testConfigureActionsDisablesNewAndDetailAndGivesTheEditScreenItsOwnDeleteAndCancel(): void
    {
        $controller = $this->createController();

        // A real EasyAdmin runtime pre-populates default actions (EDIT, DELETE...) before calling configureActions()
        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $disabled = $actions->getAsDto(null)->getDisabledActions();
        $this->assertContains(Action::NEW, $disabled);
        $this->assertContains(Action::DETAIL, $disabled);

        $editActions = $actions->getAsDto(Crud::PAGE_EDIT);
        $this->assertNotNull($editActions->getAction(Crud::PAGE_EDIT, 'cancel'));
        // The edit form is the only screen a media has, so it carries its own delete button
        $this->assertNotNull($editActions->getAction(Crud::PAGE_EDIT, Action::DELETE));
    }

    // --- configureFields -----------------------------------------------------------------------------------

    public function testConfigureFieldsUsesVichImageTypeForTheFileFieldWithA10mSizeLimit(): void
    {
        $controller = $this->createController();

        $fields = iterator_to_array($controller->configureFields(Crud::PAGE_EDIT));
        $fileField = null;
        foreach ($fields as $field) {
            if ('file' === $field->getAsDto()->getProperty()) {
                $fileField = $field->getAsDto();
            }
        }

        $this->assertNotNull($fileField);
        $this->assertSame(VichImageType::class, $fileField->getFormType());
        $this->assertFalse($fileField->getFormTypeOptions()['allow_delete']);
    }

    // A video is not a photograph: this bundle's own 20 MiB ceiling exists to keep a batch of stills from taking a shared host down, and would refuse any video worth uploading - what caps this field is php's own limit alone
    public function testConfigureFieldsCapsTheVideoFieldAtPhpOwnLimitRatherThanTheBundleOne(): void
    {
        $videoField = $this->findField($this->createController()->configureFields(Crud::PAGE_EDIT), 'videoFile');

        $this->assertNotNull($videoField);
        $this->assertSame(VichFileType::class, $videoField->getFormType());

        $constraints = $videoField->getFormTypeOptions()['constraints'];
        $this->assertCount(1, $constraints);
        // 64M from the fixture above, well over MAX_FILE_SIZE's 20 MiB
        $this->assertSame(64 * 1024 ** 2, $constraints[0]->maxSize);
        $this->assertSame(GalleryMedia::VIDEO_MIME_TYPES, $constraints[0]->mimeTypes);
    }

    // The url is handed to an iframe's src on the front end, so the form refuses anything but http(s) rather than letting the entity drop it silently
    public function testConfigureFieldsAcceptsHttpUrlsOnly(): void
    {
        $urlField = $this->findField($this->createController()->configureFields(Crud::PAGE_EDIT), 'externalUrl');

        $this->assertNotNull($urlField);
        $this->assertSame(['http', 'https'], $urlField->getCustomOption(UrlField::OPTION_ALLOWED_PROTOCOLS));
    }

    // An admin who pasted an url no longer picks a type next to it: what the media turned out to be is shown on the very screen the url is pasted on, and shown disabled - never asked (see GalleryMedia::setExternalUrl)
    public function testConfigureFieldsShowsTheMediaTypeWithoutAskingForIt(): void
    {
        $typeField = $this->findField($this->createController()->configureFields(Crud::PAGE_EDIT), 'mediaType');

        $this->assertNotNull($typeField);
        $this->assertTrue($typeField->isDisplayedOn(Crud::PAGE_EDIT));
        $this->assertTrue($typeField->getFormTypeOptions()['disabled']);
    }

    // The title is the media's name and its alt text, nothing more - it no longer sources the slug, so retouching it moves no url and warrants no warning
    public function testConfigureFieldsLeavesTheTitleFreeOfAnyWarning(): void
    {
        $titleField = $this->findField($this->createController()->configureFields(Crud::PAGE_EDIT), 'title');

        $this->assertNotNull($titleField);
        $this->assertArrayNotHasKey('attr', $titleField->getFormTypeOptions());
    }

    // Editable, and the only field of the form that moves a public url - hence the padlock, and the confirmation unlocking it asks for
    public function testConfigureFieldsLocksTheSlugBehindAPadlock(): void
    {
        $slugField = $this->findField($this->createController()->configureFields(Crud::PAGE_EDIT), 'slug');

        $this->assertNotNull($slugField);
        $this->assertSame(SlugType::class, $slugField->getFormType());
        $this->assertArrayNotHasKey('disabled', $slugField->getFormTypeOptions());
        $this->assertSame(['title'], $slugField->getCustomOption(SlugField::OPTION_TARGET_FIELD_NAME));

        $confirmation = $slugField->getCustomOption(SlugField::OPTION_UNLOCK_CONFIRMATION_MESSAGE);
        $this->assertInstanceOf(TranslatableMessage::class, $confirmation);
        $this->assertSame('confirm.media_slug_change', $confirmation->getMessage());
        $this->assertSame('gallery', $confirmation->getDomain());
    }

    // Nothing about the watermark is stored on the media, so the edit screen asks the pair again - a replaced file being an upload of its own, and the only file this answer can reach
    // Unmapped, both of them: they answer for the file, and the media has no property for either one to be written to
    public function testConfigureFieldsAsksTheWatermarkAgainOnTheEditForm(): void
    {
        $fields = $this->createController()->configureFields(Crud::PAGE_EDIT);

        $watermarkField = $this->findField($fields, 'watermark');
        $this->assertNotNull($watermarkField);
        $this->assertSame(CheckboxType::class, $watermarkField->getFormType());
        $this->assertFalse($watermarkField->getFormTypeOptions()['mapped']);

        $positionField = $this->findField($fields, 'watermarkPosition');
        $this->assertNotNull($positionField);
        $this->assertSame(ChoiceType::class, $positionField->getFormType());
        $this->assertFalse($positionField->getFormTypeOptions()['mapped']);
        $this->assertSame(
            [VichWatermarkableInterface::POSITION_TOP_LEFT, VichWatermarkableInterface::POSITION_TOP_RIGHT, VichWatermarkableInterface::POSITION_BOTTOM_RIGHT, VichWatermarkableInterface::POSITION_BOTTOM_LEFT],
            array_values($positionField->getFormTypeOptions()['choices']),
        );
    }

    // Both halves of the answer reach the media, which is what the listener that stamps reads - and they have to be there before the flush that stores the uploaded file
    public function testTheWatermarkAnsweredOnTheEditFormReachesTheMedia(): void
    {
        $media = new GalleryMedia();

        ($this->captureWatermarkListener())(new FormEvent(
            $this->createEditForm(true, VichWatermarkableInterface::POSITION_TOP_LEFT),
            $media
        ));

        $this->assertTrue($media->wantsWatermark());
        $this->assertSame(VichWatermarkableInterface::POSITION_TOP_LEFT, $media->getWatermarkPosition());
    }

    // An unanswered corner is none at all, which takes the one set site-wide (see GalleryMedia::setWatermarkPosition)
    public function testAMediaLeftUnsignedOnTheEditFormWantsNoWatermark(): void
    {
        $media = (new GalleryMedia())->setWatermark(true);

        ($this->captureWatermarkListener())(new FormEvent($this->createEditForm(false, null), $media));

        $this->assertFalse($media->wantsWatermark());
        $this->assertNull($media->getWatermarkPosition());
    }

    private function createEditForm(bool $watermark, ?string $watermarkPosition): FormInterface
    {
        $children = [];
        foreach (['watermark' => $watermark, 'watermarkPosition' => $watermarkPosition] as $name => $data) {
            $child = $this->createStub(FormInterface::class);
            $child->method('getData')->willReturn($data);
            $children[$name] = $child;
        }

        $form = $this->createStub(FormInterface::class);
        $form->method('get')->willReturnCallback(static fn (string $name): FormInterface => $children[$name]);

        return $form;
    }

    private function captureWatermarkListener(): callable
    {
        $listener = null;
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('addEventListener')->willReturnCallback(function (string $eventName, callable $callback) use (&$listener, $builder) {
            if (FormEvents::POST_SUBMIT === $eventName) {
                $listener = $callback;
            }

            return $builder;
        });

        (new \ReflectionMethod(GalleryMediaCrudController::class, 'addWatermark'))->invoke($this->createController(), $builder);

        $this->assertIsCallable($listener);

        return $listener;
    }

    private function findField(iterable $fields, string $property): ?FieldDto
    {
        foreach ($fields as $field) {
            if ($property === $field->getAsDto()->getProperty()) {
                return $field->getAsDto();
            }
        }

        return null;
    }

    // --- updateEntity --------------------------------------------------------------------------------------

    // The whole point of the decoupling: a title is retouched precisely because the first one was a placeholder, and that correction must not cost a redirect
    public function testUpdateEntityLeavesTheSlugOfARetitledMediaWhereItIs(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $media = (new GalleryMedia())->setTitle('Col du Galibier')->setSlug('mont-blanc');
        $category->addMedia($media);

        $persisted = [];
        $entityManager = $this->createEntityManager(['title' => 'Mont Blanc', 'slug' => 'mont-blanc', 'category' => $category], $persisted);

        $this->createControllerWithRouter()->updateEntity($entityManager, $media);

        $this->assertSame('mont-blanc', $media->getSlug());
        $this->assertSame([], array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect)));
    }

    // A deliberate rename, unlike an automatic one, does move the url - and is recorded so the old one keeps answering
    public function testUpdateEntityRedirectsAMediaWhoseSlugWasEdited(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $media = (new GalleryMedia())->setTitle('Mont Blanc')->setSlug('col-du-galibier');
        $category->addMedia($media);

        $persisted = [];
        $entityManager = $this->createEntityManager(['title' => 'Mont Blanc', 'slug' => 'mont-blanc', 'category' => $category], $persisted);

        $this->createControllerWithRouter()->updateEntity($entityManager, $media);

        $this->assertSame('col-du-galibier', $media->getSlug());

        $redirects = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect));
        $this->assertCount(1, $redirects);
        $this->assertSame('/gallery/voyages/mont-blanc', $redirects[0]->getFromPath());
        $this->assertSame('/gallery/voyages/col-du-galibier', $redirects[0]->getToUrl());
        $this->assertTrue($redirects[0]->isPermanent());
    }

    // Typed rather than generated, so it still has to come out a slug - "Col du Galibier !" is what an admin types, not what a url holds
    public function testUpdateEntityNormalizesATypedSlug(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $media = (new GalleryMedia())->setTitle('Mont Blanc')->setSlug('Col du Galibier !');
        $category->addMedia($media);

        $persisted = [];
        $entityManager = $this->createEntityManager(['title' => 'Mont Blanc', 'slug' => 'mont-blanc', 'category' => $category], $persisted);

        $this->createControllerWithRouter()->updateEntity($entityManager, $media);

        $this->assertSame('col-du-galibier', $media->getSlug());
    }

    // The one way left to ask for a slug rebuilt from the title, now that retitling no longer does it on its own
    public function testUpdateEntityRebuildsAnEmptiedSlugFromTheTitle(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $media = (new GalleryMedia())->setTitle('Col du Galibier')->setSlug('');
        $category->addMedia($media);

        $persisted = [];
        $entityManager = $this->createEntityManager(['title' => 'Col du Galibier', 'slug' => 'mont-blanc', 'category' => $category], $persisted);

        $this->createControllerWithRouter()->updateEntity($entityManager, $media);

        $this->assertSame('col-du-galibier', $media->getSlug());
    }

    // Saving a media without touching its slug must not move it - a "-2" suffix dropped on an untouched save is a url moving with no edit behind it
    public function testUpdateEntityLeavesTheSlugAloneWhenTheTitleIsUnchanged(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $media = (new GalleryMedia())->setTitle('Mont Blanc')->setSlug('mont-blanc-2');
        $category->addMedia($media);

        $persisted = [];
        $entityManager = $this->createEntityManager(['title' => 'Mont Blanc', 'slug' => 'mont-blanc-2', 'category' => $category], $persisted);

        $this->createControllerWithRouter()->updateEntity($entityManager, $media);

        $this->assertSame('mont-blanc-2', $media->getSlug());
        $this->assertSame([], array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect)));
    }

    // The media's url carries its category's slug too, so moving it to another category moves its url just as retitling it does
    public function testUpdateEntityRedirectsAMediaMovedToAnotherCategory(): void
    {
        $oldCategory = (new GalleryCategory())->setSlug('voyages');
        $newCategory = (new GalleryCategory())->setSlug('portraits');
        $media = (new GalleryMedia())->setTitle('Mont Blanc')->setSlug('mont-blanc');
        $newCategory->addMedia($media);

        $persisted = [];
        $entityManager = $this->createEntityManager(['title' => 'Mont Blanc', 'slug' => 'mont-blanc', 'category' => $oldCategory], $persisted);

        $this->createControllerWithRouter()->updateEntity($entityManager, $media);

        $redirects = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect));
        $this->assertCount(1, $redirects);
        $this->assertSame('/gallery/voyages/mont-blanc', $redirects[0]->getFromPath());
        $this->assertSame('/gallery/portraits/mont-blanc', $redirects[0]->getToUrl());
    }

    // A slug typed onto one a sibling already answers at is suffixed rather than stealing it
    public function testUpdateEntitySuffixesASlugAlreadyTakenInTheCategory(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $category->addMedia((new GalleryMedia())->setTitle('Mont Blanc')->setSlug('mont-blanc'));
        $media = (new GalleryMedia())->setTitle('Cervin')->setSlug('mont-blanc');
        $category->addMedia($media);

        $persisted = [];
        $entityManager = $this->createEntityManager(['title' => 'Cervin', 'slug' => 'cervin', 'category' => $category], $persisted);

        $this->createControllerWithRouter()->updateEntity($entityManager, $media);

        $this->assertSame('mont-blanc-2', $media->getSlug());
    }

    // --- deleteEntity --------------------------------------------------------------------------------------

    // A media page is declared in the sitemap (see GallerySitemapProvider), so its url is left answering 410 rather than the 404 a crawler retries for months
    public function testDeleteEntityLeavesTheUrlOfADeletedMediaAnsweringGone(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $media = (new GalleryMedia())->setTitle('Mont Blanc')->setSlug('mont-blanc');
        $category->addMedia($media);

        $persisted = [];
        $entityManager = $this->createEntityManager([], $persisted);

        $this->createControllerWithRouter()->deleteEntity($entityManager, $media);

        $redirects = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect));
        $this->assertCount(1, $redirects);
        $this->assertSame('/gallery/voyages/mont-blanc', $redirects[0]->getFromPath());
        $this->assertNull($redirects[0]->getToUrl());
        $this->assertTrue($redirects[0]->isGone());
    }

    // A media stored before slugs existed has no public url to answer for - nothing was ever declared for it
    public function testDeleteEntityRecordsNothingForAMediaWithoutASlug(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $media = (new GalleryMedia())->setTitle('Mont Blanc');
        $category->addMedia($media);

        $persisted = [];
        $entityManager = $this->createEntityManager([], $persisted);

        $this->createControllerWithRouter()->deleteEntity($entityManager, $media);

        $this->assertSame([], array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Redirect)));
    }
}
