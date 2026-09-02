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
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Service\PrintCatalogueImporter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

// The print catalogue - the sizes on sale, their prices and what the lab calls them. One screen, because a catalogue is a list an admin reprices and nothing else
class GalleryPrintFormatCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly PrintCatalogueImporter $printCatalogueImporter,
        private readonly TranslatorInterface $translator,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return GalleryPrintFormat::class;
    }

    private function roleNeeded(): string
    {
        return (string) $this->configService->get('site-role-editor');
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(t('label.print_format', [], 'gallery'))
            ->setEntityLabelInPlural(t('label.print_formats', [], 'gallery'))
            ->setEntityPermission($this->roleNeeded())
            ->setDefaultSort(['position' => 'ASC'])
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/index', '@c975LGallery/management/gallery_print_format_index.html.twig')
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        // Only offered where there is something to import: a site printing by hand, or at a lab that publishes no range, is shown a button that would do nothing
        if (null === $this->printCatalogueImporter->getCatalogue()) {
            return $actions;
        }

        $importAction = Action::new('importPrintCatalogue', t('action.import_print_catalogue', [], 'gallery'), 'fas fa-download')
            ->linkToCrudAction('importPrintCatalogue')
            ->createAsGlobalAction()
            ->addCssClass('btn btn-secondary')
        ;

        return $actions
            ->add(Crud::PAGE_INDEX, $importAction)
            ->setPermission('importPrintCatalogue', $this->roleNeeded())
        ;
    }

    // Writes the lines the lab confirms it prints, and says what it did - run again after a bundle update, it adds what the catalogue has gained and touches nothing else
    #[AdminRoute('/import-catalogue')]
    public function importPrintCatalogue(): RedirectResponse
    {
        $report = $this->printCatalogueImporter->import();

        if ($report->imported > 0) {
            $this->addFlash('success', $this->translator->trans('flash.print_catalogue_imported', ['%count%' => $report->imported], 'gallery'));
        } else {
            $this->addFlash('info', $this->translator->trans('flash.print_catalogue_nothing_to_import', [], 'gallery'));
        }

        // Said out loud rather than left to be discovered: the rows were written on references nobody confirmed, and the first order is where an unknown one would surface
        if ($report->unchecked) {
            $this->addFlash('warning', $this->translator->trans('flash.print_catalogue_unchecked', [], 'gallery'));
        }

        if ([] !== $report->unknownSkus) {
            $this->addFlash('warning', $this->translator->trans('flash.print_catalogue_unknown_skus', ['%skus%' => implode(', ', $report->unknownSkus)], 'gallery'));
        }

        return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield SlugField::new('slug', t('label.print_format_slug', [], 'gallery'))
            ->setTargetFieldName('label')
            ->setHelp(t('help.print_format_slug', [], 'gallery'))
            ->hideOnIndex()
        ;

        yield TextField::new('label', t('label.print_format_label', [], 'gallery'))
            ->setHelp(t('help.print_format_label', [], 'gallery'));

        yield IntegerField::new('widthCm', t('label.print_format_width', [], 'gallery'));
        yield IntegerField::new('heightCm', t('label.print_format_height', [], 'gallery'));

        // Shown rather than asked for: it is arithmetic on the two sizes above and the resolution, and it is the number that decides which photographs are offered this format at all
        yield IntegerField::new('requiredPixels', t('label.print_format_required_pixels', [], 'gallery'))
            ->setHelp(t('help.print_format_required_pixels', [], 'gallery'))
            ->onlyOnIndex()
        ;

        yield IntegerField::new('dpi', t('label.print_format_dpi', [], 'gallery'))
            ->setHelp(t('help.print_format_dpi', [], 'gallery'))
            ->hideOnIndex()
        ;

        yield MoneyField::new('price', t('label.print_format_price', [], 'gallery'))
            ->setCurrency('EUR')
            ->setStoredAsCents()
        ;

        yield NumberField::new('vat', t('label.print_format_vat', [], 'gallery'))
            ->setNumDecimals(1)
            ->hideOnIndex()
        ;

        yield TextField::new('sku', t('label.print_format_sku', [], 'gallery'))
            ->setHelp(t('help.print_format_sku', [], 'gallery'))
        ;

        yield TextField::new('paper', t('label.print_format_paper', [], 'gallery'))
            ->setHelp(t('help.print_format_paper', [], 'gallery'))
        ;

        yield TextField::new('paperDescription', t('label.print_format_paper_description', [], 'gallery'))
            ->setHelp(t('help.print_format_paper_description', [], 'gallery'))
            ->hideOnIndex()
        ;

        yield IntegerField::new('position', t('label.print_format_position', [], 'gallery'))->hideOnIndex();

        yield BooleanField::new('published', t('label.print_format_published', [], 'gallery'));
    }
}
