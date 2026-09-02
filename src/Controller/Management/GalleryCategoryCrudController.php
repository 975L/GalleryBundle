<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller\Management;

use c975L\ConfigBundle\Management\EasyAdminActionHelper;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ContentExporter;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Field\GalleryDataField;
use c975L\GalleryBundle\Management\GalleryBlockOwnerResolver;
use c975L\GalleryBundle\Management\GalleryExportProvider;
use c975L\GalleryBundle\Management\GalleryImportProvider;
use c975L\GalleryBundle\Model\GalleryMediaBatch;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Service\GalleryAutomaticProvider;
use c975L\GalleryBundle\Service\GalleryCustomizationRegistry;
use c975L\GalleryBundle\Service\GalleryLatestProvider;
use c975L\GalleryBundle\Service\GalleryMediaArchiver;
use c975L\GalleryBundle\Service\GalleryMediaFactory;
use c975L\GalleryBundle\Service\GalleryMediaMover;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use c975L\GalleryBundle\Service\UploadLimits;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Form\BlockType;
use c975L\UiBundle\Form\TrixEditorType;
use c975L\UiBundle\Form\Util\CollectionReconciler;
use c975L\UiBundle\Repository\RatingRepository;
use c975L\UiBundle\Service\BlockMoveRowAttrBuilder;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Endroid\QrCode\Builder\Builder;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

// The single EasyAdmin menu entry for the whole gallery feature (see this bundle's own MenuProvider), mounted on /gallery rather than the /gallery-category the class name would give: a site's galleries are its categories, so this screen is the gallery
// It lists the categories alone, never the medias - a category's medias are shown on its edit screen, each opening the media edit form (see GalleryMediaCrudController, which has no listing of its own)
#[AdminRoute(path: '/gallery', name: 'gallery')]
class GalleryCategoryCrudController extends AbstractCrudController
{
    // Also the token ids the edit template renders in its selection form (see gallery_category_edit.html.twig), which posts to deleteMedias(), editMedias() or saveMediasLayout() depending on the button pressed - one token each, an html form holding a single "_token" field that they would otherwise share
    public const DELETE_MEDIAS_CSRF_TOKEN = 'gallery_media_delete_selection';
    public const EDIT_MEDIAS_CSRF_TOKEN = 'gallery_media_edit_selection';
    public const MEDIAS_LAYOUT_CSRF_TOKEN = 'gallery_medias_layout';

    // The two actions of the trash are reached by a GET, so their token travels in the url the row buttons carry (see restoreAction() and deletePermanentlyAction()) - a confirmation modal only holds a click back, never a request forged elsewhere
    public const RESTORE_CSRF_TOKEN = 'gallery_category_restore';
    public const DELETE_PERMANENTLY_CSRF_TOKEN = 'gallery_category_delete_permanently';

    // The fields editMedias() applies to a whole selection at once, each named by the button posting it
    public const EDITABLE_FIELDS = ['credits', 'rightsReserved', 'hidden', 'printable'];

    // The value the "new gallery" entry of the move select carries, told apart from an id by not being one (see moveTarget)
    public const MOVE_TARGET_NEW = 'new';

    public function __construct(
        private readonly GalleryCategoryRepository $galleryCategoryRepository,
        private readonly SluggerInterface $slugger,
        private readonly TranslatorInterface $translator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly ContentExporter $contentExporter,
        private readonly GalleryExportProvider $galleryExportProvider,
        private readonly AdminContextProviderInterface $adminContextProvider,
        private readonly BlockMoveRowAttrBuilder $blockMoveRowAttrBuilder,
        private readonly GalleryMediaFactory $galleryMediaFactory,
        private readonly GalleryMediaMover $galleryMediaMover,
        private readonly UploadLimits $uploadLimits,
        private readonly GalleryUrlRedirector $urlRedirector,
        private readonly ConfigServiceInterface $configService,
        private readonly RequestStack $requestStack,
        private readonly GalleryMediaRepository $galleryMediaRepository,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly GalleryMediaArchiver $galleryMediaArchiver,
        private readonly GalleryAutomaticProvider $automaticProvider,
        private readonly GalleryLatestProvider $latestProvider,
        private readonly GalleryCustomizationRegistry $customizationRegistry,
    ) {
    }

    // Whether the index is currently showing the trash rather than the galleries - read from the query string, the same switch SiteBundle's PageCrudController uses, so one screen serves both lists instead of a second controller mirroring this one
    private function isTrash(): bool
    {
        return (bool) $this->requestStack->getCurrentRequest()?->query->get('trash');
    }

    // The medias' own trash, on a category's edit screen, under a parameter of its own: it shares the controller with the index above, and one name for the two would have the edit screen's trash strip the index of its actions
    private function isMediasTrash(): bool
    {
        return (bool) $this->requestStack->getCurrentRequest()?->query->get('mediasTrash');
    }

    public static function getEntityFqcn(): string
    {
        return GalleryCategory::class;
    }

