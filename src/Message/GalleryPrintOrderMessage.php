<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Message;

// Asks for an order to be handed to the lab. Carries an id and not the order, a message outliving the request that wrote it - and asynchronous because the lab is a third party whose slowness must never reach the payment provider's webhook, which retries what it thinks timed out
readonly class GalleryPrintOrderMessage
{
    public function __construct(public int $orderId)
    {
    }
}
