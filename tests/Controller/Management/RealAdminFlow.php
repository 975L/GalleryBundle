<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Controller\Management;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\DashboardContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\RequestContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Provider\AdminContextProvider;
use EasyCorp\Bundle\EasyAdminBundle\Registry\AdminControllerRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminRouteGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext as RoutingContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

// EasyAdmin's own route and url generators and Symfony's own csrf manager, none of them doubled: a stubbed generator answers any action with a url, a real one sends an action holding no #[AdminRoute] to the dashboard
final class RealAdminFlow
{
    public readonly AdminUrlGenerator $urlGenerator;
    public readonly CsrfTokenManager $csrfTokenManager;
    public readonly RequestStack $requestStack;
    private readonly string $dashboardFqcn;
    private readonly RouteCollection $routes;

    /** @param class-string $crudController */
    public function __construct(string $crudController)
    {
        $cache = new ArrayAdapter();
        $dashboard = new #[AdminDashboard(routePath: '/management', routeName: 'management')] class extends AbstractDashboardController {
        };
        $this->dashboardFqcn = $dashboard::class;
        $routeGenerator = new AdminRouteGenerator([$dashboard], [new \ReflectionClass($crudController)->newInstanceWithoutConstructor()], $cache);
        $this->routes = $routeGenerator->generateAll();

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack = new RequestStack([$request]);

        $this->csrfTokenManager = new CsrfTokenManager(new UriSafeTokenGenerator(), new SessionTokenStorage($this->requestStack));
        $this->urlGenerator = new AdminUrlGenerator(
            new AdminContextProvider($this->requestStack),
            new UrlGenerator($this->routes, new RoutingContext()),
            new AdminControllerRegistry($cache),
            $routeGenerator,
            $cache,
        );
    }

    // The request a click on that url makes current, with the admin context the generator reads the query string from
    public function arriveOn(string $url): Request
    {
        $request = Request::create($url);
        $request->setSession($this->requestStack->getSession());
        $dashboard = Dashboard::new()->getAsDto();
        $dashboard->setRouteName('management');
        $request->attributes->set(EA::CONTEXT_REQUEST_ATTRIBUTE, AdminContext::forTesting(RequestContext::forTesting($request), dashboardContext: DashboardContext::forTesting($dashboard, $this->dashboardFqcn)));
        $this->requestStack->push($request);

        return $request;
    }

    // What the router makes of a url the way a click would reach it
    /** @return array<string, mixed> */
    public function routeOf(string $url): array
    {
        return new UrlMatcher($this->routes, new RoutingContext())->match((string) parse_url($url, \PHP_URL_PATH));
    }
}
