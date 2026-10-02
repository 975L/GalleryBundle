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
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Controller\Management\GalleryPrintOrderCrudController;
use c975L\GalleryBundle\Service\GalleryCertificateService;
use c975L\UiBundle\Service\ConfigEditUrlResolver;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

// The orders screen stays listed while the sale is closed, so a test order can be followed there: the index says the sale is closed rather than the entry going missing
class GalleryPrintOrderCrudControllerTest extends TestCase
{
    // The switch's edit url is handed to an admin only, ConfigCrudController denying anything below that role
    public function testTheClosedSaleIsSaidWithTheSwitchForAnAdmin(): void
    {
        $parameters = $this->createController(printEnabled: false, admin: true)->configureResponseParameters(KeyValueStore::new());

        $this->assertFalse($parameters->get('print_enabled'));
        $this->assertSame('/management/config/edit', $parameters->get('print_switch_url'));
    }

    public function testAnEditorIsToldTheSaleIsClosedWithoutTheSwitch(): void
    {
        $parameters = $this->createController(printEnabled: false, admin: false)->configureResponseParameters(KeyValueStore::new());

        $this->assertFalse($parameters->get('print_enabled'));
        $this->assertNull($parameters->get('print_switch_url'));
    }

    public function testAnOpenSaleCarriesNoNotice(): void
    {
        $parameters = $this->createController(printEnabled: true, admin: true)->configureResponseParameters(KeyValueStore::new());

        $this->assertTrue($parameters->get('print_enabled'));
        $this->assertNull($parameters->get('print_switch_url'));
    }

    // The template the notice is drawn by, included by both print indexes
    public function testBothPrintIndexesDrawTheNotice(): void
    {
        foreach (['gallery_print_order_index', 'gallery_print_format_index'] as $template) {
            $this->assertStringContainsString(
                '_gallery_print_sale_notice.html.twig',
                (string) file_get_contents(dirname(__DIR__, 3) . '/templates/management/' . $template . '.html.twig'),
            );
        }
    }

    private function createController(bool $printEnabled, bool $admin): GalleryPrintOrderCrudController
    {
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

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => $admin && 'ROLE_ADMIN' === $role);

        $container = new Container();
        $container->set('security.authorization_checker', $authorizationChecker);

        $controller = new GalleryPrintOrderCrudController(
            $configService,
            $this->createStub(GalleryCertificateService::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AdminUrlGeneratorInterface::class),
            $configRepository,
            $configEditUrlResolver,
        );
        $controller->setContainer($container);

        return $controller;
    }
}
