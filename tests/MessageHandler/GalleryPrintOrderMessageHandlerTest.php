<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\MessageHandler;

use c975L\GalleryBundle\Contract\PrintFulfilmentInterface;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Exception\PrintFulfilmentException;
use c975L\GalleryBundle\Message\GalleryPrintOrderMessage;
use c975L\GalleryBundle\MessageHandler\GalleryPrintOrderMessageHandler;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;
use c975L\GalleryBundle\Service\PrintFulfilmentRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

// The one place a lab is ever handed an order. It runs away from the request that paid, so what it does with a refusal is what a customer's money depends on
class GalleryPrintOrderMessageHandlerTest extends TestCase
{
    public function testAPendingOrderIsSentAndStampedWithTheLabsReference(): void
    {
        $order = new GalleryPrintOrder()->setProvider('prodigi');
        $handler = $this->handler($order, $this->driver('pro-8891'));

        $handler(new GalleryPrintOrderMessage(1));

        $this->assertSame(GalleryPrintOrder::STATE_SENT, $order->getState());
        $this->assertSame('pro-8891', $order->getReference());
        $this->assertNotNull($order->getSentAt());
        $this->assertNull($order->getLastError());
    }

    // A retry after a lab was fixed has to clear what the previous attempt left behind, or the back-office keeps showing an error against an order that went through
    public function testARetriedOrderLosesTheErrorOfItsPreviousAttempt(): void
    {
        $order = new GalleryPrintOrder()
            ->setProvider('prodigi')
            ->setState(GalleryPrintOrder::STATE_FAILED)
            ->setLastError('The lab was down')
        ;
        $handler = $this->handler($order, $this->driver('pro-8891'));

        $handler(new GalleryPrintOrderMessage(1));

        $this->assertSame(GalleryPrintOrder::STATE_SENT, $order->getState());
        $this->assertNull($order->getLastError());
    }

    // Kept rather than rethrown: the customer has paid, and Messenger retrying a lab that refused the file would only have it refused again. What matters is that a human reads why
    public function testALabsRefusalIsWrittenOnTheOrderRatherThanThrown(): void
    {
        $order = new GalleryPrintOrder()->setProvider('manual');
        $driver = $this->createStub(PrintFulfilmentInterface::class);
        $driver->method('createOrder')->willThrowException(new PrintFulfilmentException('Orders are placed by hand with this provider.'));

        $this->handler($order, $driver)(new GalleryPrintOrderMessage(1));

        $this->assertSame(GalleryPrintOrder::STATE_FAILED, $order->getState());
        $this->assertSame('Orders are placed by hand with this provider.', $order->getLastError());
        $this->assertNull($order->getReference());
    }

    // A lab must never be handed the same order twice, whatever the queue redelivers
    public function testAnOrderAlreadySentIsNeverHandedOverAgain(): void
    {
        $order = new GalleryPrintOrder()
            ->setProvider('prodigi')
            ->setState(GalleryPrintOrder::STATE_SENT)
            ->setReference('pro-0001')
        ;

        $this->handler($order, $this->refusingDriver())(new GalleryPrintOrderMessage(1));

        $this->assertSame('pro-0001', $order->getReference());
    }

    // Cancelled while the message waited in the queue - the sale is off, and nothing is printed
    public function testAnOrderCancelledWhileQueuedIsNotSent(): void
    {
        $order = new GalleryPrintOrder()
            ->setProvider('prodigi')
            ->setState(GalleryPrintOrder::STATE_CANCELLED)
        ;

        $this->handler($order, $this->refusingDriver())(new GalleryPrintOrderMessage(1));

        $this->assertSame(GalleryPrintOrder::STATE_CANCELLED, $order->getState());
    }

    // An order deleted since the message was posted: nothing to send, and nothing to fail over - the never() below is the whole assertion
    public function testAnOrderThatIsGoneIsSimplyDropped(): void
    {
        $driver = $this->refusingDriver();

        $this->handler(null, $driver)(new GalleryPrintOrderMessage(1));

        $this->assertInstanceOf(PrintFulfilmentInterface::class, $driver);
    }

    private function handler(?GalleryPrintOrder $order, PrintFulfilmentInterface $driver): GalleryPrintOrderMessageHandler
    {
        $repository = $this->createStub(GalleryPrintOrderRepository::class);
        $repository->method('find')->willReturn($order);

        $registry = $this->createStub(PrintFulfilmentRegistry::class);
        $registry->method('getByName')->willReturn($driver);

        return new GalleryPrintOrderMessageHandler($repository, $registry, $this->createStub(EntityManagerInterface::class));
    }

    private function driver(string $reference): PrintFulfilmentInterface
    {
        $driver = $this->createStub(PrintFulfilmentInterface::class);
        $driver->method('createOrder')->willReturn($reference);

        return $driver;
    }

    // Fails the test by being reached at all: these cases must never get as far as a lab
    private function refusingDriver(): PrintFulfilmentInterface
    {
        $driver = $this->createMock(PrintFulfilmentInterface::class);
        $driver->expects($this->never())->method('createOrder');

        return $driver;
    }
}
