<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Entity;

use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use PHPUnit\Framework\TestCase;

// One consignment to a lab. What it answers decides two things nothing else does: whether a human still has to look at it, and whether it may leave before a certificate is signed
class GalleryPrintOrderTest extends TestCase
{
    public function testAFreshOrderIsPending(): void
    {
        $this->assertSame(GalleryPrintOrder::STATE_PENDING, new GalleryPrintOrder()->getState());
    }

    // Pending and failed are the two a human is expected to act on, and the two the handler will still hand to a lab
    public function testOnlyPendingAndFailedNeedAttention(): void
    {
        $this->assertTrue($this->orderIn(GalleryPrintOrder::STATE_PENDING)->needsAttention());
        $this->assertTrue($this->orderIn(GalleryPrintOrder::STATE_FAILED)->needsAttention());
    }

    // The guard against sending one order twice: everything already gone, shipped or called off is left alone
    public function testEverySettledStateIsLeftAlone(): void
    {
        foreach ([GalleryPrintOrder::STATE_SENT, GalleryPrintOrder::STATE_PRODUCING, GalleryPrintOrder::STATE_SHIPPED, GalleryPrintOrder::STATE_CANCELLED] as $state) {
            $this->assertFalse($this->orderIn($state)->needsAttention(), $state);
        }
    }

    public function testAnOrderOfOpenEditionsCarriesNoNumberedCopy(): void
    {
        $order = new GalleryPrintOrder()->addCopy(new GalleryPrintCopy());

        $this->assertFalse($order->hasLimitedEdition());
    }

    // One numbered copy is enough to hold the whole consignment back: its certificate is signed by hand before anything ships
    public function testASingleNumberedCopyMakesTheWholeOrderALimitedOne(): void
    {
        $order = new GalleryPrintOrder()
            ->addCopy(new GalleryPrintCopy())
            ->addCopy(new GalleryPrintCopy()->setNumber(3))
        ;

        $this->assertTrue($order->hasLimitedEdition());
    }

    public function testAddingACopyPointsItBackAtTheOrder(): void
    {
        $copy = new GalleryPrintCopy();
        $order = new GalleryPrintOrder()->addCopy($copy);

        $this->assertSame($order, $copy->getOrder());
        $this->assertCount(1, $order->getCopies());
    }

    public function testTheSameCopyIsNeverAddedTwice(): void
    {
        $copy = new GalleryPrintCopy();
        $order = new GalleryPrintOrder()->addCopy($copy)->addCopy($copy);

        $this->assertCount(1, $order->getCopies());
    }

    private function orderIn(string $state): GalleryPrintOrder
    {
        return new GalleryPrintOrder()->setState($state);
    }
}
