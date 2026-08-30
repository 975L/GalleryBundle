<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\MessageHandler;

use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Exception\PrintFulfilmentException;
use c975L\GalleryBundle\Message\GalleryPrintOrderMessage;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;
use c975L\GalleryBundle\Service\PrintFulfilmentRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// Hands one order to its lab, away from the request that paid for it. The only place createOrder() is called from, whether the order left on its own or an admin released it after signing a certificate
#[AsMessageHandler]
class GalleryPrintOrderMessageHandler
{
    public function __construct(
        private readonly GalleryPrintOrderRepository $orderRepository,
        private readonly PrintFulfilmentRegistry $fulfilmentRegistry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(GalleryPrintOrderMessage $message): void
    {
        $order = $this->orderRepository->find($message->orderId);

        // Already sent, already shipped, or cancelled while the message waited in the queue - a lab must never be handed the same order twice
        if (null === $order || !$order->needsAttention()) {
            return;
        }

        try {
            $driver = $this->fulfilmentRegistry->getByName((string) $order->getProvider());

            $order
                ->setReference($driver->createOrder($order))
                ->setState(GalleryPrintOrder::STATE_SENT)
                ->setSentAt(new \DateTimeImmutable())
                ->setLastError(null)
            ;
        } catch (PrintFulfilmentException | \InvalidArgumentException $exception) {
            // Kept rather than rethrown: the customer has paid, so what matters is that a human sees why this did not leave. Messenger retrying a lab that refused the file would only refuse it again
            $order
                ->setState(GalleryPrintOrder::STATE_FAILED)
                ->setLastError($exception->getMessage())
            ;
        }

        $this->entityManager->flush();
    }
}
