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
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;

/**
 * Moves an order along as the lab reports it, and does what each state calls for.
 *
 * The one writer of the states that come back from a lab, whichever way they arrived - a callback the lab posted or the
 * nightly command that asks. Everything that has to happen once is here rather than in either caller: the shipping
 * letter goes out once, and the print files are freed once.
 */
class GalleryPrintOrderTracker
{
    // What a lab is allowed to report. Anything else it says about an order - "pending", or a stage a driver mapped badly - leaves the order where it is
    private const array REPORTABLE = [
        GalleryPrintOrder::STATE_PRODUCING,
        GalleryPrintOrder::STATE_SHIPPED,
        GalleryPrintOrder::STATE_CANCELLED,
    ];

    public function __construct(
        private readonly GalleryPrintEmailInterface $printEmail,
        private readonly GalleryPrintFileBuilder $fileBuilder,
        private readonly GalleryPrintOrderRepository $orderRepository,
    ) {
    }

    /**
     * Applies what the lab says about an order, and answers whether it moved.
     *
     * Refuses to walk anything backwards: a lab that reports "in production" after having shipped - which happens when
     * two callbacks cross - must not reopen an order whose letter has already left.
     */
    public function apply(GalleryPrintOrder $order, string $state): bool
    {
        if ($state === $order->getState() || !\in_array($state, self::REPORTABLE, true) || !\in_array($order->getState(), GalleryPrintOrder::STATES_HELD_BY_LAB, true)) {
            return false;
        }

        $shippedAt = GalleryPrintOrder::STATE_SHIPPED === $state ? new \DateTimeImmutable() : null;

        // The row and not the state read a moment ago decides: a callback the lab replayed at the same time as the nightly command passes the guard above too, and the letter must leave once
        if (!$this->orderRepository->claim($order, $state, $shippedAt)) {
            return false;
        }

        $order->setState($state)->setShippedAt($shippedAt);

        // Written before anything else is done with it: what follows only tidies up, and a letter that fails to leave must not lose the state the lab reported
        if (GalleryPrintOrder::STATE_SHIPPED === $state) {
            $this->printEmail->shipped($order);
        }

        // The shop and not the buyer: the customer has paid and will receive nothing, and whether that becomes a refund or the same prints ordered elsewhere is the shopkeeper's call
        if (GalleryPrintOrder::STATE_CANCELLED === $state) {
            $this->printEmail->cancelled($order);
        }

        // Only once nothing will be printed from them again: they are the largest files this bundle writes, and a lab still producing an order does re-fetch what it was handed
        if (GalleryPrintOrder::STATE_SHIPPED === $state || GalleryPrintOrder::STATE_CANCELLED === $state) {
            $this->discardFiles($order);
        }

        return true;
    }

    private function discardFiles(GalleryPrintOrder $order): void
    {
        foreach ($order->getCopies() as $copy) {
            $this->fileBuilder->discard($copy);
        }
    }
}
