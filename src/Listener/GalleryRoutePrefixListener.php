<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Listener;

use c975L\ConfigBundle\EventSubscriber\RedirectSubscriber;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

// Matching a gallery url is the routes' condition business (see GalleryRoutePrefix); generating one is this listener's: the generator fills a route parameter it wasn't given from the request context, which is where the configured prefix is put here, so path('gallery_category', {category: ...}) keeps taking the category alone. Runs before ConfigBundle's RedirectSubscriber, as the error page a "gone" row leads to still renders links
#[AsEventListener(event: KernelEvents::REQUEST, priority: RedirectSubscriber::PRIORITY + 1)]
class GalleryRoutePrefixListener
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly GalleryRoutePrefix $routePrefix,
    ) {
    }

    // Puts the configured prefix in the router's context
    public function __invoke(RequestEvent $event): void
    {
        $context = $this->router->getContext();

        // A fragment renders inside a page whose context is already set, but the error page of a request aborted before this listener ran is a sub-request on an empty one
        if (!$event->isMainRequest() && $context->hasParameter(GalleryRoutePrefix::PARAMETER)) {
            return;
        }

        $context->setParameter(GalleryRoutePrefix::PARAMETER, $this->routePrefix->get());
    }
}
