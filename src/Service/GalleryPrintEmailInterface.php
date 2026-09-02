<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Entity\GalleryPrintOrder;

/**
 * The letters a print order sends that a basket cannot.
 *
 * PaymentBundle already tells the customer what they bought and what it cost. What it knows nothing of is the edition:
 * which numbers were claimed, where the certificate can be verified, and that somebody has to sign one before it ships.
 * The shipping notice is here for a different reason: PaymentBundle sends one, but only for the item kinds its own
 * back-office marks as shipped, and a print leaves without anybody clicking anything - the lab says so itself.
 */
interface GalleryPrintEmailInterface
{
    // Tells the buyer of a limited edition which copies are theirs and where each certificate can be checked. Only ever sent for numbered copies - there is nothing to say about an open edition that the order confirmation has not said
    public function editionSold(GalleryPrintOrder $order): void;

    // Tells the shop an art edition is waiting: a certificate to sign, a copy to number, and the order to release to the lab once it is done
    public function editionAwaitingSignature(GalleryPrintOrder $order): void;

    // Tells the buyer their prints have left the lab, whether the lab said so through a callback or when the nightly command asked (see GalleryPrintOrderTracker)
    public function shipped(GalleryPrintOrder $order): void;

    // Tells the shop a lab cancelled an order it had accepted: the customer has paid and will receive nothing, and what happens next - a refund, or the same prints ordered elsewhere - is a decision nobody but the shopkeeper makes
    public function cancelled(GalleryPrintOrder $order): void;
}
