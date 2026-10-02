<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Management\ContentLocaleScreen;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Controller\Management\GalleryPrintFormatCrudController;
use c975L\GalleryBundle\Model\PrintCatalogueImportReport;
use c975L\GalleryBundle\Service\GalleryTranslator;
use c975L\GalleryBundle\Service\PrintCatalogueImporter;
use c975L\UiBundle\Service\ConfigEditUrlResolver;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// What the import action says out loud: the rows written are counted, and everything the report is unsure of is stated rather than left to surface on the first order
class GalleryPrintFormatCrudControllerTest extends TestCase
{
    public function testAFilledCatalogueIsReportedWithItsCount(): void
    {
        $session = $this->import(new PrintCatalogueImportReport(12, 0, [], false));

        $this->assertSame(['flash.print_catalogue_imported'], $session->getFlashBag()->get('success'));
        $this->assertSame([], $session->getFlashBag()->get('warning'));
    }

    // Run again after an update, the action writes nothing where the range has gained nothing - which has to read as "nothing to do" rather than as a failure
    public function testACatalogueAlreadyThereSaysSoWithoutAlarming(): void
    {
        $session = $this->import(new PrintCatalogueImportReport(0, 12, [], false));

        $this->assertSame(['flash.print_catalogue_nothing_to_import'], $session->getFlashBag()->get('info'));
        $this->assertSame([], $session->getFlashBag()->get('success'));
    }

    // Forty rows changed is not "nothing to do": the refresh is counted on its own, and the "nothing to import" is kept for a run that changed nothing at all
    public function testARefreshedCatalogueIsReportedWithoutSayingNothingWasDone(): void
    {
        $session = $this->import(new PrintCatalogueImportReport(0, 12, [], false, 40));

        $this->assertSame(['flash.print_catalogue_refreshed'], $session->getFlashBag()->get('success'));
        $this->assertSame([], $session->getFlashBag()->get('info'));
    }

    // The rows were written on references nobody confirmed: an unknown one would otherwise surface at the lab, on a print somebody has paid for
    public function testAnUncheckedImportIsSaidOutLoud(): void
    {
        $session = $this->import(new PrintCatalogueImportReport(12, 0, [], true));

        $this->assertSame(['flash.print_catalogue_unchecked'], $session->getFlashBag()->get('warning'));
    }

    public function testTheReferencesTheLabNoLongerHasAreNamed(): void
    {
        $session = $this->import(new PrintCatalogueImportReport(10, 0, ['GLOBAL-HGE', 'GLOBAL-HPR'], false));

        $this->assertSame(['flash.print_catalogue_unknown_skus'], $session->getFlashBag()->get('warning'));
    }

    public function testTheActionSendsTheAdminBackToTheCatalogue(): void
    {
        $controller = $this->createController(new PrintCatalogueImportReport(1, 0, [], false), $this->createSession());

        $this->assertSame('/management/print-formats', $controller->importPrintCatalogue()->getTargetUrl());
    }

    // The language screen is opened from the list whether or not the lab publishes a range, a site printing by hand naming its formats too (see ContentLocaleCrudTrait::translateAction())
    public function testTheLanguageScreenIsOfferedEvenWithoutACatalogueToImport(): void
    {
        $actions = $this->createController(new PrintCatalogueImportReport(0, 0, [], false), $this->createSession())->configureActions(Actions::new());

        $index = $actions->getAsDto(Crud::PAGE_INDEX);

        $this->assertNotNull($index->getAction(Crud::PAGE_INDEX, 'translate'));
        $this->assertNull($index->getAction(Crud::PAGE_INDEX, 'importPrintCatalogue'));
    }

    // The sale closed, the catalogue is still written meanwhile: the index says so, and hands an admin the switch that opens it
    public function testTheClosedSaleIsSaidWithTheSwitchForAnAdmin(): void
    {
        $parameters = $this->createController(new PrintCatalogueImportReport(0, 0, [], false), $this->createSession(), printEnabled: false, admin: true)
            ->configureResponseParameters(KeyValueStore::new());

        $this->assertFalse($parameters->get('print_enabled'));
        $this->assertSame('/management/config/edit', $parameters->get('print_switch_url'));
    }

    // ConfigCrudController denies anything below the admin role, so an editor is told the sale is closed without a link that would refuse them
    public function testAnEditorIsToldTheSaleIsClosedWithoutTheSwitch(): void
    {
        $parameters = $this->createController(new PrintCatalogueImportReport(0, 0, [], false), $this->createSession(), printEnabled: false)
            ->configureResponseParameters(KeyValueStore::new());

        $this->assertFalse($parameters->get('print_enabled'));
        $this->assertNull($parameters->get('print_switch_url'));
    }

    public function testAnOpenSaleCarriesNoNotice(): void
    {
        $parameters = $this->createController(new PrintCatalogueImportReport(0, 0, [], false), $this->createSession(), printEnabled: true, admin: true)
            ->configureResponseParameters(KeyValueStore::new());

        $this->assertTrue($parameters->get('print_enabled'));
        $this->assertNull($parameters->get('print_switch_url'));
    }

    private function import(PrintCatalogueImportReport $report): Session
    {
        $session = $this->createSession();
        $this->createController($report, $session)->importPrintCatalogue();

        return $session;
    }

    private function createController(PrintCatalogueImportReport $report, Session $session, bool $printEnabled = true, bool $admin = false): GalleryPrintFormatCrudController
    {
        $importer = $this->createStub(PrintCatalogueImporter::class);
        $importer->method('import')->willReturn($report);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/print-formats');

        $request = new Request();
        $request->setSession($session);

        $container = new Container();
        $container->set('request_stack', new RequestStack([$request]));

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => $admin && 'ROLE_ADMIN' === $role);
        $container->set('security.authorization_checker', $authorizationChecker);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $slug): mixed => match ($slug) {
                'gallery-print-enabled' => $printEnabled,
                'site-role-admin' => 'ROLE_ADMIN',
                default => null,
            },
        );
        $configService->method('getBool')->willReturnCallback(static fn (mixed $value): bool => true === $value);

        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('findOneBySlug')->willReturn(new Config());

        $configEditUrlResolver = $this->createStub(ConfigEditUrlResolver::class);
        $configEditUrlResolver->method('resolve')->willReturn('/management/config/edit');

        // Action is final and cannot be doubled, so the stub hands a real one back
        $contentLocaleScreen = $this->createStub(ContentLocaleScreen::class);
        $contentLocaleScreen->method('action')->willReturnCallback(static fn (string $name): Action => Action::new($name)->linkToUrl('#'));

        // Named rather than positional: this constructor is a list of services kept in alphabetical order, and one more of them landing in the middle of it would otherwise shift every argument below
        $controller = new GalleryPrintFormatCrudController(
            adminContextProvider: $this->createStub(AdminContextProviderInterface::class),
            adminUrlGenerator: $adminUrlGenerator,
            configEditUrlResolver: $configEditUrlResolver,
            configRepository: $configRepository,
            configService: $configService,
            contentLocaleScreen: $contentLocaleScreen,
            galleryTranslator: $this->createStub(GalleryTranslator::class),
            printCatalogueImporter: $importer,
            translator: $translator,
        );
        $controller->setContainer($container);

        return $controller;
    }

    private function createSession(): Session
    {
        return new Session(new MockArraySessionStorage());
    }
}
