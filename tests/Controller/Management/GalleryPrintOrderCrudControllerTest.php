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
use c975L\GalleryBundle\Controller\Management\GalleryPrintFormatCrudController;
use c975L\GalleryBundle\Controller\Management\GalleryPrintOrderCrudController;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Service\GalleryCertificateService;
use c975L\UiBundle\Service\ConfigEditUrlResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\CrudContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    // An order's row buttons are icons on the list, their label kept as the title, while the detail page keeps the labelled buttons
    public function testTheRowActionsAreIconsOnTheListOnly(): void
    {
        $actions = $this->createController(printEnabled: true, admin: true)->configureActions(Actions::new());

        $index = $actions->getAsDto(Crud::PAGE_INDEX);
        foreach (['printCertificates' => 'action.print_certificates', 'releaseToLab' => 'action.release_to_lab', Action::DETAIL => 'action.detail'] as $name => $title) {
            $action = $index->getAction(Crud::PAGE_INDEX, $name);

            $this->assertNotNull($action);
            $this->assertFalse($action->getLabel());
            $this->assertSame($title, $action->getHtmlAttributes()['title']);
        }

        $detail = $actions->getAsDto(Crud::PAGE_DETAIL);
        foreach (['printCertificates', 'releaseToLab'] as $name) {
            $action = $detail->getAction(Crud::PAGE_DETAIL, $name);

            $this->assertNotNull($action);
            $this->assertNotFalse($action->getLabel());
            $this->assertArrayNotHasKey('title', $action->getHtmlAttributes());
        }
    }

    // A member without the role releases nothing and prints nothing, whatever the token
    public function testAMemberWithoutTheRoleIsDenied(): void
    {
        foreach (['releaseToLab', 'printCertificates'] as $action) {
            try {
                $this->createController(printEnabled: true, admin: false, editor: false)->{$action}($this->context(), new Request(['token' => 'token']));
                $this->fail($action . ' ran below the editor role.');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // Both are reached by a GET: a forged request sends nothing to the lab, and hands no certificate
    public function testAForgedRequestIsSentBackToTheListing(): void
    {
        $order = new GalleryPrintOrder();
        $order->setState(GalleryPrintOrder::STATE_FAILED);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $controller = $this->createController(printEnabled: true, admin: false, validToken: false, messageBus: $bus);

        $this->assertSame('/management/print-orders', $controller->releaseToLab($this->context($order), new Request())->getTargetUrl());
        $this->assertSame(GalleryPrintOrder::STATE_FAILED, $order->getState());

        $response = $controller->printCertificates($this->context($order), new Request());
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    // What a click does: the url the button really carries reaches the action through EasyAdmin's router, with a token the action accepts. A stubbed generator answered any action with a url, while the real one sent these two, holding no #[AdminRoute], to the dashboard
    public function testTheReleaseButtonReachesItsAction(): void
    {
        $flow = new RealAdminFlow(GalleryPrintOrderCrudController::class);
        $order = $this->orderOnRow(GalleryPrintOrder::STATE_FAILED);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));
        $controller = $this->createController(printEnabled: true, admin: false, messageBus: $bus, flow: $flow);

        $url = $this->buttonUrl($controller, 'releaseToLab', $order);
        $this->assertSame('releaseToLab', $flow->routeOf($url)[EA::CRUD_ACTION] ?? null);
        $this->assertSame('5', $flow->routeOf($url)[EA::ENTITY_ID] ?? null);

        $response = $controller->releaseToLab($this->context($order), $flow->arriveOn($url));

        $this->assertSame(GalleryPrintOrder::STATE_PENDING, $order->getState());

        // The generator starts from the current query string: what comes back must not carry the token along
        $this->assertStringNotContainsString('token=', $response->getTargetUrl());
    }

    // The same click on the other button: a real generator reaches the action, a stubbed one would answer anything
    public function testTheCertificatesButtonReachesItsAction(): void
    {
        $flow = new RealAdminFlow(GalleryPrintOrderCrudController::class);
        $order = $this->orderOnRow(GalleryPrintOrder::STATE_SENT);
        $certificates = $this->createMock(GalleryCertificateService::class);
        $certificates->expects($this->once())->method('renderMany')->willReturn(null);
        $controller = $this->createController(printEnabled: true, admin: false, flow: $flow, certificateService: $certificates);

        $url = $this->buttonUrl($controller, 'printCertificates', $order);
        $this->assertSame('printCertificates', $flow->routeOf($url)[EA::CRUD_ACTION] ?? null);

        $controller->printCertificates($this->context($order), $flow->arriveOn($url));
    }

    // A token minted for another action of the site does not open these two
    public function testATokenOfAnotherActionIsRefused(): void
    {
        $flow = new RealAdminFlow(GalleryPrintOrderCrudController::class);
        $order = $this->orderOnRow(GalleryPrintOrder::STATE_FAILED);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $controller = $this->createController(printEnabled: true, admin: false, messageBus: $bus, flow: $flow);

        $controller->releaseToLab($this->context($order), new Request(['token' => $flow->csrfTokenManager->getToken(GalleryPrintFormatCrudController::IMPORT_CSRF_TOKEN)->getValue()]));

        $this->assertSame(GalleryPrintOrder::STATE_FAILED, $order->getState());
    }

    private function orderOnRow(string $state): GalleryPrintOrder
    {
        $order = new GalleryPrintOrder();
        $order->setState($state);
        new \ReflectionProperty(GalleryPrintOrder::class, 'id')->setValue($order, 5);

        return $order;
    }

    // The url EasyAdmin itself would put on the detail page's button
    private function buttonUrl(GalleryPrintOrderCrudController $controller, string $name, GalleryPrintOrder $order): string
    {
        $action = $controller->configureActions(Actions::new())->getAsDto(Crud::PAGE_DETAIL)->getAction(Crud::PAGE_DETAIL, $name);

        return ($action->getUrl())($order);
    }

    private function requestWithSession(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function context(?GalleryPrintOrder $order = null): AdminContext
    {
        $entityDto = new EntityDto(GalleryPrintOrder::class, new ClassMetadata(GalleryPrintOrder::class), null, $order ?? new GalleryPrintOrder());

        return AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto));
    }

    private function createController(bool $printEnabled, bool $admin, bool $editor = true, bool $validToken = true, ?MessageBusInterface $messageBus = null, ?RealAdminFlow $flow = null, ?GalleryCertificateService $certificateService = null): GalleryPrintOrderCrudController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $slug): mixed => match ($slug) {
                'gallery-print-enabled' => $printEnabled,
                'site-role-admin' => 'ROLE_ADMIN',
                'site-role-editor' => 'ROLE_EDITOR',
                default => null,
            },
        );
        $configService->method('getBool')->willReturnCallback(static fn (mixed $value): bool => true === $value);

        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('findOneBySlug')->willReturn(new Config());

        $configEditUrlResolver = $this->createStub(ConfigEditUrlResolver::class);
        $configEditUrlResolver->method('resolve')->willReturn('/management/config/edit');

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => ($admin && 'ROLE_ADMIN' === $role) || ($editor && 'ROLE_EDITOR' === $role));

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn($validToken);

        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('unset')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/print-orders');

        [$csrfTokenManager, $requestStack, $adminUrlGenerator] = null === $flow
            ? [$csrfTokenManager, new RequestStack([$this->requestWithSession()]), $adminUrlGenerator]
            : [$flow->csrfTokenManager, $flow->requestStack, $flow->urlGenerator];

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $container = new Container();
        $container->set('security.authorization_checker', $authorizationChecker);
        $container->set('security.csrf.token_manager', $csrfTokenManager);
        $container->set('request_stack', $requestStack);

        $controller = new GalleryPrintOrderCrudController(
            $configService,
            $certificateService ?? $this->createStub(GalleryCertificateService::class),
            $messageBus ?? $this->createStub(MessageBusInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $adminUrlGenerator,
            $configRepository,
            $configEditUrlResolver,
            $translator,
            $csrfTokenManager,
        );
        $controller->setContainer($container);

        return $controller;
    }
}
