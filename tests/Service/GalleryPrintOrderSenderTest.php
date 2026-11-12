<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Message\GalleryPrintOrderMessage;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;
use c975L\GalleryBundle\Service\GalleryPrintEmailInterface;
use c975L\GalleryBundle\Service\GalleryPrintOrderSender;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

// What a paid order sends waits for the payment's commit, goes out once, and never goes out for an order the rollback took away
class GalleryPrintOrderSenderTest extends TestCase
{
    // Nothing leaves while the payment's transaction is open, and the order goes to the lab once it is committed
    public function testAnOrderIsSentOnlyOnceTheTransactionIsOver(): void
    {
        $inTransaction = true;

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(GalleryPrintOrderMessage::class))
            ->willReturn(new Envelope(new \stdClass()))
        ;

        $sender = $this->createSender($messageBus, $this->createStub(GalleryPrintEmailInterface::class), new GalleryPrintOrder(), $inTransaction);

        $sender->defer(7, false);
        $sender->postFlush();

        $inTransaction = false;
        $sender->release();
        // Sent once: a later flush or the end of the request finds nothing left
        $sender->release();
    }

    // A rolled-back payment leaves no order to read back, and nothing is sent for it
    public function testAnOrderTheRollbackTookAwayIsDropped(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $printEmail = $this->createMock(GalleryPrintEmailInterface::class);
        $printEmail->expects($this->never())->method('editionSold');

        $inTransaction = false;
        $this->createSender($messageBus, $printEmail, null, $inTransaction)->defer(7, true);
    }

    // A numbered edition is announced and waits for its certificate while the hold is on
    public function testANumberedEditionIsAnnouncedAndHeldForItsCertificate(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $printEmail = $this->createMock(GalleryPrintEmailInterface::class);
        $printEmail->expects($this->once())->method('editionSold');
        $printEmail->expects($this->once())->method('editionAwaitingSignature');

        $inTransaction = false;
        $this->createSender($messageBus, $printEmail, new GalleryPrintOrder(), $inTransaction)->defer(7, true);
    }

    // A shop that issues its certificates on its own turns the hold off, and the numbered print leaves for the lab once announced
    public function testANumberedEditionGoesToTheLabWhenTheHoldIsOff(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $printEmail = $this->createMock(GalleryPrintEmailInterface::class);
        $printEmail->expects($this->once())->method('editionSold');
        $printEmail->expects($this->never())->method('editionAwaitingSignature');

        $inTransaction = false;
        $this->createSender($messageBus, $printEmail, new GalleryPrintOrder(), $inTransaction, false)->defer(7, true);
    }

    // A sender over an order repository answering $order, and a connection whose transaction state follows $inTransaction
    private function createSender(MessageBusInterface $messageBus, GalleryPrintEmailInterface $printEmail, ?GalleryPrintOrder $order, bool &$inTransaction, bool $hold = true): GalleryPrintOrderSender
    {
        $orderRepository = $this->createStub(GalleryPrintOrderRepository::class);
        $orderRepository->method('find')->willReturn($order);

        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$inTransaction): bool {
            return $inTransaction;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->method('getConnection')->willReturn($connection);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($hold);

        return new GalleryPrintOrderSender($orderRepository, $printEmail, $configService, $entityManager, $messageBus);
    }
}
