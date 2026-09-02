<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Command;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Command\GalleryPrintSyncCommand;
use c975L\GalleryBundle\Contract\PrintFulfilmentInterface;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Exception\PrintFulfilmentException;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;
use c975L\GalleryBundle\Service\GalleryPrintOrderTracker;
use c975L\GalleryBundle\Service\PrintFulfilmentRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

// What makes the labs' callbacks optional: one lost request must not leave an order reading "sent" for ever
class GalleryPrintSyncCommandTest extends TestCase
{
    public function testWhatTheLabAnswersGoesThroughTheTracker(): void
    {
        $order = $this->order();
        $tracker = $this->createMock(GalleryPrintOrderTracker::class);
        $tracker->expects($this->once())->method('apply')->with($order, GalleryPrintOrder::STATE_SHIPPED)->willReturn(true);

        $tester = $this->tester([$order], $this->driver(GalleryPrintOrder::STATE_SHIPPED), $tracker);
        $tester->execute([]);

        $this->assertStringContainsString('1 order(s) moved', $tester->getDisplay());
    }

    // An unreachable lab is not a refusal: the order keeps its state and is asked again tomorrow
    public function testALabThatCannotBeReachedLeavesItsOrdersUntouched(): void
    {
        $order = $this->order();
        $driver = $this->createStub(PrintFulfilmentInterface::class);
        $driver->method('getName')->willReturn('prodigi');
        $driver->method('getState')->willThrowException(new PrintFulfilmentException('The lab could not be reached'));
        $tracker = $this->createMock(GalleryPrintOrderTracker::class);
        $tracker->expects($this->never())->method('apply');

        $tester = $this->tester([$order], $driver, $tracker);

        $this->assertSame(0, $tester->execute([]));
        $this->assertSame(GalleryPrintOrder::STATE_SENT, $order->getState());
        $this->assertStringContainsString('1 lab(s) could not be asked', $tester->getDisplay());
    }

    // A site that changed lab still holds orders sent through the previous one, whose driver may have been uninstalled with it
    public function testAnOrderSentThroughALabNoLongerInstalledIsReported(): void
    {
        $order = $this->order()->setProvider('gone');
        $tester = $this->tester([$order], $this->driver(GalleryPrintOrder::STATE_SHIPPED));

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('1 lab(s) could not be asked', $tester->getDisplay());
    }

    private function order(): GalleryPrintOrder
    {
        return new GalleryPrintOrder()->setProvider('prodigi')->setReference('pro-8891')->setState(GalleryPrintOrder::STATE_SENT);
    }

    private function driver(string $state): PrintFulfilmentInterface
    {
        $driver = $this->createStub(PrintFulfilmentInterface::class);
        $driver->method('getName')->willReturn('prodigi');
        $driver->method('getState')->willReturn($state);

        return $driver;
    }

    private function tester(array $orders, PrintFulfilmentInterface $driver, ?GalleryPrintOrderTracker $tracker = null): CommandTester
    {
        $orderRepository = $this->createStub(GalleryPrintOrderRepository::class);
        $orderRepository->method('findTracked')->willReturn($orders);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('prodigi');

        return new CommandTester(new GalleryPrintSyncCommand(
            $orderRepository,
            new PrintFulfilmentRegistry([$driver], $configService),
            $tracker ?? $this->createStub(GalleryPrintOrderTracker::class),
        ));
    }
}