    // The role every gallery management screen sits behind, ConfigBundle's own entry rather than a constant - a site decides who edits its galleries, and it is the same role the public pages offer their edit button to (see gallery/media.html.twig)
    private function roleNeeded(): string
    {
        return (string) $this->configService->get('site-role-editor');
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(t('label.gallery_category', [], 'gallery'))
            ->setEntityLabelInPlural(t('label.gallery_categories', [], 'gallery'))
            ->setEntityPermission($this->roleNeeded())
            // The order the site itself lists them in (see GalleryCategoryRepository::findAllOrdered), so a gallery is looked for here where it is found there
            ->setDefaultSort(['title' => 'ASC'])
            // The row actions are icon-only (see configureActions), so they cost less width side by side than the dropdown they'd otherwise be folded into
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/index', '@c975LGallery/management/gallery_category_index.html.twig')
            ->overrideTemplate('crud/edit', '@c975LGallery/management/gallery_category_edit.html.twig')
            ->overrideTemplate('crud/new', '@c975LGallery/management/gallery_category_new.html.twig')
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        // Same "export selection" as SiteBundle's PageCrudController::exportSelection() - see that method's own comment for why
        $actions->add(Crud::PAGE_INDEX, Action::new('exportSelection', t('action.export_selection', [], 'gallery'), 'fa fa-file-export')
            ->createAsBatchAction()
            ->linkToCrudAction('exportSelection'));
        $actions->setPermission('exportSelection', $this->roleNeeded());

        // Medias are only ever added from the category they belong to (NEW is disabled on GalleryMediaCrudController, which has no upload button of its own): the category is what this link carries, and the upload screen shows it without letting it be changed
        // Icon-only among the index's row buttons. The edit screen has no action of its own: up in the toolbar the button sits above the blocks collection, whose own "add" button is then the one clicked to add a media - it is rendered down with the medias instead (see configureResponseParameters and gallery_category_edit.html.twig)
        // Never on the automatic gallery: it displays medias it doesn't hold, so an upload made from it would land in a category that shows none of its own
        $uploadMediasAction = Action::new('uploadMedias', t('label.gallery_upload_medias', [], 'gallery'), 'fas fa-upload')
            ->linkToUrl(fn (GalleryCategory $category): string => $this->uploadMediasUrl($category))
            ->displayIf(static fn (GalleryCategory $category): bool => !$category->isAutomatic());
        $actions->add(Crud::PAGE_INDEX, EasyAdminActionHelper::toIconOnly(
            $uploadMediasAction,
            $this->translator->trans('label.gallery_upload_medias', [], 'gallery'),
        ));
        $actions->setPermission('uploadMedias', $this->roleNeeded());

        // Opens the category on the public site, in a new tab - the same action a Page carries in SiteBundle, a category being what a gallery url points at. No preview twin: a category has nothing to publish, it is online the moment it exists
        // The route takes the slug alone, its prefix being filled from the request context (see GalleryRoutePrefixListener)
        $viewOnSiteAction = Action::new('viewOnSite', t('action.view_on_site', [], 'gallery'), 'fa fa-external-link-alt')
            ->linkToUrl(fn (GalleryCategory $category): string => $this->generateUrl('gallery_category', ['category' => $category->getSlug()]))
            ->setHtmlAttributes(['target' => '_blank'])
            ->addCssClass('btn btn-secondary');
        $actions->setPermission('viewOnSite', $this->roleNeeded());

        // Lets the admin back out of a create/edit without saving - mirrors EasyAdmin's own built-in actions (linkToCrudAction targeting INDEX, same as Action::INDEX itself)
        $cancelAction = Action::new('cancel', $this->translator->trans('action.cancel', [], 'EasyAdminBundle'), 'fa fa-times')
            ->linkToCrudAction(Action::INDEX)
            ->addCssClass('btn btn-secondary');

        // In the trash a category is off the site and takes no upload, so the two actions that assume the opposite go away and the two that only make sense there appear. "exportSelection" deliberately stays: exporting a category out of the trash, files and all, is how it is carried to another site or kept aside before it is dropped for good
        if ($this->isTrash()) {
            $actions
                ->add(Crud::PAGE_INDEX, $this->restoreAction())
                ->add(Crud::PAGE_INDEX, $this->deletePermanentlyAction())
                ->setPermission('restore', $this->roleNeeded())
                // The only irreversible action of the screen, held one role higher than the rest of the gallery - same split as SiteBundle's own deletePermanently()
                ->setPermission('deletePermanently', (string) $this->configService->get('site-role-admin'))
                ->disable(Action::NEW, Action::DELETE, 'uploadMedias', 'viewOnSite')
            ;
        }

        return $actions
            ->add(Crud::PAGE_INDEX, $this->trashAction())
            ->add(Crud::PAGE_INDEX, $viewOnSiteAction)
            ->add(Crud::PAGE_EDIT, $viewOnSiteAction)
            ->add(Crud::PAGE_NEW, $cancelAction)
            ->add(Crud::PAGE_EDIT, $cancelAction)
            // A gallery is dropped from its own screen as a media is from its (see GalleryMediaCrudController), rather than only from the row button one screen above - deleting it takes its medias and its heading blocks along, the association cascading
            ->add(Crud::PAGE_EDIT, Action::DELETE)
            ->update(Crud::PAGE_EDIT, Action::DELETE, fn (Action $action) => $action
                // "delete" now only moves the category to the trash, so it says so - and the confirmation says what survives, which is everything
                ->setLabel(t('action.move_to_trash', [], 'gallery'))
                ->setIcon('fa fa-trash-alt')
                ->displayIf(static fn (GalleryCategory $category): bool => !$category->isUncategorized()))
            ->setPermission(Action::INDEX, $this->roleNeeded())
            ->setPermission(Action::NEW, $this->roleNeeded())
            ->setPermission(Action::EDIT, $this->roleNeeded())
            ->setPermission(Action::DELETE, $this->roleNeeded())
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.edit', [], 'EasyAdminBundle'),
            ))
            ->update(Crud::PAGE_INDEX, 'viewOnSite', fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.view_on_site', [], 'gallery'),
            ))
            // The catch-all "Non classé" category must always exist as a fallback for medias uploaded without a real one picked (see GalleryCategoryRepository::findOrCreateUncategorized)
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action
                    ->setLabel(t('action.move_to_trash', [], 'gallery'))
                    ->setIcon('fa fa-trash-alt')
                    ->displayIf(static fn (GalleryCategory $category): bool => !$category->isUncategorized()),
                $this->translator->trans('action.move_to_trash', [], 'gallery'),
            ))
            // Detail adds no information beyond what edit already shows
            ->disable(Action::DETAIL)
        ;
    }

    // Toggles between "go to trash" and "back to galleries", depending on where we currently are - one button rather than two, the screen it leads to being the one you are not on
    private function trashAction(): Action
    {
        $action = $this->isTrash()
            ? Action::new('trash', t('label.gallery_categories', [], 'gallery'), 'fa fa-images')
                ->linkToUrl(fn (): string => $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction(Action::INDEX)
                    ->unset('trash')
                    ->generateUrl())
            : Action::new('trash', t('action.trash', [], 'gallery'), 'fa fa-trash-alt')
                ->linkToUrl(fn (): string => $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction(Action::INDEX)
                    ->set('trash', 1)
                    ->generateUrl());

        return $action
            ->createAsGlobalAction()
            ->addCssClass('btn btn-secondary');
    }

    // Puts a category back on the site, its medias and files never having moved - only shown once already in the trash
    // Built as a url rather than linked to the crud action, so the csrf token the action checks travels with it
    private function restoreAction(): Action
    {
        return EasyAdminActionHelper::toIconOnly(
            Action::new('restore', t('action.restore', [], 'gallery'), 'fa fa-trash-restore')
                ->linkToUrl(fn (GalleryCategory $category): string => $this->trashActionUrl('restore', $category, self::RESTORE_CSRF_TOKEN)),
            $this->translator->trans('action.restore', [], 'gallery'),
        );
    }

    // The one action that actually deletes - only shown once already in the trash, so it always takes two deliberate steps to lose a gallery
    private function deletePermanentlyAction(): Action
    {
        return EasyAdminActionHelper::toIconOnly(
            Action::new('deletePermanently', t('action.delete_permanently', [], 'gallery'), 'fa fa-trash')
                ->linkToUrl(fn (GalleryCategory $category): string => $this->trashActionUrl('deletePermanently', $category, self::DELETE_PERMANENTLY_CSRF_TOKEN))
                ->askConfirmation(t('confirm.delete_permanently', [], 'gallery')),
            $this->translator->trans('action.delete_permanently', [], 'gallery'),
        );
    }

    // The url of a trash row button, its csrf token in the query string - the action is a GET, which an <img> on a third-party page would otherwise fire on a logged-in admin
    private function trashActionUrl(string $action, GalleryCategory $category, string $tokenId): string
    {
        return $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction($action)
            ->setEntityId($category->getId())
            ->set('trash', 1)
            ->set('token', $this->csrfTokenManager->getToken($tokenId)->getValue())
            ->generateUrl();
    }

    // The trash listing both actions come back to, whether they ran or were refused
    private function trashIndexUrl(): string
    {
        return $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->set('trash', 1)
            ->generateUrl();
    }

    // Only lists categories that are not in the trash, or only those that are when viewing it
    #[\Override]
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        // The automatic galleries are written here, before the listing reads its rows, so an admin opening his galleries simply finds them among the others - nobody creates them, and nothing else on this screen has to know they are special (see GalleryAutomaticProvider)
        $this->automaticProvider->ensureCategories();

        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.isDeleted = :isDeleted')
            ->setParameter('isDeleted', $this->isTrash())
        ;
    }

    // Move to trash: flags the category and nothing else. Its medias keep their own flag untouched, so restoring it gives back exactly the ones that were showing, and the cascade on GalleryCategory::$medias is never reached - which is what leaves every file on disk
    #[\Override]
    public function deleteEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if ($entityInstance instanceof GalleryCategory) {
            $entityInstance->setIsDeleted(true);
            $entityManager->flush();

            return;
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    // Restores a category out of the trash - nothing to put back, nothing having been taken away
    #[AdminRoute('/{entityId}/restore')]
    public function restore(AdminContext $context, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted($this->roleNeeded());

        if (!$this->isCsrfTokenValid(self::RESTORE_CSRF_TOKEN, $request->query->getString('token'))) {
            return $this->redirect($this->trashIndexUrl());
        }

        $category = $context->getEntity()->getInstance();
        $category->setIsDeleted(false);
        $entityManager->flush();

        // Same pair persistEntity() lifts for a category created under a freed slug: a "gone" row an earlier permanent deletion left on that path would shadow the gallery for good, RedirectSubscriber running before the router
        if (\is_string($category->getSlug())) {
            $url = $this->generateUrl('gallery_category', ['category' => $category->getSlug()]);
            $this->urlRedirector->release($entityManager, $url);
            $this->urlRedirector->release($entityManager, $url . '/*');
            $entityManager->flush();
        }

        $this->addFlash('success', $this->translator->trans('flash.gallery_category_restored', [], 'gallery'));

        return $this->redirect($this->trashIndexUrl());
    }

    // Removes the category for good, with its medias, its heading blocks and every file of the set - the cascade and GalleryMediaDerivativeCleanupListener only ever run from here
    #[AdminRoute('/{entityId}/delete-permanently')]
    public function deletePermanently(AdminContext $context, Request $request, EntityManagerInterface $entityManager, RatingRepository $ratingRepository): Response
    {
        $this->denyAccessUnlessGranted((string) $this->configService->get('site-role-admin'));

        if (!$this->isCsrfTokenValid(self::DELETE_PERMANENTLY_CSRF_TOKEN, $request->query->getString('token'))) {
            return $this->redirect($this->trashIndexUrl());
        }

        $category = $context->getEntity()->getInstance();

        // The 410 the trash served only lasted as long as the category could still be restored; a "gone" Redirect keeps answering it for good, which search engines act on far faster than the plain 404 the url would otherwise fall back to. Recorded here rather than at the move to trash, where it used to sit: a category that can still come back must not have its url declared gone
        if (\is_string($category->getSlug())) {
            $this->urlRedirector->recordGoneTree($entityManager, $this->generateUrl('gallery_category', ['category' => $category->getSlug()]));
        }

        // The visitors' likes, which hang off "gallery_media" + id rather than off a relation (see c975L\UiBundle\Entity\Rating): the medias go with the cascade below, and nothing would take their likes with them
        $mediaIds = $this->mediaIds($category->getMedias());

        $entityManager->remove($category);
        $entityManager->flush();

        // Only once the medias have actually gone: a flush that fails leaves them in place, and they must find their likes where they left them
        $this->dropRatings($ratingRepository, $mediaIds);

        $this->addFlash('success', $this->translator->trans('flash.gallery_category_deleted_permanently', [], 'gallery'));

        return $this->redirect($this->trashIndexUrl());
    }

    // The upload screen for a category, reached from the index's row button and from the edit screen's media list alike
    private function uploadMediasUrl(GalleryCategory $category): string
    {
        return $this->generateUrl(
            GalleryMediaUploadController::UPLOAD_ROUTE,
            ['category' => $category->getId()],
        );
    }

    // The creation form carries files (see configureFields), so it meets post_max_size just as the upload screen does - php having dropped the request, the category wouldn't be created and the screen would redisplay itself blank and silent (see UploadLimits::isTruncatedRequest)
    #[\Override]
    public function new(AdminContext $context): KeyValueStore | Response
    {
        $request = $context->getRequest();

        if ($this->uploadLimits->isTruncatedRequest($request)) {
            $this->addFlash('danger', $this->translator->trans('label.gallery_upload_batch_refused', [], 'gallery'));

            return $this->redirect($request->getUri());
        }

        return parent::new($context);
    }

    #[\Override]
    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->addMediaBatch($this->addSlugNormalizer(parent::createNewFormBuilder($entityDto, $formOptions, $context)));
    }

    // The files picked on the creation form become the category's medias right away, saved with it by the cascade on GalleryCategory::$medias - a category is created to hold medias, and saving an empty one only to reach the upload screen afterwards is a step for nothing
    // Nothing is persisted here: an invalid form is redisplayed and the medias built go no further than the instance it holds, EasyAdmin only ever saving a form it found valid
    private function addMediaBatch(FormBuilderInterface $formBuilder): FormBuilderInterface
    {
        $formBuilder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $category = $event->getData();
            if (!$category instanceof GalleryCategory) {
                return;
            }

            $form = $event->getForm();
            $this->galleryMediaFactory->createFromUploads(
                $category,
                $form->get('files')->getData() ?? [],
                new GalleryMediaBatch(
                    $form->get('titleRoot')->getData(),
                    $form->get('credits')->getData(),
                    (bool) $form->get('rightsReserved')->getData(),
                    (bool) $form->get('keepOriginals')->getData(),
                    (bool) $form->get('watermark')->getData(),
                    $form->get('watermarkPosition')->getData(),
                ),
            );
        });

        return $formBuilder;
    }

    // Normalizes the submitted slug before validation instead of at persist time, so the unique check (see GalleryCategory) sees the exact value that would be stored - an admin typing "Photos !" must be told the slug is taken, not hit the database unique constraint
    // A renamed category also gets its slug rebuilt from its new title, which is what the "title-confirm" warning announces (see configureFields) and what updateEntity() then redirects the old url to - EasyAdmin's SlugField only follows its target field client-side while the slug is still empty (see its field-slug.js), so on an edit form nothing ever resyncs it
    private function addSlugNormalizer(FormBuilderInterface $formBuilder): FormBuilderInterface
    {
        $formBuilder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                return;
            }

            $category = $event->getForm()->getData();
            $title = $data['title'] ?? null;
            if ($category instanceof GalleryCategory && null !== $category->getId() && \is_string($title) && $title !== $category->getTitle()) {
                $data['slug'] = $title;
            }

            if (!\is_string($data['slug'] ?? null)) {
                return;
            }

            $data['slug'] = strtolower($this->slugger->slug($data['slug'])->toString());
            $event->setData($data);
        });

        return $formBuilder;
    }

    // Removing the very last block leaves nothing submitted at all for "blocks" (an HTML form can't represent an empty array, only an absent key), which has to be normalized to [] here or Symfony skips add/remove handling entirely for the whole field - same listener as SiteBundle's PageCrudController, minus its max_input_vars guard: a category holds a heading, not a whole page's worth of blocks
    #[\Override]
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $formBuilder = $this->addSlugNormalizer(parent::createEditFormBuilder($entityDto, $formOptions, $context));

        $formBuilder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                return;
            }

            $category = $event->getForm()->getData();
            if ($category instanceof GalleryCategory) {
                CollectionReconciler::pruneRemoved(
                    $category->getBlocks(),
                    $data['blocks'] ?? [],
                    static fn (Block $block) => $category->removeBlock($block)
                );
            }

            if (!isset($data['blocks'])) {
                $data['blocks'] = [];
                $event->setData($data);
            }
        });

        return $formBuilder;
    }

    // Created category - a slug freed by an earlier deletion is still answering 410 Gone (see deleteEntity), and RedirectSubscriber runs before the router: the page would exist while its url kept saying it doesn't
    // Its wildcard goes too, that row covering every media url below the slug, and so does the url of each media the creation form brought along (see addMediaBatch)
    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof GalleryCategory && \is_string($entityInstance->getSlug())) {
            $this->releaseCategoryUrl($entityManager, $entityInstance);

            foreach ($entityInstance->getMedias() as $media) {
                if (\is_string($media->getSlug())) {
                    $this->urlRedirector->release($entityManager, $this->generateUrl('gallery_media', [
                        'category' => $entityInstance->getSlug(),
                        'slug' => $media->getSlug(),
                    ]));
                }
            }
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    // The url a created category answers on, and the wildcard covering every media url below it - both freed of the "gone" row an earlier permanent deletion left there, ConfigBundle's RedirectSubscriber running before the router
    // Shared with the gallery the move toolbar creates on the spot (see createMoveTarget), which never goes through persistEntity()
    private function releaseCategoryUrl(EntityManagerInterface $entityManager, GalleryCategory $category): void
    {
        $url = $this->generateUrl('gallery_category', ['category' => $category->getSlug()]);

        $this->urlRedirector->release($entityManager, $url);
        $this->urlRedirector->release($entityManager, $url . '/*');
    }

    // Updated category - a rename moves its public url (see addSlugNormalizer), so the old one is redirected to the new one rather than left to 404 on every link and search result already pointing at it, exactly as SiteBundle's PageCrudController does for a page
    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $originalSlug = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)['slug'] ?? null;

        if ($entityInstance instanceof GalleryCategory && \is_string($originalSlug) && $originalSlug !== $entityInstance->getSlug()) {
            $this->redirectSlugChange($entityManager, $originalSlug, (string) $entityInstance->getSlug());
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    // Both urls are generated rather than concatenated, the first segment being the configured route prefix (see GalleryRoutePrefix) - the row itself is written by GalleryUrlRedirector, shared with the media CRUD, which moves a url the same way
    private function redirectSlugChange(EntityManagerInterface $entityManager, string $oldSlug, string $newSlug): void
    {
        $oldUrl = $this->generateUrl('gallery_category', ['category' => $oldSlug]);
        $newUrl = $this->generateUrl('gallery_category', ['category' => $newSlug]);

        $this->urlRedirector->record($entityManager, $oldUrl, $newUrl);

        // The category's slug is also the segment above each of its medias (see GalleryController::media), so renaming it moves every media url under it - a second row, wildcarded (ConfigBundle's own convention, see RedirectSubscriber::resolve), sends them to the category rather than leaving each to 404
        $this->urlRedirector->record($entityManager, $oldUrl . '/*', $newUrl);
    }

    // What this site adds to a gallery and no other site has, rendered from the form type it declares - a site declaring none gets no field at all (see c975L\GalleryBundle\Contract\GalleryCustomizationProviderInterface)
    /** @return list<GalleryDataField> */
    private function dataFields(): array
    {
        $formType = $this->customizationRegistry->getCategoryDataFormType();

        if (null === $formType) {
            return [];
        }

        return [
            GalleryDataField::new('data', t('label.data', [], 'gallery'))
                ->setFormType($formType),
        ];
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $entity = $this->adminContextProvider->getContext()?->getEntity()?->getInstance();

        return [
            IdField::new('id')->onlyOnIndex(),

            Field::new('medias')
                ->setLabel(t('label.thumbnail', [], 'gallery'))
                ->onlyOnIndex()
                ->setTemplatePath('@c975LGallery/management/_gallery_category_thumbnail.html.twig'),

            // Renaming a category rebuilds its slug (see addSlugNormalizer), so its public url changes - the admin is asked to confirm before the field takes a single keystroke, through UiBundle's "title-confirm" controller
            // Left out of the creation form: the modal it reuses is only rendered on the edit/index/detail pages, and a category being created has no url to preserve yet
            TextField::new('title')
                ->setLabel(t('label.title', [], 'gallery'))
                ->setRequired(true)
                ->setFormTypeOption('attr', Crud::PAGE_NEW === $pageName ? [] : [
                    'data-controller' => 'title-confirm',
                    'data-action' => 'focus->title-confirm#confirm click->title-confirm#confirm',
                    'data-title-confirm-message-value' => $this->translator->trans('confirm.title_change', [], 'gallery'),
                ]),

            // Counted on the loaded collection (GalleryCategory::getMediasCount()), so there is no column to sort on
            // Rendered by a template, like the thumbnail above: the automatic gallery only receives its medias in configureResponseParameters(), long after the fields are processed, so the count has to be read at render time
            Field::new('mediasCount')
                ->setLabel(t('label.gallery_medias', [], 'gallery'))
                ->setSortable(false)
                ->onlyOnIndex()
                ->setTemplatePath('@c975LGallery/management/_gallery_category_medias_count.html.twig'),

            SlugField::new('slug')
                ->setLabel(t('label.slug', [], 'gallery'))
                ->setTargetFieldName('title')
                ->setRequired(true)
                ->setHelp(t('label.slug_help', [], 'gallery')),

            // TrixEditorType rather than EasyAdmin's own TextEditorField: it is the editor every other rich-text field of the ecosystem uses (see UiBundle's block form types), and its widget is where the rephrase button is wired - EasyAdmin's own renders through a different form block, which would leave this field the only one without it
            // Labelled from ConfigBundle's own 'config' domain, the very key SiteBundle's PageCrudController reads: the field plays the same role on a category as on a page, and an admin meets it under one name across the back office
            TextareaField::new('summarySocialNetwork')
                ->setLabel(t('label.summary_social_network', [], 'config'))
                ->setHelp(t('label.gallery_summary_social_network_help', [], 'gallery'))
                ->setFormType(TrixEditorType::class)
                ->hideOnIndex(),

            // Kept on the index, where EasyAdmin draws it as a switch saving on the spot: taking a gallery off the site is an answer to something happening now, and reading the listing is where an admin sees which ones are masked
            // Offered at creation too, a gallery being filled long before it is shown - the medias uploaded into it are then public the day the switch is turned back, with nothing to go through one by one
            BooleanField::new('hidden')
                ->setLabel(t('label.gallery_category_hidden', [], 'gallery'))
                ->setHelp(t('help.gallery_category_hidden', [], 'gallery')),

            ...$this->dataFields(),

            // The same batch as the upload screen's, offered at creation time so a category can be filled in one go (see createNewFormBuilder for where the files become medias, and GalleryMediaBatchUploadType for the screen that adds them to a category that already exists)
            // Unmapped: a category has no files of its own, and the medias they become are built from them afterwards
            Field::new('files')
                ->setLabel(t('label.gallery_files', [], 'gallery'))
                ->setHelp($this->translator->trans('label.gallery_batch_files_help', [
                    '%files%' => $this->uploadLimits->getMaxFiles(),
                    '%size%' => $this->uploadLimits->toMegabytes($this->uploadLimits->getMaxFileSize()),
                    '%total%' => $this->uploadLimits->toMegabytes($this->uploadLimits->getMaxBatchSize()),
                ], 'gallery'))
                ->setFormType(FileType::class)
                ->setFormTypeOptions([
                    'mapped' => false,
                    'required' => false,
                    'multiple' => true,
                    // Weighed against the server's ceilings the moment they are picked, the check itself being wired by the template this screen overrides (see gallery_category_new.html.twig)
                    'attr' => [
                        'data-gallery-upload-limits-target' => 'input',
                        'data-action' => 'change->gallery-upload-limits#check',
                    ],
                    'constraints' => [new All([new Image(maxSize: UploadLimits::MAX_FILE_SIZE)])],
                ])
                ->onlyWhenCreating(),

            // Seeds the title of every media of the batch, numbered from where the category leaves off (see GalleryMediaFactory) - same field as the upload screen's, a category being filled the same way whichever screen does it
            Field::new('titleRoot')
                ->setLabel(t('label.gallery_title_root', [], 'gallery'))
                ->setHelp(t('label.gallery_batch_title_root_help', [], 'gallery'))
                ->setFormType(TextType::class)
                ->setFormTypeOptions(['mapped' => false, 'required' => false])
                ->onlyWhenCreating(),

            Field::new('credits')
                ->setLabel(t('label.credits', [], 'gallery'))
                ->setHelp(t('label.gallery_batch_credits_help', [], 'gallery'))
                ->setFormType(TextType::class)
                ->setFormTypeOptions(['mapped' => false, 'required' => false])
                ->onlyWhenCreating(),

            Field::new('rightsReserved')
                ->setLabel(t('label.rights_reserved', [], 'gallery'))
                ->setFormType(CheckboxType::class)
                ->setFormTypeOptions([
                    'mapped' => false,
                    'required' => false,
                    'label_attr' => ['class' => 'checkbox-switch'],
                ])
                ->onlyWhenCreating(),

            Field::new('keepOriginals')
                ->setLabel(t('label.gallery_keep_originals', [], 'gallery'))
                ->setHelp(t('label.gallery_batch_keep_originals_help', [], 'gallery'))
                ->setFormType(CheckboxType::class)
                ->setFormTypeOptions([
                    'mapped' => false,
                    'required' => false,
                    'label_attr' => ['class' => 'checkbox-switch'],
                ])
                ->onlyWhenCreating(),

            // Same pair as the upload screen's, a category being filled the same way whichever screen does it (see GalleryMediaBatchUploadType)
            Field::new('watermark')
                ->setLabel(t('label.gallery_watermark', [], 'gallery'))
                ->setHelp(t('label.gallery_batch_watermark_help', [], 'gallery'))
                ->setFormType(CheckboxType::class)
                ->setFormTypeOptions([
                    'mapped' => false,
                    'required' => false,
                    'label_attr' => ['class' => 'checkbox-switch'],
                ])
                ->onlyWhenCreating(),

            Field::new('watermarkPosition')
                ->setLabel(t('label.gallery_watermark_position', [], 'gallery'))
                ->setHelp(t('label.gallery_batch_watermark_position_help', [], 'gallery'))
                ->setFormType(ChoiceType::class)
                // Choice labels are translation keys, not t() calls: they are array keys, and an array key can only be a string
                ->setFormTypeOptions([
                    'mapped' => false,
                    'required' => false,
                    // t() rather than the key alone: "choice_translation_domain" only covers the choices, the placeholder being translated in the form's own domain - EasyAdmin's here, where the key does not exist. A TranslatableMessage carries the domain with it, whatever the form is rendered in
                    'placeholder' => t('label.gallery_watermark_position_default', [], 'gallery'),
                    'choice_translation_domain' => 'gallery',
                    'choices' => [
                        'label.gallery_watermark_top_left' => VichWatermarkableInterface::POSITION_TOP_LEFT,
                        'label.gallery_watermark_top_right' => VichWatermarkableInterface::POSITION_TOP_RIGHT,
                        'label.gallery_watermark_bottom_right' => VichWatermarkableInterface::POSITION_BOTTOM_RIGHT,
                        'label.gallery_watermark_bottom_left' => VichWatermarkableInterface::POSITION_BOTTOM_LEFT,
                    ],
                ])
                ->onlyWhenCreating(),

            // Editorial heading rendered above the category's grid (see gallery/category.html.twig)
            CollectionField::new('blocks')
                ->setLabel(t('label.blocks', [], 'ui'))
                // CollectionField's own default is "col-md-8 col-xxl-7", which every nesting level of blocks-in-blocks eats into - same reasoning as SiteBundle's PageCrudController
                ->setColumns('col-12')
                ->setEntryType(BlockType::class)
                ->allowAdd()
                ->allowDelete()
                ->setFormTypeOption('by_reference', false)
                ->setFormTypeOption('row_attr', $this->blockMoveRowAttrBuilder->build(GalleryBlockOwnerResolver::TYPE_CATEGORY, $entity instanceof GalleryCategory ? $entity->getId() : null))
                ->hideOnIndex(),
        ];
    }

    // The category's medias are listed under its edit form (see gallery_category_edit.html.twig), each thumbnail opening the media edit screen - the urls are built here rather than in the template, which would otherwise have to name the media CRUD controller by its fqcn
    // Each carries its category, which is what sends the admin back to this very screen once the media is saved or deleted (see GalleryMediaCrudController::index())
    #[\Override]
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        // The ceilings the creation form's files are weighed against before they are sent, the template having no other way to reach them (see gallery_category_new.html.twig)
        if (Crud::PAGE_NEW === $responseParameters->get('pageName')) {
            $responseParameters->set('upload_limits', $this->uploadLimits);
        }

        // The listing draws each category's thumbnail and counts its medias, which an automatic one only has once it has been handed the list it shows (see GalleryAutomaticProvider)
        if (Crud::PAGE_INDEX === $responseParameters->get('pageName')) {
            $entities = $responseParameters->get('entities');
            $categories = [];
            if (is_iterable($entities)) {
                foreach ($entities as $entityDto) {
                    $instance = $entityDto instanceof EntityDto ? $entityDto->getInstance() : null;
                    if ($instance instanceof GalleryCategory) {
                        $categories[] = $instance;
                    }
                }
            }
            $this->automaticProvider->hydrate($categories);

            return $responseParameters;
        }

        if (Crud::PAGE_EDIT !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $category = $responseParameters->get('entity')?->getInstance();
        $mediaEditUrls = [];

        if ($category instanceof GalleryCategory) {
            // The medias the screen actually lists, its own or the last additions of the whole gallery (see mediasShown)
            $medias = $this->mediasShown($category);

            // The automatic gallery takes no upload, holds no trash and arranges nothing: its medias belong to other categories, which is where each of them is added, trashed and ordered
            if ($category->isAutomatic()) {
                $responseParameters->set('medias_automatic', true);
                // Cut into the days it was read as, for the gallery of the last additions alone: what an admin credits or downloads in one go is an upload session, and a heading per day is what tells one from the next. The prints are not an upload session, and are listed as one grid
                $responseParameters->set('medias_by_day', GalleryCategory::AUTOMATIC_LATEST === $category->getAutomaticKind()
                    ? $this->latestProvider->getMediasByDay()
                    : []);
                $responseParameters->set('medias_trash', false);
                $responseParameters->set('medias_trash_count', 0);
            } else {
                // The "Add medias" button sits with the medias rather than up in the toolbar, where it was above the blocks collection and its own "add" button (see gallery_category_edit.html.twig)
                $responseParameters->set('media_upload_url', $this->uploadMediasUrl($category));

                $responseParameters->set('medias_trash', $this->isMediasTrash());
                $responseParameters->set('medias_trash_count', count(array_filter(
                    $category->getMedias()->toArray(),
                    static fn (GalleryMedia $media): bool => $media->isDeleted()
                )));
            }

            $responseParameters->set('medias', $medias);

            // The galleries the medias toolbar offers to move a selection into - the same list the media's own edit form offers (see moveTarget), minus the one being looked at, which is where they already are
            $responseParameters->set('move_targets', array_values(array_filter(
                $this->galleryCategoryRepository->findAllOrdered(),
                static fn (GalleryCategory $target): bool => !$target->isAutomatic() && $target !== $category
            )));

            // Built off the list the screen shows rather than off the category's own collection: on the automatic gallery the medias are other categories' - each is edited from here and comes back here once saved, the category the url carries being where the media CRUD sends the admin back to
            foreach ($medias as $media) {
                $mediaEditUrls[$media->getId()] = $this->adminUrlGenerator
                    ->setController(GalleryMediaCrudController::class)
                    ->setAction(Action::EDIT)
                    ->setEntityId($media->getId())
                    ->set('category', $category->getId())
                    ->generateUrl()
                ;
            }
        }

        $responseParameters->set('media_edit_urls', $mediaEditUrls);

        return $responseParameters;
    }

    // What a category's edit screen lists: the medias it holds, those of them in the trash, or the list it gathers when it is an automatic one
    // The grid is handed its list rather than reading category.medias itself, that collection holding the trash too - and the three selection actions read the very same list back, so what is acted on is always what was shown (see selectedMedias)
    /** @return list<GalleryMedia> */
    private function mediasShown(GalleryCategory $category): array
    {
        if ($category->isAutomatic()) {
            return $this->automaticProvider->getMedias($category);
        }

        if ($this->isMediasTrash()) {
            return array_values(array_filter(
                $category->getMedias()->toArray(),
                static fn (GalleryMedia $media): bool => $media->isDeleted()
            ));
        }

        return $this->galleryMediaRepository->findByCategory($category);
    }

    // Moves the medias checked under the category's edit form (see gallery_category_edit.html.twig) to the trash - the media CRUD only ever handles one at a time, which is a screen per media for a batch an admin wants off the grid in one go
    // Only medias of the category the url carries are ever touched, whatever ids are posted, and none of their files is touched at all - they wait in the trash view of this very screen, which restores them or drops them for good
    // Their likes stay where they are, this action taking no RatingRepository at all: the trash is reversible, and a photo coming back has to find them (see dropRatings, which only deletePermanently and deleteMediasPermanently reach)
    #[AdminRoute('/{entityId}/delete-medias', options: ['methods' => ['POST']])]
    public function deleteMedias(AdminContext $context, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted($this->roleNeeded());

        [$category, $url] = $this->mediaSelectionContext($context);

        if (!$this->isCsrfTokenValid(self::DELETE_MEDIAS_CSRF_TOKEN, $request->request->getString('_token'))) {
            return $this->redirect($url);
        }

        $medias = $this->selectedMedias($category, $request, deleted: false);

        foreach ($medias as $media) {
            // The category would keep showing a cover it no longer displays anywhere else - cleared here rather than left to getCoverOrRandomMedia() to step over, so restoring the media does not silently make it the cover again
            // The media's own category, not the screen's: from the automatic gallery a photo is trashed out of the gallery it actually belongs to, which is the one holding it as a cover
            $owner = $media->getCategory() ?? $category;
            if ($owner->getCoverMedia() === $media) {
                $owner->setCoverMedia(null);
            }

            // Same move to trash the media CRUD makes one at a time (see GalleryMediaCrudController::deleteEntity): the files stay, and so does the row, until the media is dropped from the trash screen
            $media->setIsDeleted(true);
        }
        $entityManager->flush();

        if (!$medias->isEmpty()) {
            $this->addFlash('success', $this->translator->trans('label.gallery_medias_trashed', ['%count%' => $medias->count()], 'gallery'));
        }

        return $this->redirect($url);
    }

    // Puts the checked medias back in the grid - the mirror of deleteMedias(), on the same selection form and the same route shape, only reachable from the trash view of the category's edit screen
    #[AdminRoute('/{entityId}/restore-medias', options: ['methods' => ['POST']])]
    public function restoreMedias(AdminContext $context, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted($this->roleNeeded());

        [$category, $url] = $this->mediaSelectionContext($context, trash: true);

        if (!$this->isCsrfTokenValid(self::DELETE_MEDIAS_CSRF_TOKEN, $request->request->getString('_token'))) {
            return $this->redirect($url);
        }

        $medias = $this->selectedMedias($category, $request, deleted: true);

        // A restored media comes back at the end of the gallery rather than at the rank it held: an upload made while it sat in the trash counts nothing of the trash and may have taken that very rank (see GalleryMediaFactory::nextPosition), and two medias sharing one position leave their order to the database
        $next = 0;
        foreach ($category->getMedias() as $existing) {
            if (!$existing->isDeleted()) {
                $next = max($next, $existing->getPosition() + 1);
            }
        }

        foreach ($medias as $media) {
            $media->setIsDeleted(false);
            $media->setPosition($next++);

            // The media page answered 410 while the media sat in the trash and answers the media again from here, so a "gone" row an earlier permanent deletion left under that url would shadow it - same release persistEntity() does for a category
            if (\is_string($category->getSlug()) && \is_string($media->getSlug())) {
                $this->urlRedirector->release($entityManager, $this->generateUrl('gallery_media', [
                    'category' => $category->getSlug(),
                    'slug' => $media->getSlug(),
                ]));
            }
        }
        $entityManager->flush();

        if (!$medias->isEmpty()) {
            $this->addFlash('success', $this->translator->trans('label.gallery_medias_restored', ['%count%' => $medias->count()], 'gallery'));
        }

        return $this->redirect($url);
    }

    // Drops the checked medias for good, files included - the only path in this bundle that removes a media, GalleryMediaDerivativeCleanupListener running off the remove() below. Held at the admin role, the rest of the gallery sitting at the editor's
    #[AdminRoute('/{entityId}/delete-medias-permanently', options: ['methods' => ['POST']])]
    public function deleteMediasPermanently(AdminContext $context, Request $request, EntityManagerInterface $entityManager, RatingRepository $ratingRepository): Response
    {
        $this->denyAccessUnlessGranted((string) $this->configService->get('site-role-admin'));

        [$category, $url] = $this->mediaSelectionContext($context, trash: true);

        if (!$this->isCsrfTokenValid(self::DELETE_MEDIAS_CSRF_TOKEN, $request->request->getString('_token'))) {
            return $this->redirect($url);
        }

        $medias = $this->selectedMedias($category, $request, deleted: true);

        foreach ($medias as $media) {
            // The media page is declared in the sitemap (see GallerySitemapProvider), so its url is left answering 410 Gone rather than the 404 a crawler retries for months - recorded here and not at the move to trash, a media that can still come back having no url to declare gone
            if (\is_string($category->getSlug()) && \is_string($media->getSlug())) {
                $this->urlRedirector->recordGone($entityManager, $this->generateUrl('gallery_media', [
                    'category' => $category->getSlug(),
                    'slug' => $media->getSlug(),
                ]));
            }

            $entityManager->remove($media);
        }

        // Read before the flush, which nulls the identifier of everything it deletes, and only here: a media sitting in the trash can still come back, and it has to find its likes where it left them
        $mediaIds = $this->mediaIds($medias);

        $entityManager->flush();

        // Only once the medias have actually gone: a flush that fails leaves them in place, likes and all
        $this->dropRatings($ratingRepository, $mediaIds);

        if (!$medias->isEmpty()) {
            $this->addFlash('success', $this->translator->trans('label.gallery_medias_deleted_permanently', ['%count%' => $medias->count()], 'gallery'));
        }

        return $this->redirect($url);
    }

    /**
     * The ids of the medias about to be removed for good - read while they still have one, the flush nulling the identifier of what it deletes.
     *
     * @param iterable<GalleryMedia> $medias
     *
     * @return int[]
     */
    private function mediaIds(iterable $medias): array
    {
        $ids = [];
        foreach ($medias as $media) {
            if (null !== $media->getId()) {
                $ids[] = $media->getId();
            }
        }

        return $ids;
    }

    /**
     * Drops the likes of medias removed for good - the one thing a deletion leaves behind, a rating naming its owner (see c975L\UiBundle\Entity\Rating) rather than relating to it, so no foreign key cascades it.
     * One query for the whole set, a gallery of two thousand photos being deleted in one go.
     *
     * @param int[] $mediaIds
     */
    private function dropRatings(RatingRepository $ratingRepository, array $mediaIds): void
    {
        $ratingRepository->deleteForOwners('gallery_media', $mediaIds);
    }

    // The category the posted selection belongs to, and the screen to return to - the three selection actions share it rather than each rebuilding the same two things
    /** @return array{GalleryCategory, string} */
    private function mediaSelectionContext(AdminContext $context, bool $trash = false): array
    {
        $category = $context->getEntity()->getInstance();
        if (!$category instanceof GalleryCategory) {
            throw $this->createNotFoundException();
        }

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($category->getId())
        ;

        return [$category, $trash ? $url->set('mediasTrash', 1)->generateUrl() : $url->generateUrl()];
    }

    // Applies one field to every media checked under the category's edit form (see gallery_category_edit.html.twig) - the same credits line, or the same "rights reserved" state, typed once instead of opened media by media
    // The field applied is the one the button pressed names, a submit button posting its own name/value alone: the controls of the other buttons travel with it and are simply left unread
    // Only medias of the category the url carries are ever touched, whatever ids are posted, exactly as deleteMedias() does
    #[AdminRoute('/{entityId}/edit-medias', options: ['methods' => ['POST']])]
    public function editMedias(AdminContext $context, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted($this->roleNeeded());

        $category = $context->getEntity()->getInstance();
        if (!$category instanceof GalleryCategory) {
            throw $this->createNotFoundException();
        }

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($category->getId())
            ->generateUrl()
        ;

        $field = $request->request->getString('field');
        if (!\in_array($field, self::EDITABLE_FIELDS, true) || !$this->isCsrfTokenValid(self::EDIT_MEDIAS_CSRF_TOKEN, $request->request->getString('_edit_token'))) {
            return $this->redirect($url);
        }

        // An empty credits box clears the credits rather than being ignored, as an unchecked box unsets the rights - the field is applied as the toolbar shows it, which is also the only way to blank it on a whole selection
        $credits = $request->request->getString('credits') ?: null;

        $medias = $this->selectedMedias($category, $request);
        foreach ($medias as $media) {
            match ($field) {
                'credits' => $media->setCredits($credits),
                'rightsReserved' => $media->setRightsReserved($request->request->getBoolean('rightsReserved')),
                'hidden' => $media->setHidden($request->request->getBoolean('hidden')),
                // Marks a whole selection as sellable, which is how a hundred photographs are put on sale without opening each one - the sizes each is actually offered are worked out from its own file (see GalleryPrintService::getOffers)
                default => $media->setPrintable($request->request->getBoolean('printable')),
            };
        }
        $entityManager->flush();

        if (!$medias->isEmpty()) {
            $this->addFlash('success', $this->translator->trans('label.gallery_medias_updated', ['%count%' => $medias->count()], 'gallery'));
        }

        return $this->redirect($url);
    }

    // Moves the checked medias into another gallery, everything they carry following them (see GalleryMediaMover): the files into the directory that gallery is stored under, the old media pages into redirects, and the ranks of both galleries closing behind and opening in front
    // The title root is optional and works exactly as the upload screen's: left empty the medias keep the titles they had, filled they are renumbered from where the arrival gallery leaves off - "Voitures 12" becoming "Volvo 3" without a single url moving, a slug never following a title in this bundle
    // Only medias of the category the url carries are ever touched, whatever ids are posted, exactly as deleteMedias() does - the gallery each of them actually belongs to being the one renumbered, so the automatic gallery files a photo into the right place from the list of the last additions
    #[AdminRoute('/{entityId}/move-medias', options: ['methods' => ['POST']])]
    public function moveMedias(AdminContext $context, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted($this->roleNeeded());

        [$category, $url] = $this->mediaSelectionContext($context);

        if (!$this->isCsrfTokenValid(self::DELETE_MEDIAS_CSRF_TOKEN, $request->request->getString('_token'))) {
            return $this->redirect($url);
        }

        // Read before the arrival gallery is settled: creating one on the spot for a selection that turns out to be empty would leave an empty gallery behind
        $medias = $this->selectedMedias($category, $request, deleted: false);
        if ($medias->isEmpty()) {
            return $this->redirect($url);
        }

        // Checked again here rather than trusted from the select: the posted id is a request like any other, and a gallery holding no media of its own would take photographs out of every grid at once
        $target = $this->moveTarget($entityManager, $request);
        if (null === $target) {
            return $this->redirect($url);
        }

        $moved = $this->galleryMediaMover->move($entityManager, $medias, $target, $request->request->getString('titleRoot'));
        $entityManager->flush();

        if ($moved > 0) {
            $this->addFlash('success', $this->translator->trans('label.gallery_medias_moved', [
                '%count%' => $moved,
                '%category%' => (string) $target->getTitle(),
            ], 'gallery'));
        }

        return $this->redirect($url);
    }

    // The gallery a selection may be moved into: an existing one, or one created on the spot under the title the toolbar's box carries, the select's "new gallery" entry being what asks for it
    // Nothing is preselected in that select, so an empty value is the admin not having chosen yet rather than a malformed request, and both meet the same refusal - the flash is raised here and by createMoveTarget(), so the caller has only the redirect left to do
    private function moveTarget(EntityManagerInterface $entityManager, Request $request): ?GalleryCategory
    {
        $posted = $request->request->getString('targetCategory');
        if (self::MOVE_TARGET_NEW === $posted) {
            return $this->createMoveTarget($entityManager, $request->request->getString('newCategoryTitle'));
        }

        $target = $this->existingMoveTarget((int) $posted);
        if (null === $target) {
            $this->addFlash('danger', $this->translator->trans('label.gallery_medias_move_refused', [], 'gallery'));
        }

        return $target;
    }

    // One that actually holds medias of its own, and not one in the trash - the very filter the media's own edit form offers on its category field (see GalleryMediaCrudController::configureFields), a media moved to either would disappear from every grid
    private function existingMoveTarget(int $id): ?GalleryCategory
    {
        $target = $id > 0 ? $this->galleryCategoryRepository->find($id) : null;

        return $target instanceof GalleryCategory && !$target->isAutomatic() && !$target->isDeleted() ? $target : null;
    }

    // The gallery created on the spot, so filing photographs somewhere new doesn't mean leaving the screen, creating a category and coming back to a selection that is gone
    // The slug is built from the title exactly as the category form builds it (see addSlugNormalizer), and a collision is refused rather than suffixed: a category's slug is its natural key (see GalleryCategory), and the admin is told the name is taken
    // Persisted here and left to the caller's own flush, which is the one that writes the moved medias too - a creation going through while the move fails would leave an empty gallery behind
    private function createMoveTarget(EntityManagerInterface $entityManager, string $title): ?GalleryCategory
    {
        $title = trim($title);
        $slug = '' === $title ? '' : strtolower($this->slugger->slug($title)->toString());
        if ('' === $slug) {
            $this->addFlash('danger', $this->translator->trans('label.gallery_medias_move_refused', [], 'gallery'));

            return null;
        }

        if ($this->galleryCategoryRepository->findOneBySlug($slug) instanceof GalleryCategory) {
            $this->addFlash('danger', $this->translator->trans('label.gallery_medias_move_slug_taken', ['%slug%' => $slug], 'gallery'));

            return null;
        }

        $target = new GalleryCategory()
            ->setSlug($slug)
            ->setTitle($title)
        ;

        $this->releaseCategoryUrl($entityManager, $target);
        $entityManager->persist($target);

        return $target;
    }

    // Hands the files of the checked medias back as one zip - their high resolution version, or the untouched originals kept at upload, the button pressed naming which (see GalleryMediaArchiver)
    // The one way to get those files back without an ssh session: the highres is public but linked nowhere as a file, and the original sits outside public/ altogether. Both are read-only here, which is why this sits at the editor's role like the rest of the screen rather than at the admin's
    // Only medias of the category the url carries are ever read, whatever ids are posted, exactly as deleteMedias() does - offered from the grid and from the trash alike, which is why it is the one selection action not filtering on the trash state
    #[AdminRoute('/{entityId}/download-medias', options: ['methods' => ['POST']])]
    public function downloadMedias(AdminContext $context, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->roleNeeded());

        [$category, $url] = $this->mediaSelectionContext($context, trash: $this->isMediasTrash());

        $variant = $request->request->getString('variant');
        if (!\in_array($variant, GalleryMediaArchiver::VARIANTS, true) || !$this->isCsrfTokenValid(self::DELETE_MEDIAS_CSRF_TOKEN, $request->request->getString('_token'))) {
            return $this->redirect($url);
        }

        // The trash state is left out of the filter here, unlike everywhere else on this screen: it is what keeps a selection posted from the grid away from the permanent deletion, where reading a file is the same act whether the photo is online or waiting to be dropped - and getting the originals back is most wanted precisely before dropping them
        $medias = $this->selectedMedias($category, $request);
        if ($medias->isEmpty()) {
            return $this->redirect($url);
        }

        // Weighed before a single byte is written: a whole gallery of originals is tens of gigabytes, which no browser download and no temporary directory should be asked to carry - refused with its own size stated, never handed over truncated
        $weight = $this->galleryMediaArchiver->weigh($medias, $variant);
        if ($weight > GalleryMediaArchiver::MAX_TOTAL_BYTES) {
            $this->addFlash('danger', $this->translator->trans('label.gallery_medias_download_too_large', [
                '%size%' => $this->megabytes($weight),
                '%limit%' => $this->megabytes(GalleryMediaArchiver::MAX_TOTAL_BYTES),
            ], 'gallery'));

            return $this->redirect($url);
        }

        // The archive is refused rather than truncated, so a temporary directory that would not take it, or a disk that filled up mid write, says so on its own flash - an admin reading "nothing to download" would go looking at the wrong place
        try {
            $archive = $this->galleryMediaArchiver->archive($medias, $variant, (string) $category->getSlug());
        } catch (\RuntimeException) {
            $this->addFlash('danger', $this->translator->trans('label.gallery_medias_download_failed', [], 'gallery'));

            return $this->redirect($url);
        }

        // Nothing on disk for the whole selection - originals asked of a batch uploaded without keeping any, most of the time, which is a message to read rather than an empty zip to open
        if (null === $archive) {
            $this->addFlash('warning', $this->translator->trans('label.gallery_medias_download_empty', [], 'gallery'));

            return $this->redirect($url);
        }

        return $archive;
    }

    // The size a flash states, in whole megabytes - the byte count is what the archiver measures and nobody reads
    private function megabytes(int $bytes): int
    {
        return (int) round($bytes / 1048576);
    }

    // The checked medias, kept to those the category actually holds - a posted id belonging to another category is simply dropped
    // The trash state is part of the filter, the normal grid and the trash sharing one token: without it a selection posted from the normal screen would reach the permanent deletion, which the two-step trash exists to prevent
    private function selectedMedias(GalleryCategory $category, Request $request, ?bool $deleted = null): Collection
    {
        $ids = array_map(static fn (mixed $id): int => \is_scalar($id) ? (int) $id : 0, $request->request->all('mediaIds'));

        // An automatic gallery holds none of the medias it shows, so the selection is kept to the very list its screen listed - a media that has since left that list is simply dropped, exactly as an id belonging to another category is below
        $medias = $category->isAutomatic()
            ? new ArrayCollection($this->automaticProvider->getMedias($category))
            : $category->getMedias();

        return $medias->filter(static fn (GalleryMedia $media): bool => \in_array($media->getId(), $ids, true)
            && (null === $deleted || $media->isDeleted() === $deleted));
    }

    // Saves what the medias' grid lets an admin arrange, as it is arranged: their order, set by dragging the thumbnails around, and which of them the category is represented by - no cover picked means a random one, as the public components have always fallen back to (see components/Gallery/Category.html.twig)
    // Called by gallery-media-sort.js rather than by a button of its own: the grid is not part of the edit form (an html form never nests in another), and nothing on the screen could have told an admin that the Save button above ignores it
    // Only medias of the category the url carries are ever touched, whatever ids are posted, and an id belonging to another category simply leaves the cover on random rather than pointing the category at a media it doesn't hold
    #[AdminRoute('/{entityId}/medias-layout', options: ['methods' => ['POST']])]
    public function saveMediasLayout(AdminContext $context, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted($this->roleNeeded());

        $category = $context->getEntity()->getInstance();
        if (!$category instanceof GalleryCategory) {
            throw $this->createNotFoundException();
        }

        // The token travels as a header, the body carrying the layout alone - same shape as UiBundle's BlockMoveController, this being the same kind of call
        if (!$this->isCsrfTokenValid(self::MEDIAS_LAYOUT_CSRF_TOKEN, $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => 'invalid_csrf'], 419);
        }

        // The trash is left out of both the order and the cover: a trashed media is neither arranged nor displayed, and renumbering it would push its position over those of the medias still online
        $medias = [];
        foreach ($category->getMedias() as $media) {
            if (!$media->isDeleted()) {
                $medias[$media->getId()] = $media;
            }
        }

        // Renumbered from 0 following the order posted, rather than the values each media's own edit screen holds - the grid is what the admin arranged, and gaps left by a deleted media would otherwise never close
        $position = 0;
        foreach ($request->request->all('mediaOrder') as $id) {
            $media = $medias[\is_scalar($id) ? (int) $id : 0] ?? null;
            if (null !== $media) {
                $media->setPosition($position++);
            }
        }

        // Read as a string, the "random" radio posting an empty value that getInt() refuses outright
        $category->setCoverMedia($medias[(int) $request->request->getString('coverMediaId')] ?? null);
        $entityManager->flush();

        return new JsonResponse(['saved' => true]);
    }

    /**
     * Draws the qr code of the gallery's public page, shown on its edit screen (see gallery_category_edit.html.twig).
     *
     * Same shape as the one SiteBundle draws for a page: generated on the fly rather than stored, a code being nothing
     * but its url and a stored one being a file to regenerate the day the slug changes.
     *
     * Built on 'site-url' and not on the current request, which is the back-office's - what is printed on a flyer has to
     * be the address the public reaches, not the one an admin happens to be logged into.
     */
    #[AdminRoute('/{entityId}/qrcode')]
    public function qrcode(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted($this->roleNeeded());

        $category = $context->getEntity()->getInstance();
        $url = rtrim((string) $this->configService->get('site-url'), '/')
            . $this->generateUrl('gallery_category', ['category' => $category->getSlug()]);

        $result = new Builder()->build(data: $url, size: 250, margin: 10);

        return new Response($result->getString(), Response::HTTP_OK, ['Content-Type' => $result->getMimeType()]);
    }

    // Exports the checked categories (with their gallery and medias, real files bundled in the archive) as a downloadable zip, meant to be re-uploaded elsewhere via ConfigBundle's ContentImportController (see GalleryImportProvider) - restricted to ROLE_ADMIN, see configureActions()
    #[AdminRoute]
    public function exportSelection(AdminContext $context, BatchActionDto $batchActionDto): Response
    {
        $this->denyAccessUnlessGranted($this->roleNeeded());

        if (GalleryCategory::class !== $batchActionDto->getEntityFqcn()) {
            throw new BadRequestHttpException();
        }

        if (!$this->isCsrfTokenValid('ea-batch-action-exportSelection-' . $batchActionDto->getEntityFqcn(), $batchActionDto->getCsrfToken())) {
            return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
        }

        $categories = $this->galleryCategoryRepository->findBy(['id' => $batchActionDto->getEntityIds()]);
        $data = $this->galleryExportProvider->serialize($categories);

        return $this->contentExporter->export(GalleryImportProvider::KIND, $data['items'], $data['files']);
    }
}
