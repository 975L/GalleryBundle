<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Controller\Management\GalleryPrintFormatCrudController;
use c975L\GalleryBundle\Model\PrintCatalogueImportReport;
use c975L\GalleryBundle\Service\PrintCatalogueImporter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
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

    private function import(PrintCatalogueImportReport $report): Session
    {
        $session = $this->createSession();
        $this->createController($report, $session)->importPrintCatalogue();

        return $session;
    }

    private function createController(PrintCatalogueImportReport $report, Session $session): GalleryPrintFormatCrudController
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

        $controller = new GalleryPrintFormatCrudController(
            $this->createStub(ConfigServiceInterface::class),
            $importer,
            $translator,
            $adminUrlGenerator,
        );
        $controller->setContainer($container);

        return $controller;
    }

    private function createSession(): Session
    {
        return new Session(new MockArraySessionStorage());
    }
}
