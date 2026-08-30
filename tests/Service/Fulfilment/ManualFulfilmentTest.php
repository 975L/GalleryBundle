<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service\Fulfilment;

use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Exception\PrintFulfilmentException;
use c975L\GalleryBundle\Service\Fulfilment\ManualFulfilment;
use PHPUnit\Framework\TestCase;

// The lab that is a human. Everything here is about refusing to look like one that answers
class ManualFulfilmentTest extends TestCase
{
    public function testItIsNamedAsTheConfigurationNamesIt(): void
    {
        $this->assertSame('manual', new ManualFulfilment()->getName());
    }

    // The one thing that must never be quietly successful: an order reported as sent is an order nobody prints
    public function testCreateOrderRefusesToPretendItSentAnything(): void
    {
        $this->expectException(PrintFulfilmentException::class);

        new ManualFulfilment()->createOrder(new GalleryPrintOrder());
    }

    // Nobody was asked, so nothing moved: the admin walks the order along from the back-office
    public function testAnOrderNobodyAskedAboutIsStillPending(): void
    {
        $this->assertSame(GalleryPrintOrder::STATE_PENDING, new ManualFulfilment()->getState('whatever'));
    }

    public function testAPrinterWithNoApiPostsNoCallback(): void
    {
        $this->assertNull(new ManualFulfilment()->readCallback(['state' => 'shipped']));
    }
}
