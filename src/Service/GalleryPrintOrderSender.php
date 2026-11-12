<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Message\GalleryPrintOrderMessage;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

// Holds what a paid order sends - the edition's letters and the message to the lab - until the payment's transaction is committed (see PaymentBundle's BasketService::deliver), then sends it once; an order the rollback took away is dropped
#[AsDoctrineListener(event: Events::postFlush)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'release')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'release')]
#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'release')]
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'release')]
class GalleryPrintOrderSender implements ResetInterface
{
    /** @var array<int, bool> order id => whether it holds a numbered copy */
    private array $pending = [];

    public function __construct(
        private readonly GalleryPrintOrderRepository $orderRepository,
        private readonly GalleryPrintEmailInterface $printEmail,
        private readonly ConfigServiceInterface $configService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    // Queues a paid order, told whether it holds a numbered copy: the copies were claimed by SQL, so the order's own collection cannot say it yet
    public function defer(int $orderId, bool $hasLimitedEdition): void
    {
        $this->pending[$orderId] = $hasLimitedEdition;
        $this->release();
    }

    // Each flush outside a transaction, and the end of the request, command or message, sends what is queued
    public function postFlush(): void
    {
        $this->release();
    }

    // Sends the queued orders once no transaction is open any more, each read back first so a rolled-back one sends nothing
    public function release(): void
    {
        if ([] === $this->pending || !$this->entityManager->isOpen() || $this->entityManager->getConnection()->isTransactionActive()) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $orderId => $hasLimitedEdition) {
            $order = $this->orderRepository->find($orderId);
            if (null !== $order) {
                $this->send($order, $hasLimitedEdition);
            }
        }
    }

    // An art edition is announced and, while the shop signs its certificates, waits for the admin to release it; anything else goes to the lab
    private function send(GalleryPrintOrder $order, bool $hasLimitedEdition): void
    {
        if ($hasLimitedEdition) {
            $this->printEmail->editionSold($order);
        }

        // A shop that issues its certificates on its own turns "gallery-print-edition-hold" off, and the print leaves like any other
        if ($hasLimitedEdition && false !== $this->configService->get('gallery-print-edition-hold')) {
            $this->printEmail->editionAwaitingSignature($order);

            return;
        }

        $this->messageBus->dispatch(new GalleryPrintOrderMessage((int) $order->getId()));
    }

    // Nothing carried from one request to the next in a worker runtime
    public function reset(): void
    {
        $this->pending = [];
    }
}
