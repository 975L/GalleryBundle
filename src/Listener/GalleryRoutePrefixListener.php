<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Listener;

use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

// Matching a gallery url is the routes' condition business (see GalleryRoutePrefix); generating one is this listener's: the generator fills a route parameter it wasn't given from the request context, which is where the configured prefix is put here, so path('gallery_category', {category: ...}) keeps taking the category alone
// Runs just before Symfony's own RouterListener (priority 32), the parameter having to be in the context before anything generates a url
#[AsEventListener(event: KernelEvents::REQUEST, priority: 33)]
class GalleryRoutePrefixListener
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly GalleryRoutePrefix $routePrefix,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->router->getContext()->setParameter(GalleryRoutePrefix::PARAMETER, $this->routePrefix->get());
    }
}
