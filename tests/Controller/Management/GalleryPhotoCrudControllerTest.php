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
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Form\Type\VichImageType;

class GalleryPhotoCrudControllerTest extends TestCase
{
    private function createController(): GalleryPhotoCrudController
    {
        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/gallery-photos');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new GalleryPhotoCrudController($adminUrlGenerator, $translator);
    }

    // --- configureActions --------------------------------------------------------------------------------

    // Photos are only ever created in bulk via the dedicated upload screen (see GalleryPhotoUploadController) - never one at a time here
    public function testConfigureActionsDisablesNewAndDetailAndAddsUploadAndManageCategoriesGlobalActions(): void
    {
        $controller = $this->createController();

        // A real EasyAdmin runtime pre-populates default actions (EDIT, DELETE...) before calling configureActions() - update() below assumes EDIT/DELETE already exist on PAGE_INDEX
        $actions = $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $disabled = $actions->getAsDto(null)->getDisabledActions();
        $this->assertContains(Action::NEW, $disabled);
        $this->assertContains(Action::DETAIL, $disabled);

        $indexActions = $actions->getAsDto(Crud::PAGE_INDEX)->getActions();
        $this->assertArrayHasKey('uploadPhotos', $indexActions);
        $this->assertArrayHasKey('manageCategories', $indexActions);
        $this->assertNotNull($actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, 'cancel'));
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
}
