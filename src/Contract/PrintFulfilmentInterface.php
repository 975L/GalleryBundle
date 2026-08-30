<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Contract;

use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Exception\PrintFulfilmentException;

/**
 * One lab, and only what talking to it requires. Everything a print order means - the formats, the prices, the edition
 * and its register, the certificate - is the bundle's own business and never reaches here: a driver receives an order
 * that is already priced and already numbered, and answers what the lab said about it.
 *
 * A site printing somewhere this ecosystem never heard of writes a class implementing this, tags it, and names it in
 * "gallery-print-provider". Nothing else is overridden.
 */
interface PrintFulfilmentInterface
{
    // The name this driver answers to in "gallery-print-provider", lowercase and stable - it is stored in configuration and in every order this driver sent
    public function getName(): string;

    /**
     * Hands the order to the lab and returns the reference it gave back, which is what every later exchange about that
     * order is keyed on.
     *
     * Called from a message handler and never from the payment webhook, so it may take its time and may throw: a
     * failure leaves the order where it was, to be sent again or passed by hand (see GalleryPrintOrder::STATE_PENDING).
     *
     * @throws PrintFulfilmentException when the lab refused the order or could not be reached
     */
    public function createOrder(GalleryPrintOrder $order): string;

    /**
     * What the lab currently says about an order it accepted, as one of GalleryPrintOrder::STATE_*.
     *
     * Only ever asked for an order the lab acknowledged, so an unknown reference is an error and not a null.
     *
     * @throws PrintFulfilmentException when the lab could not be reached or does not know that reference
     */
    public function getState(string $reference): string;

    /**
     * Reads a callback the lab posted and says which order it concerns and where it now stands, or null when the
     * payload is not one this driver acts on - labs post more than shipment notices, and the ones we ignore are not
     * errors.
     *
     * Whoever calls this has already checked the request is genuinely from the lab; a driver whose lab signs its
     * callbacks verifies that signature here and throws when it does not match.
     *
     * @param array<string, mixed> $payload the decoded callback body
     *
     * @return array{reference: string, state: string}|null
     *
     * @throws PrintFulfilmentException when the payload claims to be signed and the signature does not hold
     */
    public function readCallback(array $payload): ?array;
}
