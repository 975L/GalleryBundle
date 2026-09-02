<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Controller;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Contract\PrintFulfilmentInterface;
use c975L\GalleryBundle\Controller\PrintCallbackController;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Exception\PrintFulfilmentException;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;
use c975L\GalleryBundle\Service\GalleryPrintOrderTracker;
use c975L\GalleryBundle\Service\PrintFulfilmentRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

// A public url a lab posts to, and which nothing signs. What arrives is read as a name and never as a state - the state is asked of the lab itself
class PrintCallbackControllerTest extends TestCase
{
    public function testWhatTheLabAnswersIsWhatIsApplied(): void
    {
        $order = $this->order();
        $tracker = $this->createMock(GalleryPrintOrderTracker::class);
        $tracker->expects($this->once())->method('apply')->with($order, GalleryPrintOrder::STATE_SHIPPED);

        $response = $this->controller($this->driver(GalleryPrintOrder::STATE_SHIPPED), $order, $tracker)
            ->callback($this->request(), 'prodigi');

        $this->assertSame(200, $response->getStatusCode());
    }

    // A forged post claiming everything has shipped costs this site one question to its own lab, and nothing else
    public function testTheStateCarriedByThePayloadIsNeverBelieved(): void
    {
        $order = $this->order();
        $tracker = $this->createMock(GalleryPrintOrderTracker::class);
        $tracker->expects($this->once())->method('apply')->with($order, GalleryPrintOrder::STATE_PRODUCING);

        $this->controller($this->driver(GalleryPrintOrder::STATE_PRODUCING), $order, $tracker)
            ->callback($this->request(['state' => GalleryPrintOrder::STATE_SHIPPED]), 'prodigi');
    }

    // Labs post more than shipment notices, and the ones a driver ignores are not errors to be replayed
    public function testAPayloadTheDriverDoesNotActOnIsAccepted(): void
    {
        $driver = $this->createStub(PrintFulfilmentInterface::class);
        $driver->method('getName')->willReturn('prodigi');
        $driver->method('readCallback')->willReturn(null);
        $tracker = $this->createMock(GalleryPrintOrderTracker::class);
        $tracker->expects($this->never())->method('apply');

        $response = $this->controller($driver, null, $tracker)->callback($this->request(), 'prodigi');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testACallbackNamingAnOrderThisSiteDoesNotHoldIsAccepted(): void
    {
        $response = $this->controller($this->driver(GalleryPrintOrder::STATE_SHIPPED), null)
            ->callback($this->request(), 'prodigi');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testACallbackForALabNobodyInstalledIsNotFound(): void
    {
        $response = $this->controller($this->driver(GalleryPrintOrder::STATE_SHIPPED), null)
            ->callback($this->request(), 'whoever');

        $this->assertSame(404, $response->getStatusCode());
    }

    // The lab is told to post again, and the nightly synchronisation catches the order either way
    public function testALabThatCannotBeAskedIsToldToPostAgain(): void
    {
        $driver = $this->createStub(PrintFulfilmentInterface::class);
        $driver->method('getName')->willReturn('prodigi');
        $driver->method('readCallback')->willReturn(['reference' => 'pro-8891', 'state' => GalleryPrintOrder::STATE_SHIPPED]);
        $driver->method('getState')->willThrowException(new PrintFulfilmentException('The lab could not be reached'));

        $response = $this->controller($driver, $this->order())->callback($this->request(), 'prodigi');

        $this->assertSame(500, $response->getStatusCode());
    }

    private function order(): GalleryPrintOrder
    {
        return new GalleryPrintOrder()->setProvider('prodigi')->setReference('pro-8891')->setState(GalleryPrintOrder::STATE_SENT);
    }

    private function driver(string $state): PrintFulfilmentInterface
    {
        $driver = $this->createStub(PrintFulfilmentInterface::class);
        $driver->method('getName')->willReturn('prodigi');
        $driver->method('readCallback')->willReturn(['reference' => 'pro-8891', 'state' => $state]);
        $driver->method('getState')->willReturn($state);

        return $driver;
    }

    private function request(array $payload = []): Request
    {
        return new Request([], [], [], [], [], [], json_encode($payload));
    }

    private function controller(PrintFulfilmentInterface $driver, ?GalleryPrintOrder $order, ?GalleryPrintOrderTracker $tracker = null): PrintCallbackController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('prodigi');

        $orderRepository = $this->createStub(GalleryPrintOrderRepository::class);
        $orderRepository->method('findByReference')->willReturn($order);

        return new PrintCallbackController(
            new PrintFulfilmentRegistry([$driver], $configService),
            $orderRepository,
            $tracker ?? $this->createStub(GalleryPrintOrderTracker::class),
            new NullLogger(),
        );
    }
}
