<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service\Fulfilment;

use c975L\GalleryBundle\Contract\PrintFulfilmentInterface;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Exception\PrintFulfilmentException;

/**
 * No lab at all - the order waits in the back-office with its print file ready, and a human places it.
 *
 * Two situations, neither of them a fallback nobody wants. A shop that has its own printer down the road and no wish
 * for an api; and the day the configured lab is unreachable, where an order already paid for must not be lost because a
 * third party is down.
 *
 * Refuses to pretend it sent anything: createOrder() throws, which is exactly what leaves the order pending and in
 * front of the admin. Saying "sent" here would be the one lie that loses a print.
 */
class ManualFulfilment implements PrintFulfilmentInterface
{
    public function getName(): string
    {
        return 'manual';
    }

    public function createOrder(GalleryPrintOrder $order): string
    {
        throw new PrintFulfilmentException('Orders are placed by hand with this provider.');
    }

    // Nobody is asked, so nothing has changed: the admin moves the order along in the back-office as the printer reports
    public function getState(string $reference): string
    {
        return GalleryPrintOrder::STATE_PENDING;
    }

    // A printer with no api posts no callbacks
    public function readCallback(array $payload): ?array
    {
        return null;
    }
}
