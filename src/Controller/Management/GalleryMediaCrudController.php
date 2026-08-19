<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use c975L\GalleryBundle\Service\UploadLimits;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Form\Type\VichFileType;
use Vich\UploaderBundle\Form\Type\VichImageType;

use function Symfony\Component\Translation\t;

// Edits one media at a time, and nothing else: it has no listing of its own and no sidebar entry, a media being reached from the category holding it (see GalleryCategoryCrudController, the single menu entry for the whole gallery feature)
class GalleryMediaCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly GalleryMediaSlugger $mediaSlugger,
        private readonly GalleryUrlRedirector $urlRedirector,
        private readonly ConfigServiceInterface $configService,
        private readonly UploadLimits $uploadLimits,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return GalleryMedia::class;
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
            ->setEntityLabelInSingular(t('label.gallery_media', [], 'gallery'))
            ->setEntityLabelInPlural(t('label.gallery_medias', [], 'gallery'))
            ->setEntityPermission($this->roleNeeded())
            ->overrideTemplate('crud/edit', '@c975LGallery/management/gallery_media_edit.html.twig')
        ;
    }

    // There is no all-medias listing: a category's medias are shown on that category's own edit screen (see GalleryCategoryCrudController), which is where every redirect EasyAdmin sends here lands instead - after a save, after a delete, and for anyone reaching the url by hand
    // The category is read from the query string, which the media screens carry along from the link that opened them (AdminUrlGenerator keeps the current parameters), so the admin returns to the category worked on rather than to the top of the list
    #[\Override]
    public function index(AdminContext $context): KeyValueStore | Response
    {
        $categoryId = $context->getRequest()->query->getInt('category');

        $url = $this->adminUrlGenerator
            ->setController(GalleryCategoryCrudController::class)
            ->unset('category')
        ;

        $url = $categoryId > 0
            ? $url->setAction(Action::EDIT)->setEntityId($categoryId)
            : $url->setAction(Action::INDEX);

        return $this->redirect($url->generateUrl());
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        // Lets the admin back out of an edit without saving - mirrors EasyAdmin's own built-in actions (linkToCrudAction targeting INDEX, same as Action::INDEX itself), which redirects to the category above
        $cancelAction = Action::new('cancel', $this->translator->trans('action.cancel', [], 'EasyAdminBundle'), 'fa fa-times')
            ->linkToCrudAction(Action::INDEX)
            ->addCssClass('btn btn-secondary');

        return $actions
            ->setPermission(Action::EDIT, $this->roleNeeded())
            ->setPermission(Action::DELETE, $this->roleNeeded())
            // Medias are only ever created in bulk, from a category's own "add medias" action (see GalleryCategoryCrudController) - never one at a time, and never from here, where no category is picked
            ->disable(Action::NEW)
            ->add(Crud::PAGE_EDIT, $cancelAction)
            // The edit form is the only screen a media has, so it carries its own delete button - there is no listing left to offer one
            ->add(Crud::PAGE_EDIT, Action::DELETE)
            // Detail adds no information beyond what edit already shows
            ->disable(Action::DETAIL)
        ;
    }

    #[\Override]
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->addWatermark(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    // The watermark answered on the form is carried to the media it applies to, the two fields being unmapped (see configureFields) - on submit, so it is in place before the flush that stores the uploaded file and has UiBundle's VichImageResizeListener stamp it
    // Answered for nothing when the form carries no new file: what would be stamped is not stored again, and the media goes on carrying the signature its file already holds
    private function addWatermark(FormBuilderInterface $formBuilder): FormBuilderInterface
    {
        $formBuilder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $media = $event->getData();
            if (!$media instanceof GalleryMedia) {
                return;
            }

            $form = $event->getForm();
            $media
                ->setWatermark((bool) $form->get('watermark')->getData())
                ->setWatermarkPosition($form->get('watermarkPosition')->getData())
            ;
        });

        return $formBuilder;
    }

    // Updated media - a media's public url moves when its slug is edited, and when it is moved to another category, the category's own slug being the segment above it
    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof GalleryMedia) {
            $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);

            // Normalized, never rebuilt from the title: a title is retouched precisely because the first one was a placeholder, and having the url follow it made every such correction cost a redirect. What an admin types into the slug field still has to be a slug and still has to be free within the category, which is what the slugger answers - and an emptied field is how one is asked to be rebuilt from the title
            $this->mediaSlugger->assign($entityInstance, $entityInstance->getSlug());

            $this->redirectUrlChange($entityManager, $original, $entityInstance);
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    // Both urls are generated rather than concatenated, the first segment being the configured route prefix (see GalleryRoutePrefix)
    private function redirectUrlChange(EntityManagerInterface $entityManager, array $original, GalleryMedia $media): void
    {
        $originalCategory = $original['category'] ?? null;
        $originalSlug = $original['slug'] ?? null;

        // A media stored before slugs existed has none to redirect from, and there is no old url to preserve either - it simply starts being reachable under its new one
        if (!$originalCategory instanceof GalleryCategory || !\is_string($originalSlug)) {
            return;
        }

        $this->urlRedirector->record(
            $entityManager,
            $this->generateUrl('gallery_media', ['category' => $originalCategory->getSlug(), 'slug' => $originalSlug]),
            $this->generateUrl('gallery_media', ['category' => $media->getCategory()?->getSlug(), 'slug' => $media->getSlug()]),
        );
    }

    // Move to trash: the media leaves the grid and its page answers 410 (see GalleryController::resolveCategoryAndMedia), but the row and its four files stay exactly where they are - what removes them is deletePermanently() on its category's trash screen, or the category's own permanent deletion
    // No "gone" Redirect is recorded here any more: the 410 lasts only as long as the media can still be restored, and a Redirect row would outlive the restore (see GalleryCategoryCrudController::deletePermanently, which records the tree for good)
    #[\Override]
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof GalleryMedia) {
            // The cover is released here as GalleryCategoryCrudController::deleteMedias() does it for a whole selection - the two ways to trash a media must leave the category in the same state, or restoring one silently makes it the cover again
            $category = $entityInstance->getCategory();
            if ($category?->getCoverMedia() === $entityInstance) {
                $category->setCoverMedia(null);
            }

            $entityInstance->setIsDeleted(true);
            $entityManager->flush();

            return;
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        return [
            // Only the galleries that actually show their medias are offered: an automatic gallery holds none of its own (it lists the last additions of the others, see GalleryLatestProvider), and a trashed one would show none - a media moved to either would disappear from every grid, front and back alike
            AssociationField::new('category')
                ->setLabel(t('label.gallery_category', [], 'gallery'))
                ->setQueryBuilder(static fn (QueryBuilder $queryBuilder): QueryBuilder => $queryBuilder
                    ->andWhere('entity.automatic = false')
                    ->andWhere('entity.isDeleted = false'))
                ->setRequired(true),

            Field::new('file')
                ->setLabel(t('label.file', [], 'gallery'))
                ->setFormType(VichImageType::class)
                ->setFormTypeOptions([
                    'required' => false,
                    'allow_delete' => false,
                    'download_uri' => true,
                    'asset_helper' => true,
                    'delete_label_translation_domain' => 'messages',
                    'constraints' => [
                        new FileConstraint(maxSize: '10M'),
                    ],
                ])
                ->onlyOnForms(),

            // Same pair as the batch screens', asked again here because a replacement is an upload of its own: the media keeps no flag from the batch that created it (see GalleryMedia::wantsWatermark), and the file already stored carries whatever signature it was given
            // Unmapped, and read back on submit (see createEditFormBuilder): they answer for the file being uploaded, not for the media
            Field::new('watermark')
                ->setLabel(t('label.gallery_watermark', [], 'gallery'))
                ->setHelp(t('label.gallery_media_watermark_help', [], 'gallery'))
                ->setFormType(CheckboxType::class)
                ->setFormTypeOptions([
                    'mapped' => false,
                    'required' => false,
                    'label_attr' => ['class' => 'checkbox-switch'],
                ])
                ->onlyOnForms(),

            Field::new('watermarkPosition')
                ->setLabel(t('label.gallery_watermark_position', [], 'gallery'))
                ->setHelp(t('label.gallery_batch_watermark_position_help', [], 'gallery'))
                ->setFormType(ChoiceType::class)
                // Choice labels are translation keys, not t() calls: they are array keys, and an array key can only be a string
                ->setFormTypeOptions([
                    'mapped' => false,
                    'required' => false,
                    // t() rather than the key alone: "choice_translation_domain" only covers the choices, the placeholder being translated in the form's own domain - EasyAdmin's here, where the key does not exist
                    'placeholder' => t('label.gallery_watermark_position_default', [], 'gallery'),
                    'choice_translation_domain' => 'gallery',
                    'choices' => [
                        'label.gallery_watermark_top_left' => VichWatermarkableInterface::POSITION_TOP_LEFT,
                        'label.gallery_watermark_top_right' => VichWatermarkableInterface::POSITION_TOP_RIGHT,
                        'label.gallery_watermark_bottom_right' => VichWatermarkableInterface::POSITION_BOTTOM_RIGHT,
                        'label.gallery_watermark_bottom_left' => VichWatermarkableInterface::POSITION_BOTTOM_LEFT,
                    ],
                ])
                ->onlyOnForms(),

            // The media's name and its alt text (see GalleryMedia::$title) - freely retouched, and no longer the source of the slug, so nothing it does moves a public url: a batch is uploaded under a title root and the medias worth describing are described afterwards, one by one, at no cost
            TextField::new('title')
                ->setLabel(t('label.title', [], 'gallery'))
                ->setRequired(true)
                ->setHelp(t('label.gallery_media_title_help', [], 'gallery')),

            // Editable, and the only field here that moves a public url - hence the padlock EasyAdmin's own SlugField draws, the same one a category and a page are edited behind (see GalleryCategoryCrudController and SiteBundle's PageCrudController): it is read-only until deliberately unlocked, and unlocking asks for the confirmation the change deserves
            // Never resynced from the title either, the field-slug script only following its target while the slug is still empty - which is exactly how an emptied field asks for one rebuilt from the title (see GalleryMediaSlugger)
            SlugField::new('slug')
                ->setLabel(t('label.slug', [], 'gallery'))
                ->setTargetFieldName('title')
                ->setHelp(t('label.gallery_media_slug_help', [], 'gallery'))
                ->setUnlockConfirmationMessage(t('confirm.media_slug_change', [], 'gallery')),

            TextField::new('credits')
                ->setLabel(t('label.credits', [], 'gallery')),

            // A video entry keeps its uploaded image above - it is what the grid shows, and what a self-hosted player uses as its poster; the two fields below only decide what the detail page opens on (see GalleryMedia::isVideo())
            // One field where there used to be a type and an id: an admin pastes the address bar of the page they were watching the video on, and the platform reads itself off it (see GalleryMedia::setExternalUrl). Nothing to extract by hand, and no pair of fields left to contradict each other
            // Http(s) only: an url is handed to an iframe's src on the front end, where a javascript: one would run in the site's own origin (see GalleryMedia::setExternalUrl, which drops the same schemes on the import's way in)
            UrlField::new('externalUrl')
                ->setLabel(t('label.gallery_external_url', [], 'gallery'))
                ->setHelp(t('label.gallery_external_url_help', [], 'gallery'))
                ->allowedProtocols(['http', 'https'])
                ->setRequired(false),

            // The site's own copy, which wins over the url above when both are there (see GalleryMedia::refreshMediaType) - no third party, nothing to consent to, and a video that outlives whatever a platform decides
            // The ceiling is php's own, not this bundle's 20 MiB one: that ceiling exists to keep a batch of photographs from taking a shared host down, and would refuse any video worth uploading (see UploadLimits::getMaxVideoFileSize)
            Field::new('videoFile')
                ->setLabel(t('label.gallery_video_file', [], 'gallery'))
                ->setHelp(t('label.gallery_video_file_help', ['%size%' => $this->uploadLimits->toMegabytes($this->uploadLimits->getMaxVideoFileSize())], 'gallery'))
                ->setFormType(VichFileType::class)
                ->setFormTypeOptions([
                    'required' => false,
                    'allow_delete' => true,
                    'download_uri' => true,
                    'asset_helper' => true,
                    'delete_label_translation_domain' => 'messages',
                    'constraints' => [
                        new FileConstraint(
                            maxSize: $this->uploadLimits->getMaxVideoFileSize(),
                            mimeTypes: GalleryMedia::VIDEO_MIME_TYPES,
                        ),
                    ],
                    'attr' => ['accept' => implode(',', GalleryMedia::VIDEO_MIME_TYPES)],
                ])
                ->onlyOnForms(),

            // What the url turned out to be, shown rather than asked - an admin who pasted the wrong thing sees "embed" where they expected a platform's name, which is the whole feedback this field owes them
            // Disabled rather than hidden on the form: this screen is the only one an admin ever sees of a media (index redirects and detail is disabled), so hiding it here would hide it everywhere; the property has no setter, and a disabled field is never written back
            ChoiceField::new('mediaType')
                ->setLabel(t('label.gallery_media_type', [], 'gallery'))
                ->setTranslatableChoices(array_combine(
                    GalleryMedia::mediaTypes(),
                    array_map(static fn (string $type) => t('label.gallery_media_type_' . $type, [], 'gallery'), GalleryMedia::mediaTypes()),
                ))
                ->setFormTypeOption('disabled', true),

            BooleanField::new('rightsReserved')
                ->setLabel(t('label.rights_reserved', [], 'gallery')),

            IntegerField::new('position')
                ->setLabel(t('label.position', [], 'gallery')),
        ];
    }
}
