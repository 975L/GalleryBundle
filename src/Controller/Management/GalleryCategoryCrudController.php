<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller\Management;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Repository\GalleryRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\String\Slugger\SluggerInterface;

use function Symfony\Component\Translation\t;

// Not exposed in the EasyAdmin sidebar (see GalleryPhotoCrudController, which is the single menu
// entry for the whole gallery feature) - reachable from there via a toolbar link instead, so both
// screens stay visibly linked
class GalleryCategoryCrudController extends AbstractCrudController
{
    private const ROLE_NEEDED = 'ROLE_ADMIN';

    public function __construct(
        private readonly GalleryRepository $galleryRepository,
        private readonly SluggerInterface $slugger,
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
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->setPermission(Action::INDEX, self::ROLE_NEEDED)
            ->setPermission(Action::NEW, self::ROLE_NEEDED)
            ->setPermission(Action::EDIT, self::ROLE_NEEDED)
            ->setPermission(Action::DELETE, self::ROLE_NEEDED)
            // The catch-all "Non classé" category must always exist as a fallback for photos
            // uploaded without a real one picked (see GalleryCategoryRepository::findOrCreateUncategorized)
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action): Action => $action->displayIf(
                static fn (GalleryCategory $category): bool => !$category->isUncategorized()
            ))
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),

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
            $category->setSlug(strtolower($this->slugger->slug($slug)->toString()));
        }
    }
}
