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
use c975L\GalleryBundle\Management\GalleryBlockOwnerResolver;
use c975L\GalleryBundle\Management\GalleryExportProvider;
use c975L\GalleryBundle\Management\GalleryImportProvider;
use c975L\GalleryBundle\Model\GalleryMediaBatch;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Service\GalleryMediaFactory;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use c975L\GalleryBundle\Service\UploadLimits;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Form\BlockType;
use c975L\UiBundle\Form\TrixEditorType;
use c975L\UiBundle\Form\Util\CollectionReconciler;
use c975L\UiBundle\Service\BlockMoveRowAttrBuilder;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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

    // The fields editMedias() applies to a whole selection at once, each named by the button posting it
    public const EDITABLE_FIELDS = ['credits', 'rightsReserved'];

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
        private readonly UploadLimits $uploadLimits,
        private readonly GalleryUrlRedirector $urlRedirector,
        private readonly ConfigServiceInterface $configService,
    ) {
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

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(t('label.gallery_category', [], 'gallery'))
            ->setEntityLabelInPlural(t('label.gallery_categories', [], 'gallery'))
            ->setEntityPermission($this->roleNeeded())
            ->setDefaultSort(['position' => 'ASC'])
            // The row actions are icon-only (see configureActions), so they cost less width side by side than the dropdown they'd otherwise be folded into
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/index', '@c975LGallery/management/gallery_category_index.html.twig')
            ->overrideTemplate('crud/edit', '@c975LGallery/management/gallery_category_edit.html.twig')
            ->overrideTemplate('crud/new', '@c975LGallery/management/gallery_category_new.html.twig')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        // Same "export selection" as SiteBundle's PageCrudController::exportSelection() - see that method's own comment for why
        $actions->add(Crud::PAGE_INDEX, Action::new('exportSelection', t('action.export_selection', [], 'gallery'), 'fa fa-file-export')
            ->createAsBatchAction()
            ->linkToCrudAction('exportSelection'));
        $actions->setPermission('exportSelection', $this->roleNeeded());

        // Medias are only ever added from the category they belong to (NEW is disabled on GalleryMediaCrudController, which has no upload button of its own): the category is what this link carries, and the upload screen shows it without letting it be changed
        // Icon-only among the index's row buttons. The edit screen has no action of its own: up in the toolbar the button sits above the blocks collection, whose own "add" button is then the one clicked to add a media - it is rendered down with the medias instead (see configureResponseParameters and gallery_category_edit.html.twig)
        $uploadMediasAction = Action::new('uploadMedias', t('label.gallery_upload_medias', [], 'gallery'), 'fas fa-upload')
            ->linkToUrl(fn (GalleryCategory $category): string => $this->uploadMediasUrl($category));
        $actions->add(Crud::PAGE_INDEX, EasyAdminActionHelper::toIconOnly(
            $uploadMediasAction,
            $this->translator->trans('label.gallery_upload_medias', [], 'gallery'),
        ));
        $actions->setPermission('uploadMedias', $this->roleNeeded());

        // Lets the admin back out of a create/edit without saving - mirrors EasyAdmin's own built-in actions (linkToCrudAction targeting INDEX, same as Action::INDEX itself)
        $cancelAction = Action::new('cancel', $this->translator->trans('action.cancel', [], 'EasyAdminBundle'), 'fa fa-times')
            ->linkToCrudAction(Action::INDEX)
            ->addCssClass('btn btn-secondary');

        return $actions
            ->add(Crud::PAGE_NEW, $cancelAction)
            ->add(Crud::PAGE_EDIT, $cancelAction)
            // A gallery is dropped from its own screen as a media is from its (see GalleryMediaCrudController), rather than only from the row button one screen above - deleting it takes its medias and its heading blocks along, the association cascading
            ->add(Crud::PAGE_EDIT, Action::DELETE)
            ->update(Crud::PAGE_EDIT, Action::DELETE, static fn (Action $action) => $action->displayIf(
                static fn (GalleryCategory $category): bool => !$category->isUncategorized()
            ))
            ->setPermission(Action::INDEX, $this->roleNeeded())
            ->setPermission(Action::NEW, $this->roleNeeded())
            ->setPermission(Action::EDIT, $this->roleNeeded())
            ->setPermission(Action::DELETE, $this->roleNeeded())
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.edit', [], 'EasyAdminBundle'),
            ))
            // The catch-all "Non classé" category must always exist as a fallback for medias uploaded without a real one picked (see GalleryCategoryRepository::findOrCreateUncategorized)
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action->displayIf(static fn (GalleryCategory $category): bool => !$category->isUncategorized()),
                $this->translator->trans('action.delete', [], 'EasyAdminBundle'),
            ))
            // Detail adds no information beyond what edit already shows
            ->disable(Action::DETAIL)
        ;
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
    public function new(AdminContext $context): KeyValueStore | Response
    {
        $request = $context->getRequest();

        if ($this->uploadLimits->isTruncatedRequest($request)) {
            $this->addFlash('danger', $this->translator->trans('label.gallery_upload_batch_refused', [], 'gallery'));

            return $this->redirect($request->getUri());
        }

        return parent::new($context);
    }

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
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof GalleryCategory && \is_string($entityInstance->getSlug())) {
            $url = $this->generateUrl('gallery_category', ['category' => $entityInstance->getSlug()]);

            $this->urlRedirector->release($entityManager, $url);
            $this->urlRedirector->release($entityManager, $url . '/*');

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

    // Updated category - a rename moves its public url (see addSlugNormalizer), so the old one is redirected to the new one rather than left to 404 on every link and search result already pointing at it, exactly as SiteBundle's PageCrudController does for a page
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

    // Deleted category - its page and every media page under it are declared in the sitemap (see GallerySitemapProvider), so the urls are left answering 410 Gone rather than the 404 a crawler retries for months
    // The medias go with it (GalleryCategory::$medias cascades the removal), and a single wildcard row covers all of them - the alternative being one row per media, which is what would make the redirect table grow with every deleted gallery
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof GalleryCategory && \is_string($entityInstance->getSlug())) {
            $this->urlRedirector->recordGoneTree($entityManager, $this->generateUrl('gallery_category', ['category' => $entityInstance->getSlug()]));
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

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
            IntegerField::new('mediasCount')
                ->setLabel(t('label.gallery_medias', [], 'gallery'))
                ->setSortable(false)
                ->onlyOnIndex(),

            SlugField::new('slug')
                ->setLabel(t('label.slug', [], 'gallery'))
                ->setTargetFieldName('title')
                ->setRequired(true)
                ->setHelp(t('label.slug_help', [], 'gallery')),

            // TrixEditorType rather than EasyAdmin's own TextEditorField: it is the editor every other rich-text field of the ecosystem uses (see UiBundle's block form types), and its widget is where the rephrase button is wired - EasyAdmin's own renders through a different form block, which would leave this field the only one without it
            TextareaField::new('description')
                ->setLabel(t('label.gallery_description', [], 'gallery'))
                ->setHelp(t('label.gallery_description_help', [], 'gallery'))
                ->setFormType(TrixEditorType::class)
                ->hideOnIndex(),

            IntegerField::new('position')
                ->setLabel(t('label.position', [], 'gallery'))
                ->hideOnIndex(),

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
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        // The ceilings the creation form's files are weighed against before they are sent, the template having no other way to reach them (see gallery_category_new.html.twig)
        if (Crud::PAGE_NEW === $responseParameters->get('pageName')) {
            $responseParameters->set('upload_limits', $this->uploadLimits);
        }

        if (Crud::PAGE_EDIT !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $category = $responseParameters->get('entity')?->getInstance();
        $mediaEditUrls = [];

        if ($category instanceof GalleryCategory) {
            // The "Add medias" button sits with the medias rather than up in the toolbar, where it was above the blocks collection and its own "add" button (see gallery_category_edit.html.twig)
            $responseParameters->set('media_upload_url', $this->uploadMediasUrl($category));

            foreach ($category->getMedias() as $media) {
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

    // Deletes the medias checked under the category's edit form (see gallery_category_edit.html.twig) - the media CRUD only ever deletes one at a time, which is a screen per media for a batch an admin wants gone in one go
    // Only medias of the category the url carries are ever touched, whatever ids are posted, and their files go with them (see GalleryMediaDerivativeCleanupListener)
    #[AdminRoute('/{entityId}/delete-medias', options: ['methods' => ['POST']])]
    public function deleteMedias(AdminContext $context, Request $request, EntityManagerInterface $entityManager): Response
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

        if (!$this->isCsrfTokenValid(self::DELETE_MEDIAS_CSRF_TOKEN, $request->request->getString('_token'))) {
            return $this->redirect($url);
        }

        $medias = $this->selectedMedias($category, $request);

        foreach ($medias as $media) {
            // The category would keep pointing at a cover that is gone - the join column's "on delete set null" only reaches the row, not the instance Doctrine still holds
            if ($category->getCoverMedia() === $media) {
                $category->setCoverMedia(null);
            }

            // Same 410 the media CRUD leaves behind when it deletes one at a time (see GalleryMediaCrudController::deleteEntity), a media page being declared in the sitemap whichever screen removes it - one stored before slugs existed has no public url to answer for
            if (\is_string($category->getSlug()) && \is_string($media->getSlug())) {
                $this->urlRedirector->recordGone($entityManager, $this->generateUrl('gallery_media', [
                    'category' => $category->getSlug(),
                    'slug' => $media->getSlug(),
                ]));
            }

            $entityManager->remove($media);
        }
        $entityManager->flush();

        if (!$medias->isEmpty()) {
            $this->addFlash('success', $this->translator->trans('label.gallery_medias_deleted', ['%count%' => $medias->count()], 'gallery'));
        }

        return $this->redirect($url);
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
        $rightsReserved = $request->request->getBoolean('rightsReserved');

        $medias = $this->selectedMedias($category, $request);
        foreach ($medias as $media) {
            if ('credits' === $field) {
                $media->setCredits($credits);
            } else {
                $media->setRightsReserved($rightsReserved);
            }
        }
        $entityManager->flush();

        if (!$medias->isEmpty()) {
            $this->addFlash('success', $this->translator->trans('label.gallery_medias_updated', ['%count%' => $medias->count()], 'gallery'));
        }

        return $this->redirect($url);
    }

    // The checked medias, kept to those the category actually holds - a posted id belonging to another category is simply dropped
    private function selectedMedias(GalleryCategory $category, Request $request): Collection
    {
        $ids = array_map(static fn (mixed $id): int => \is_scalar($id) ? (int) $id : 0, $request->request->all('mediaIds'));

        return $category->getMedias()->filter(static fn (GalleryMedia $media): bool => \in_array($media->getId(), $ids, true));
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

        $medias = [];
        foreach ($category->getMedias() as $media) {
            $medias[$media->getId()] = $media;
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
