<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\ConfigBundle\Contract\UserInterface;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Management\AccountDataProvider;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;
use c975L\PaymentBundle\Entity\Basket;
use PHPUnit\Framework\TestCase;

class AccountDataProviderTest extends TestCase
{
    // Each print order of the account, tied to its basket by number
    public function testExportsThePrintOrdersOfTheAccount(): void
    {
        $order = new GalleryPrintOrder()->setBasket(new Basket()->setNumber('2026-000123'))->setState('shipped');

        $data = $this->provider([$order])->getAccountData($this->createStub(UserInterface::class));

        $this->assertSame('2026-000123', $data['prints'][0]['order']);
        $this->assertSame('shipped', $data['prints'][0]['state']);
        $this->assertSame([], $data['prints'][0]['copies']);
    }

    // No print order, no "prints" key
    public function testNothingWithoutPrintOrders(): void
    {
        $this->assertSame([], $this->provider([])->getAccountData($this->createStub(UserInterface::class)));
    }

    /** @param list<GalleryPrintOrder> $orders */
    private function provider(array $orders): AccountDataProvider
    {
        $repository = $this->createStub(GalleryPrintOrderRepository::class);
        $repository->method('findForUser')->willReturn($orders);

        return new AccountDataProvider($repository);
    }
}
