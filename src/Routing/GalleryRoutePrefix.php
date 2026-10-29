<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Routing;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Bundle\FrameworkBundle\Routing\Attribute\AsRoutingConditionService;
use Symfony\Contracts\Service\ResetInterface;

// The first segment of the public routes, edited in the back office (ConfigBundle's "gallery-route-prefix" entry) and read at each request rather than baked into the router's cache - changing it in the dashboard takes effect straight away, with no cache to clear
// The routes carry it as a {gallery_prefix} parameter and let this service decide, in their condition, whether the segment they were handed is the configured one: a route matching /{anything}/{category} without that check would swallow every two-segment url of the site
#[AsRoutingConditionService(alias: self::ALIAS)]
class GalleryRoutePrefix implements ResetInterface
{
    public const ALIAS = 'gallery_route_prefix';

    public const PARAMETER = 'gallery_prefix';

    public const SLUG = 'gallery-route-prefix';

    public const DEFAULT = 'gallery';

    private ?string $prefix = null;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // Read once per request, the listener and every route condition asking for it
    public function get(): string
    {
        return $this->prefix ??= $this->resolve();
    }

    // An empty value would leave the category route mounted at the site root, catching every single-segment url - the default takes over instead, as it does before the entry has been loaded at all (c975l:config:load-all)
    private function resolve(): string
    {
        $prefix = trim((string) $this->configService->get(self::SLUG), " \t\n\r\0\x0B/");

        return '' === $prefix ? self::DEFAULT : $prefix;
    }

    // What the routes' condition calls: any other first segment simply doesn't match, and the router carries on with the rest of the site's routes
    public function matches(mixed $segment): bool
    {
        return is_string($segment) && $segment === $this->get();
    }

    // Dropped between two requests so a worker runtime (FrankenPHP, RoadRunner...) still picks up a prefix changed in the dashboard
    public function reset(): void
    {
        $this->prefix = null;
    }
}
