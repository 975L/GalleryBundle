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
use c975L\GalleryBundle\Field\GalleryDataField;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryPrintCopyRepository;
use c975L\GalleryBundle\Service\GalleryCustomizationRegistry;
use c975L\GalleryBundle\Service\GalleryMediaLikeCounter;
use c975L\GalleryBundle\Service\GalleryMediaMover;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use c975L\GalleryBundle\Service\UploadLimits;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
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

// Edits one media at a time, and lists the whole library on its index - the contact sheet of every gallery at once, which is what a triage pass reads where a category's own grid only answers "what is in this gallery" (see GalleryCategoryCrudController, still listing a category's medias on its edit screen), the two being the same grid drawn from the same tile (see _gallery_media_tile.html.twig)
class GalleryMediaCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly GalleryMediaSlugger $mediaSlugger,
        private readonly GalleryMediaMover $mediaMover,
        private readonly GalleryUrlRedirector $urlRedirector,
        private readonly ConfigServiceInterface $configService,
        private readonly UploadLimits $uploadLimits,
        private readonly GalleryCustomizationRegistry $customizationRegistry,
        private readonly GalleryPrintCopyRepository $printCopyRepository,
        private readonly GalleryMediaLikeCounter $likeCounter,
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
            // Newest first, on the one column carrying an index of its own (see GalleryMedia) - a library is read from what has just come in, where a category's own grid is read in the order an admin arranged it
            ->setDefaultSort(['createdAt' => 'DESC'])
            // Named rather than left to EasyAdmin, which searches every text column there is - a filename and a slug would answer for words no admin typed looking for them
            ->setSearchFields(['title', 'description', 'credits'])
            // The same grid of thumbnails a category's edit screen draws, rather than a table of rows: a contact sheet is read as images (see gallery_media_index.html.twig, and UiBundle's media library, which overrides its own index for the same reason)
            ->overrideTemplate('crud/index', '@c975LGallery/management/gallery_media_index.html.twig')
            ->overrideTemplate('crud/edit', '@c975LGallery/management/gallery_media_edit.html.twig')
        ;
    }

    // What the contact sheet leaves out: the trash on both sides - a media in it, and every media of a gallery in it, trashing a category flagging the category alone; hidden medias stay in, as they do in a category's own grid, an admin having to see what he has masked
    #[\Override]
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.isDeleted = false')
            ->innerJoin('entity.category', 'sheetCategory')
            ->andWhere('sheetCategory.isDeleted = false')
        ;
    }

    // The three questions a triage pass asks of a library - which gallery, on sale or not, masked or not - plus the rights, applied to a selection often enough to be looked for afterwards, the automatic galleries being left out of the category filter as they are out of the media's own category field (see GalleryAutomaticProvider)
    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('category', t('label.gallery_category', [], 'gallery'))
                ->setFormTypeOption('value_type_options.query_builder', static fn (GalleryCategoryRepository $repository): QueryBuilder => $repository
                    ->createQueryBuilder('c')
                    ->andWhere('c.automaticKind IS NULL')
                    ->andWhere('c.isDeleted = false')
                    // Alphabetically, as every other list of the galleries is (see GalleryCategoryRepository::findAllOrdered) - a category carries no rank of its own, only its medias do
                    ->orderBy('c.title', 'ASC')))
            ->add(BooleanFilter::new('printable', t('label.gallery_media_printable', [], 'gallery')))
            ->add(BooleanFilter::new('hidden', t('label.gallery_media_hidden', [], 'gallery')))
            ->add(BooleanFilter::new('rightsReserved', t('label.rights_reserved', [], 'gallery')))
        ;
    }

    // The likes of the medias the page shows, counted once for the whole page and by the very service a category's own grid asks (see GalleryMediaLikeCounter)
    #[\Override]
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('media_likes', $this->likeCounter->count($this->shownMedias($responseParameters->get('entities'))));

        return $responseParameters;
    }

    // The medias the page actually draws, read off the dtos EasyAdmin hands the template
    /** @return list<GalleryMedia> */
    private function shownMedias(mixed $entities): array
    {
        if (!is_iterable($entities)) {
            return [];
        }

        $medias = [];
        foreach ($entities as $entityDto) {
            $instance = $entityDto instanceof EntityDto ? $entityDto->getInstance() : null;
            if ($instance instanceof GalleryMedia) {
                $medias[] = $instance;
            }
        }

        return $medias;
    }

    // Answers two different screens, and the "category" parameter is what tells them apart - carried along by the media screens from the link that opened them (AdminUrlGenerator keeps the current parameters), it sends a save, a delete or a cancel started from a gallery back to that gallery's edit screen, where without it the request came from the sidebar entry and the contact sheet of the whole library answers instead, filtered and searched (see configureFilters)
    #[\Override]
    public function index(AdminContext $context): KeyValueStore | Response
    {
        $categoryId = $context->getRequest()->query->getInt('category');
        if ($categoryId < 1) {
            return parent::index($context);
        }

        return $this->redirect($this->adminUrlGenerator
            ->setController(GalleryCategoryCrudController::class)
            ->unset('category')
            ->setAction(Action::EDIT)
            ->setEntityId($categoryId)
            ->generateUrl());
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        // Lets the admin back out of an edit without saving - mirrors EasyAdmin's own built-in actions (linkToCrudAction targeting INDEX, same as Action::INDEX itself), which redirects to the category the media was reached from, or to the library's contact sheet when none is carried
        $cancelAction = Action::new('cancel', $this->translator->trans('action.cancel', [], 'EasyAdminBundle'), 'fa fa-times')
            ->linkToCrudAction(Action::INDEX)
            ->addCssClass('btn btn-secondary');

        return $actions
            // The contact sheet is a screen of its own since the index stopped redirecting, so it states the same bar its sidebar entry announces (see MenuProvider)
            ->setPermission(Action::INDEX, $this->roleNeeded())
            ->setPermission(Action::EDIT, $this->roleNeeded())
            ->setPermission(Action::DELETE, $this->roleNeeded())
            // Medias are only ever created in bulk, from a category's own "add medias" action (see GalleryCategoryCrudController) - never one at a time, and never from here, where no category is picked
            ->disable(Action::NEW)
            ->add(Crud::PAGE_EDIT, $cancelAction)
            // The edit form carries its own delete button: the contact sheet its index draws offers no row action at all, a thumbnail being the link to the form and nothing else (see gallery_media_index.html.twig)
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

    // Updated media - a media's public url moves when its slug is edited, and when it is moved to another gallery, the gallery's own slug being the segment above it, its files following it there
    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof GalleryMedia) {
            $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);

            // Normalized, never rebuilt from the title: a title is retouched precisely because the first one was a placeholder, and having the url follow it made every such correction cost a redirect. What an admin types into the slug field still has to be a slug and still has to be free within the category, which is what the slugger answers - and an emptied field is how one is asked to be rebuilt from the title
            $this->mediaSlugger->assign($entityInstance, $entityInstance->getSlug());

            $this->redirectUrlChange($entityManager, $original, $entityInstance);

            $this->settleEdition($entityManager, $original, $entityInstance);

            // The category field of this very form is the second way a media changes gallery, the selection of the category screen being the first (see GalleryCategoryCrudController::moveMedias) - the slug and the redirect are settled just above, so what is left to follow is the files and the ranks of the two galleries
            // The rank an admin typed on this form is honoured rather than overwritten: they arranged the media themselves, where an untouched one simply lands after what the arrival gallery already holds
            $source = $original['category'] ?? null;
            $this->mediaMover->follow(
                $entityInstance,
                $source instanceof GalleryCategory ? $source : null,
                ($original['position'] ?? null) !== $entityInstance->getPosition(),
            );
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * Writes the register of an edition the first time one is announced, and refuses to touch it afterwards.
     *
     * An edition is a promise made in public - thirty, and no thirty-first. Once its rows exist they are what the
     * certificates already issued point at, so raising the number, lowering it or turning the edition back into an open
     * one are all the same act: rewriting a promise after it was kept. The field is put back as it was and the admin is
     * told, rather than the change being applied quietly.
     *
     * @param array<string, mixed> $original
     */
    private function settleEdition(EntityManagerInterface $entityManager, array $original, GalleryMedia $media): void
    {
        // Anything but a positive number is an open edition, and is stored as one: a zero saved as it was announced an edition of nothing, which no row could ever be claimed from and which the freeze below then refused to let anyone correct
        // Both sides normalised, so a media already carrying a zero compares as open and is repairable
        $previous = $original['editionSize'] ?? null;
        $previous = \is_int($previous) && $previous > 0 ? $previous : null;

        $size = $media->getEditionSize();
        $size = null !== $size && $size > 0 ? $size : null;
        $media->setEditionSize($size);

        if ($previous === $size) {
            return;
        }

        // Nothing has been sold and nothing was ever announced, so this is the announcement: the rows are written now, and claiming from them is what selling a numbered print means (see GalleryPrintCopyRepository::claimNumber)
        if (null === $previous) {
            $this->printCopyRepository->openEdition($media, $size);

            return;
        }

        $media->setEditionSize($previous);
        $this->addFlash('warning', $this->translator->trans('label.gallery_edition_frozen', [], 'gallery'));
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

    // What this site adds to a media and no other site has, rendered from the form type it declares - a site declaring none gets no field at all (see c975L\GalleryBundle\Contract\GalleryCustomizationProviderInterface)
    /** @return list<GalleryDataField> */
    private function dataFields(): array
    {
        $formType = $this->customizationRegistry->getMediaDataFormType();

        if (null === $formType) {
            return [];
        }

        return [
            GalleryDataField::new('data', t('label.data', [], 'gallery'))
                ->setFormType($formType),
        ];
    }

    #[\Override]
    // A declaration of fields, one line per field: its length says how much the screen shows, not how much the method decides
    /** @SuppressWarnings(PHPMD.ExcessiveMethodLength) */
    public function configureFields(string $pageName): iterable
    {
        return [
            // Only the galleries that actually show their medias are offered: an automatic gallery holds none of its own (it lists the last additions of the others, see GalleryLatestProvider), and a trashed one would show none - a media moved to either would disappear from every grid, front and back alike
            AssociationField::new('category')
                ->setLabel(t('label.gallery_category', [], 'gallery'))
                ->setQueryBuilder(static fn (QueryBuilder $queryBuilder): QueryBuilder => $queryBuilder
                    ->andWhere('entity.automaticKind IS NULL')
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

            // The caption shown under the media on its own page, as long as it needs to be - hidden from the grid, where a paragraph per row would bury the thumbnails it exists to show
            TextareaField::new('description')
                ->setLabel(t('label.description', [], 'gallery'))
                ->setHelp(t('label.gallery_media_description_help', [], 'gallery'))
                ->setRequired(false)
                ->hideOnIndex(),

            TextField::new('credits')
                ->setLabel(t('label.credits', [], 'gallery')),

            BooleanField::new('rightsReserved')
                ->setLabel(t('label.rights_reserved', [], 'gallery')),

            BooleanField::new('hidden')
                ->setLabel(t('label.gallery_media_hidden', [], 'gallery'))
                ->setHelp(t('help.gallery_media_hidden', [], 'gallery')),

            BooleanField::new('printable')
                ->setLabel(t('label.gallery_media_printable', [], 'gallery'))
                ->setHelp(t('help.gallery_media_printable', [], 'gallery')),

            // Left empty the photograph is printed on demand without end. Filled it is an edition, and the register is written the moment it is saved - which is why nothing here lets it be raised afterwards (see GalleryMediaSubscriber)
            IntegerField::new('editionSize')
                ->setLabel(t('label.gallery_media_edition_size', [], 'gallery'))
                ->setHelp(t('help.gallery_media_edition_size', [], 'gallery')),

            ...$this->dataFields(),

            // Kept below the media's own fields rather than under the upload above: the pair is answered once in a while, where everything above it is retouched at every pass
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

            IntegerField::new('position')
                ->setLabel(t('label.position', [], 'gallery')),
        ];
    }
}
