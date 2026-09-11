<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Controller\Management\Trait;

use c975L\ConfigBundle\Management\ContentLocaleScreen;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Controller\Management\GalleryPrintFormatCrudController;
use c975L\GalleryBundle\Controller\Management\Trait\ContentLocaleCrudTrait;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Service\GalleryTranslator;
use c975L\GalleryBundle\Service\PrintCatalogueImporter;
use Doctrine\ORM\Mapping\ClassMetadata;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\CrudContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class ContentLocaleCrudTraitTest extends TestCase
{
    // A method of the class wins over a trait's in silence, which is how the tabs and the stored translations vanished from two screens out of three - the trait keeps to names no CRUD controller inherits
    public function testTheTraitDeclaresNothingAControllerCouldShadow(): void
    {
        $inherited = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            new \ReflectionClass(AbstractCrudController::class)->getMethods()
        );

        foreach (new \ReflectionClass(ContentLocaleCrudTrait::class)->getMethods() as $method) {
            $this->assertNotContains($method->getName(), $inherited, sprintf('ContentLocaleCrudTrait::%s() shares its name with AbstractCrudController', $method->getName()));
        }
    }

    // A saved row on a multilingual site hands the language tabs their parameters, through the helper the screen calls from its own override
    public function testASavedRowHandsTheLanguageTabsTheirParameters(): void
    {
        $contentLocaleScreen = $this->createMock(ContentLocaleScreen::class);
        $contentLocaleScreen->expects($this->once())->method('addParameters')->with(
            $this->isInstanceOf(KeyValueStore::class),
            GalleryPrintFormatCrudController::class,
            3,
            ['en'],
            null,
        );

        $this->controller($contentLocaleScreen, active: true)->configureResponseParameters(KeyValueStore::new(['pageName' => Crud::PAGE_EDIT]));
    }

    // A site declaring a single language gets no tabs at all
    public function testASingleLanguageSiteGetsNoTabs(): void
    {
        $contentLocaleScreen = $this->createMock(ContentLocaleScreen::class);
        $contentLocaleScreen->expects($this->never())->method('addParameters');

        $this->controller($contentLocaleScreen, active: false)->configureResponseParameters(KeyValueStore::new(['pageName' => Crud::PAGE_EDIT]));
    }

    // The print formats screen, the lightest of the three using the trait, opened on a saved format
    private function controller(ContentLocaleScreen $contentLocaleScreen, bool $active): GalleryPrintFormatCrudController
    {
        $format = new GalleryPrintFormat();
        new \ReflectionProperty(GalleryPrintFormat::class, 'id')->setValue($format, 3);
        $entityDto = new EntityDto(GalleryPrintFormat::class, new ClassMetadata(GalleryPrintFormat::class), null, $format);

        $adminContextProvider = $this->createStub(AdminContextProviderInterface::class);
        $adminContextProvider->method('getContext')->willReturn(AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto)));

        $galleryTranslator = $this->createStub(GalleryTranslator::class);
        $galleryTranslator->method('isActive')->willReturn($active);
        $galleryTranslator->method('getTranslatableLocales')->willReturn($active ? ['en'] : []);

        return new GalleryPrintFormatCrudController(
            adminContextProvider: $adminContextProvider,
            adminUrlGenerator: $this->createStub(AdminUrlGeneratorInterface::class),
            configService: $this->createStub(ConfigServiceInterface::class),
            contentLocaleScreen: $contentLocaleScreen,
            galleryTranslator: $galleryTranslator,
            printCatalogueImporter: $this->createStub(PrintCatalogueImporter::class),
            translator: $this->createStub(TranslatorInterface::class),
        );
    }
}
