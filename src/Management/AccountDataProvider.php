<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Account\AccountDataProviderInterface;
use c975L\ConfigBundle\Contract\UserInterface;
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;

// The prints part of a member's data export (ConfigBundle's /account/export): each print order of the account's baskets, its lab state and the copies it holds
class AccountDataProvider implements AccountDataProviderInterface
{
    public function __construct(private readonly GalleryPrintOrderRepository $printOrderRepository)
    {
    }

    // Under "prints", newest first
    public function getAccountData(UserInterface $user): array
    {
        $orders = $this->printOrderRepository->findForUser($user);

        return [] === $orders ? [] : ['prints' => array_map($this->order(...), $orders)];
    }

    // One print order as the member reads it, the basket number linking it to the "orders" part
    /** @return array<string, mixed> */
    private function order(GalleryPrintOrder $order): array
    {
        return [
            'order' => $order->getBasket()?->getNumber(),
            'state' => $order->getState(),
            'date' => $order->getCreatedAt(),
            'sent' => $order->getSentAt(),
            'shipped' => $order->getShippedAt(),
            'copies' => array_map(static fn (GalleryPrintCopy $copy): array => [
                'work' => $copy->getWorkTitle(),
                'format' => $copy->getFormatLabel(),
                'number' => $copy->getNumber(),
            ], array_values($order->getCopies()->toArray())),
        ];
    }
}
