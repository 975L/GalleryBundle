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
use c975L\GalleryBundle\Entity\GalleryPhoto;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Form\Type\VichImageType;

use function Symfony\Component\Translation\t;

// The single EasyAdmin menu entry for the whole gallery feature (see GalleryBundle's own MenuProvider) - category management (GalleryCategoryCrudController) isn't listed separately in the sidebar, it's reachable from the "manageCategories" toolbar link below, so both stay visibly linked instead of looking like two unrelated screens
class GalleryPhotoCrudController extends AbstractCrudController
{
    private const ROLE_NEEDED = 'ROLE_ADMIN';

    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return GalleryPhoto::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(t('label.gallery_photo', [], 'gallery'))
            ->setEntityLabelInPlural(t('label.gallery_photos', [], 'gallery'))
            ->setEntityPermission(self::ROLE_NEEDED)
            ->setDefaultSort(['category' => 'ASC', 'position' => 'ASC'])
            ->overrideTemplate('crud/index', '@c975LGallery/management/gallery_photo_index.html.twig')
            ->overrideTemplate('crud/edit', '@c975LGallery/management/gallery_photo_edit.html.twig')
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('category')->setLabel(t('label.gallery_category', [], 'gallery')))
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        $uploadPhotos = Action::new('uploadPhotos', t('label.gallery_upload_photos', [], 'gallery'), 'fas fa-upload')
            ->linkToUrl(fn (): string => $this->generateUrl(GalleryPhotoUploadController::UPLOAD_ROUTE))
            ->createAsGlobalAction()
        ;

        $manageCategories = Action::new('manageCategories', t('label.gallery_manage_categories', [], 'gallery'), 'fas fa-folder-tree')
            ->linkToUrl(fn (): string => $this->adminUrlGenerator
                ->setController(GalleryCategoryCrudController::class)
                ->setAction(Action::INDEX)
                ->generateUrl())
            ->createAsGlobalAction()
        ;

        // Lets the admin back out of an edit without saving - mirrors EasyAdmin's own built-in actions (linkToCrudAction targeting INDEX, same as Action::INDEX itself)
        $cancelAction = Action::new('cancel', $this->translator->trans('action.cancel', [], 'EasyAdminBundle'), 'fa fa-times')
            ->linkToCrudAction(Action::INDEX)
            ->addCssClass('btn btn-secondary');

        return $actions
            ->setPermission(Action::INDEX, self::ROLE_NEEDED)
            ->setPermission(Action::EDIT, self::ROLE_NEEDED)
            ->setPermission(Action::DELETE, self::ROLE_NEEDED)
            // Photos are only ever created in bulk, via the dedicated upload screen (see GalleryPhotoUploadController) - not one at a time here
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, $uploadPhotos)
            ->add(Crud::PAGE_INDEX, $manageCategories)
            ->add(Crud::PAGE_EDIT, $cancelAction)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.edit', [], 'EasyAdminBundle'),
            ))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.delete', [], 'EasyAdminBundle'),
            ))
            // Detail adds no information beyond what edit already shows
            ->disable(Action::DETAIL)
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),

            AssociationField::new('category')
                ->setLabel(t('label.gallery_category', [], 'gallery'))
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

            TextField::new('alt')
                ->setLabel(t('label.alt_text', [], 'gallery'))
                ->hideOnIndex(),

            TextField::new('credits')
                ->setLabel(t('label.credits', [], 'gallery'))
                ->hideOnIndex(),

            // A video entry keeps its uploaded image above - it is what the grid shows, the type only decides what the detail page opens on (see GalleryPhoto::isVideo())
            // setTranslatableChoices(), not setChoices(): a plain choice array's keys only translate under EasyAdmin's own CRUD-level domain, which isn't "gallery"
            ChoiceField::new('mediaType')
                ->setLabel(t('label.gallery_media_type', [], 'gallery'))
                ->setTranslatableChoices(array_combine(
                    GalleryPhoto::MEDIA_TYPES,
                    array_map(static fn (string $type) => t('label.gallery_media_type_' . $type, [], 'gallery'), GalleryPhoto::MEDIA_TYPES),
                ))
                ->hideOnIndex(),

            TextField::new('externalId')
                ->setLabel(t('label.gallery_external_id', [], 'gallery'))
                ->setHelp(t('label.gallery_external_id_help', [], 'gallery'))
                ->hideOnIndex(),

            BooleanField::new('rightsReserved')
                ->setLabel(t('label.rights_reserved', [], 'gallery'))
                ->hideOnIndex(),

            IntegerField::new('position')
                ->setLabel(t('label.position', [], 'gallery'))
                ->hideOnIndex(),
        ];
    }
}
