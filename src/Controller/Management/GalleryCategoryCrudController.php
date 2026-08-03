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
use c975L\ConfigBundle\Service\Export\ContentExporter;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Management\GalleryExportProvider;
use c975L\GalleryBundle\Management\GalleryImportProvider;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

// Not exposed in the EasyAdmin sidebar (see GalleryPhotoCrudController, which is the single menu entry for the whole gallery feature) - reachable from there via a toolbar link instead, so both screens stay visibly linked
class GalleryCategoryCrudController extends AbstractCrudController
{
    private const ROLE_NEEDED = 'ROLE_ADMIN';

    public function __construct(
        private readonly GalleryRepository $galleryRepository,
        private readonly GalleryCategoryRepository $galleryCategoryRepository,
        private readonly SluggerInterface $slugger,
        private readonly TranslatorInterface $translator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly ContentExporter $contentExporter,
        private readonly GalleryExportProvider $galleryExportProvider,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return GalleryCategory::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(t('label.gallery_category', [], 'gallery'))
            ->setEntityLabelInPlural(t('label.gallery_categories', [], 'gallery'))
            ->setEntityPermission(self::ROLE_NEEDED)
            ->setDefaultSort(['position' => 'ASC'])
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
        $actions->setPermission('exportSelection', self::ROLE_NEEDED);

        // Lets the admin back out of a create/edit without saving - mirrors EasyAdmin's own built-in actions (linkToCrudAction targeting INDEX, same as Action::INDEX itself)
        $cancelAction = Action::new('cancel', $this->translator->trans('action.cancel', [], 'EasyAdminBundle'), 'fa fa-times')
            ->linkToCrudAction(Action::INDEX)
            ->addCssClass('btn btn-secondary');

        return $actions
            ->add(Crud::PAGE_NEW, $cancelAction)
            ->add(Crud::PAGE_EDIT, $cancelAction)
            ->setPermission(Action::INDEX, self::ROLE_NEEDED)
            ->setPermission(Action::NEW, self::ROLE_NEEDED)
            ->setPermission(Action::EDIT, self::ROLE_NEEDED)
            ->setPermission(Action::DELETE, self::ROLE_NEEDED)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.edit', [], 'EasyAdminBundle'),
            ))
            // The catch-all "Non classé" category must always exist as a fallback for photos uploaded without a real one picked (see GalleryCategoryRepository::findOrCreateUncategorized)
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action->displayIf(static fn (GalleryCategory $category): bool => !$category->isUncategorized()),
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

            Field::new('photos')
                ->setLabel(t('label.thumbnail', [], 'gallery'))
                ->onlyOnIndex()
                ->setTemplatePath('@c975LGallery/management/_gallery_category_thumbnail.html.twig'),

            TextField::new('title')
                ->setLabel(t('label.title', [], 'gallery'))
                ->setRequired(true),

            SlugField::new('slug')
                ->setLabel(t('label.slug', [], 'gallery'))
                ->setTargetFieldName('title')
                ->setRequired(true),

            IntegerField::new('position')
                ->setLabel(t('label.position', [], 'gallery'))
                ->hideOnIndex(),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->prepareCategory($entityInstance);

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->prepareCategory($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);
    }

    private function prepareCategory(GalleryCategory $category): void
    {
        if (null === $category->getGallery()) {
            $category->setGallery($this->galleryRepository->findOrCreateDefault());
        }

        $slug = $category->getSlug();
        if (null !== $slug) {
            $slug = strtolower($this->slugger->slug($slug)->toString());
            $category->setSlug($this->galleryCategoryRepository->makeSlugUnique($category, $slug));
        }
    }

    // Exports the checked categories (with their gallery and photos, real files bundled in the archive) as a downloadable zip, meant to be re-uploaded elsewhere via ConfigBundle's ContentImportController (see GalleryImportProvider) - restricted to ROLE_ADMIN, see configureActions()
    #[AdminRoute]
    public function exportSelection(AdminContext $context, BatchActionDto $batchActionDto): Response
    {
        $this->denyAccessUnlessGranted(self::ROLE_NEEDED);

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
