<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Model;

use c975L\GalleryBundle\Model\PrintOffer;
use PHPUnit\Framework\TestCase;

// What a basket line is keyed on. It travels through the checkout as a string and comes back months later on an order, so reading it back has to survive everything a stale basket or a hand-typed request can carry
class PrintOfferTest extends TestCase
{
    public function testIdIsReadBackAsItWasWritten(): void
    {
        $this->assertSame([12, '30x45'], PrintOffer::parseId('12:30x45'));
    }

    // A format slug holding a colon is still one slug: only the first separator counts
    public function testOnlyTheFirstSeparatorSplits(): void
    {
        $this->assertSame([7, 'a:b'], PrintOffer::parseId('7:a:b'));
    }

    public function testIdWithoutSeparatorIsRefused(): void
    {
        $this->assertNull(PrintOffer::parseId('12'));
    }

    public function testNonNumericMediaIsRefused(): void
    {
        $this->assertNull(PrintOffer::parseId('abc:30x45'));
    }

    public function testEmptyFormatIsRefused(): void
    {
        $this->assertNull(PrintOffer::parseId('12:'));
    }

    // PaymentBundle types the id as int|string, and an int alone never names a pair
    public function testIntegerIdIsRefused(): void
    {
        $this->assertNull(PrintOffer::parseId(12));
    }
}
