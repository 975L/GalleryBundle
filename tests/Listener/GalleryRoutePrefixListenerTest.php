<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Listener;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Listener\GalleryRoutePrefixListener;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

class GalleryRoutePrefixListenerTest extends TestCase
{
    private RequestContext $context;

    protected function setUp(): void
    {
        $this->context = new RequestContext();
    }

    private function createListener(string $configuredPrefix): GalleryRoutePrefixListener
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getContext')->willReturn($this->context);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($configuredPrefix);

        return new GalleryRoutePrefixListener($router, new GalleryRoutePrefix($configService));
    }

    private function createEvent(int $requestType): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), new Request(), $requestType);
    }

    // What lets path('gallery_category', {category: ...}) generate a url without being handed the prefix
    public function testItPutsTheConfiguredPrefixInTheRouterContext(): void
    {
        ($this->createListener('galerie'))($this->createEvent(HttpKernelInterface::MAIN_REQUEST));

        $this->assertSame('galerie', $this->context->getParameter(GalleryRoutePrefix::PARAMETER));
    }

    // A fragment renders inside a page whose context is already set: reading the configuration again would buy nothing
    public function testItLeavesASubRequestOnAFilledContextAlone(): void
    {
        $this->context->setParameter(GalleryRoutePrefix::PARAMETER, 'galerie');

        ($this->createListener('fotos'))($this->createEvent(HttpKernelInterface::SUB_REQUEST));

        $this->assertSame('galerie', $this->context->getParameter(GalleryRoutePrefix::PARAMETER));
    }

    // The error page of a request aborted before the listener ran (a 400 from an early listener) still renders links: its sub-request gets the prefix, or the error turns into a 500
    public function testItFillsASubRequestOnAnEmptyContext(): void
    {
        ($this->createListener('galerie'))($this->createEvent(HttpKernelInterface::SUB_REQUEST));

        $this->assertSame('galerie', $this->context->getParameter(GalleryRoutePrefix::PARAMETER));
    }
}
