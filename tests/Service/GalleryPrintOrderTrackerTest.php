<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;
use c975L\GalleryBundle\Service\GalleryPrintEmailInterface;
use c975L\GalleryBundle\Service\GalleryPrintFileBuilder;
use c975L\GalleryBundle\Service\GalleryPrintOrderTracker;
use PHPUnit\Framework\TestCase;

// The one writer of the states a lab reports, whichever way they arrived. What has to happen once - the letter, the files freed - happens here or nowhere
class GalleryPrintOrderTrackerTest extends TestCase
{
    public function testAnOrderTheLabIsPrintingMovesOn(): void
    {
        $order = $this->order(GalleryPrintOrder::STATE_SENT);

        $this->assertTrue($this->tracker()->apply($order, GalleryPrintOrder::STATE_PRODUCING));
        $this->assertSame(GalleryPrintOrder::STATE_PRODUCING, $order->getState());
        $this->assertNull($order->getShippedAt());
    }

    public function testAShippedOrderIsStampedAndItsBuyerToldOnce(): void
    {
        $order = $this->order(GalleryPrintOrder::STATE_PRODUCING);
        $email = $this->createMock(GalleryPrintEmailInterface::class);
        $email->expects($this->once())->method('shipped')->with($order);

        $this->assertTrue($this->tracker($email)->apply($order, GalleryPrintOrder::STATE_SHIPPED));
        $this->assertSame(GalleryPrintOrder::STATE_SHIPPED, $order->getState());
        $this->assertNotNull($order->getShippedAt());
    }

    // The largest files this bundle writes, and nothing will be printed from them again - an order shipped is what says so
    public function testTheFilesOfAnOrderThatIsOverAreFreed(): void
    {
        $copy = new GalleryPrintCopy();
        $order = $this->order(GalleryPrintOrder::STATE_SENT)->addCopy($copy);
        $fileBuilder = $this->createMock(GalleryPrintFileBuilder::class);
        $fileBuilder->expects($this->once())->method('discard')->with($copy);

        $this->tracker(null, $fileBuilder)->apply($order, GalleryPrintOrder::STATE_CANCELLED);
    }

    // A lab that has taken the order but not yet shipped it re-fetches what it was handed, and a file deleted under it would be composed all over again
    public function testTheFilesOfAnOrderTheLabIsStillPrintingAreKept(): void
    {
        $order = $this->order(GalleryPrintOrder::STATE_SENT)->addCopy(new GalleryPrintCopy());
        $fileBuilder = $this->createMock(GalleryPrintFileBuilder::class);
        $fileBuilder->expects($this->never())->method('discard');

        $this->tracker(null, $fileBuilder)->apply($order, GalleryPrintOrder::STATE_PRODUCING);
    }

    // The customer has paid and will receive nothing: the shop is written to, never the buyer, a refund being nobody's decision but the shopkeeper's
    public function testACancelledOrderIsReportedToTheShop(): void
    {
        $order = $this->order(GalleryPrintOrder::STATE_SENT);
        $email = $this->createMock(GalleryPrintEmailInterface::class);
        $email->expects($this->once())->method('cancelled')->with($order);
        $email->expects($this->never())->method('shipped');

        $this->assertTrue($this->tracker($email)->apply($order, GalleryPrintOrder::STATE_CANCELLED));
    }

    // Two callbacks crossing, the older one arriving last: an order whose letter has left must not be reopened
    public function testALabReportingAnOlderStageDoesNotWalkAnOrderBackwards(): void
    {
        $order = $this->order(GalleryPrintOrder::STATE_SHIPPED);
        $email = $this->createMock(GalleryPrintEmailInterface::class);
        $email->expects($this->never())->method('shipped');

        $this->assertFalse($this->tracker($email)->apply($order, GalleryPrintOrder::STATE_PRODUCING));
        $this->assertSame(GalleryPrintOrder::STATE_SHIPPED, $order->getState());
    }

    // An order waiting for a signature, or one the lab refused, is a human's business - nothing a lab says moves it
    public function testAnOrderNoLabIsHoldingIsLeftWhereItIs(): void
    {
        $order = $this->order(GalleryPrintOrder::STATE_PENDING);

        $this->assertFalse($this->tracker()->apply($order, GalleryPrintOrder::STATE_SHIPPED));
        $this->assertSame(GalleryPrintOrder::STATE_PENDING, $order->getState());
    }

    // ManualFulfilment answers "pending" to every question, which has to read as "nothing has changed" rather than as a state to write
    public function testAStateNoLabIsAllowedToReportChangesNothing(): void
    {
        $order = $this->order(GalleryPrintOrder::STATE_SENT);

        $this->assertFalse($this->tracker()->apply($order, GalleryPrintOrder::STATE_PENDING));
        $this->assertSame(GalleryPrintOrder::STATE_SENT, $order->getState());
    }

    // The same shipment reported twice - the lab's callback and the nightly command within the same hour - sends one letter
    public function testTheSameStateReportedTwiceIsNotAppliedTwice(): void
    {
        $order = $this->order(GalleryPrintOrder::STATE_SHIPPED);

        $this->assertFalse($this->tracker()->apply($order, GalleryPrintOrder::STATE_SHIPPED));
    }

    // The callback the lab replayed and the nightly command reading the same order at the same moment: whoever loses the row writes nothing and tells nobody
    public function testAnOrderAnotherProcessHasAlreadyMovedIsLeftAlone(): void
    {
        $order = $this->order(GalleryPrintOrder::STATE_PRODUCING);
        $email = $this->createMock(GalleryPrintEmailInterface::class);
        $email->expects($this->never())->method('shipped');

        $this->assertFalse($this->tracker($email, null, false)->apply($order, GalleryPrintOrder::STATE_SHIPPED));
        $this->assertSame(GalleryPrintOrder::STATE_PRODUCING, $order->getState());
    }

    private function order(string $state): GalleryPrintOrder
    {
        return new GalleryPrintOrder()->setProvider('prodigi')->setReference('pro-8891')->setState($state);
    }

    private function tracker(?GalleryPrintEmailInterface $email = null, ?GalleryPrintFileBuilder $fileBuilder = null, bool $claimed = true): GalleryPrintOrderTracker
    {
        $orderRepository = $this->createStub(GalleryPrintOrderRepository::class);
        $orderRepository->method('claim')->willReturn($claimed);

        return new GalleryPrintOrderTracker(
            $email ?? $this->createStub(GalleryPrintEmailInterface::class),
            $fileBuilder ?? $this->createStub(GalleryPrintFileBuilder::class),
            $orderRepository,
        );
    }
}
