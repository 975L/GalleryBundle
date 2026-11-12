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
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Model\PrintOffer;
use c975L\GalleryBundle\Repository\GalleryPrintCopyRepository;
use c975L\GalleryBundle\Repository\GalleryPrintFormatRepository;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;
use c975L\GalleryBundle\Service\GalleryPrintBasketItemProvider;
use c975L\GalleryBundle\Service\GalleryPrintOrderSender;
use c975L\GalleryBundle\Service\GalleryPrintService;
use c975L\GalleryBundle\Service\PrintFulfilmentRegistry;
use c975L\PaymentBundle\Entity\Basket;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// What a paid basket writes, and what it then triggers - the certificate of a numbered edition is signed by hand, so its order waits where an open one goes straight to the lab
class GalleryPrintBasketItemProviderTest extends TestCase
{
    private function createOffer(bool $limited): PrintOffer
    {
        $media = new GalleryMedia()->setTitle('Mont Blanc');
        $media->setEditionSize($limited ? 30 : null);

        $format = new GalleryPrintFormat()->setSlug('30x40')->setLabel('30 x 40 cm')->setPrice(12000);

        return new PrintOffer($media, $format);
    }

    // A site running the gallery without a shop still has somewhere to send a customer back to: its own galleries
    public function testTheCatalogueIsTheGalleryIndex(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('gallery_index')
            ->willReturn('/galerie');

        $provider = $this->createProvider($this->createOffer(false), ['urlGenerator' => $urlGenerator]);

        $this->assertSame('/galerie', $provider->getCatalogueUrl());
    }

    // Everything a basket row is drawn with, none of which PaymentBundle can work out on its own: without the id the page cannot even be rendered, and two sizes of one photograph read as two identical lines without the size
    public function testABasketRowIsHandedEverythingItIsDrawnWith(): void
    {
        $offer = $this->createOffer(false);
        $offer->media->setSlug('mont-blanc')->setCategory(new GalleryCategory()->setTitle('Montagnes')->setSlug('montagnes'));

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.org/galerie/montagnes/mont-blanc');

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('eur');

        $line = $this->createProvider($offer, ['urlGenerator' => $urlGenerator, 'configService' => $configService])->toBasketData($offer, 2);

        $this->assertSame('0:30x40', $line['item']['id']);
        $this->assertSame('30 x 40 cm', $line['item']['description']);
        $this->assertSame('eur', $line['item']['currency']);
        $this->assertArrayHasKey('media', $line['item']);
        // The page the photograph is read on, which "gallery_print_display" - the route the row falls back on - never was
        $this->assertSame('https://example.org/galerie/montagnes/mont-blanc', $line['parent']['url']);
    }

    // What stops the quantity buttons short of the edition: how many numbers were ever offered, and how many are already claimed
    public function testALimitedEditionSaysWhatIsLeftOfItToTheQuantityButtons(): void
    {
        $offer = $this->createOffer(true);

        $printService = $this->createStub(GalleryPrintService::class);
        $printService->method('findOffer')->willReturn($offer);
        $printService->method('getRemaining')->willReturn(4);

        $line = $this->createProvider($offer, ['printService' => $printService])->toBasketData($offer, 1);

        $this->assertSame(30, $line['item']['limitedQuantity']);
        $this->assertSame(26, $line['item']['orderedQuantity']);
    }

    // An open edition is never short of anything, and a zero is what the buttons read as "no limit at all"
    public function testAnOpenEditionNeverStopsTheQuantityButtons(): void
    {
        $line = $this->createProvider($this->createOffer(false))->toBasketData($this->createOffer(false), 1);

        $this->assertSame(0, $line['item']['limitedQuantity']);
        $this->assertSame(0, $line['item']['orderedQuantity']);
    }

    // A photograph filed nowhere has no page to be sent to, and a row saying so is drawn as its title alone
    public function testAPhotographWithoutAGalleryIsSentNowhere(): void
    {
        $line = $this->createProvider($this->createOffer(false))->toBasketData($this->createOffer(false), 1);

        $this->assertNull($line['parent']['url']);
    }

    // The print switch and the catalogue's size, each state named so a failure says which one broke
    /** @return iterable<string, array{bool, int, bool}> */
    public static function provideShipsParcelsStates(): iterable
    {
        yield 'on with formats' => [true, 3, true];
        yield 'off with formats' => [false, 3, false];
        yield 'on without formats' => [true, 0, false];
    }

    // Prints are declared as posted only while the print service is switched on and has a format to sell
    #[DataProvider('provideShipsParcelsStates')]
    public function testPrintsShipParcelsOnlyWhileThePrintServiceCanSellOne(bool $enabled, int $formats, bool $expected): void
    {
        $printService = $this->createStub(GalleryPrintService::class);
        $printService->method('isEnabled')->willReturn($enabled);

        $formatRepository = $this->createStub(GalleryPrintFormatRepository::class);
        $formatRepository->method('count')->willReturn($formats);

        $provider = $this->createProvider($this->createOffer(false), ['printService' => $printService, 'formatRepository' => $formatRepository]);

        $this->assertSame($expected, $provider->shipsParcels());
    }

    // The format's weight is frozen into the line, then taken as many times as it was ordered; a format nobody weighed, or a line snapshotted before formats were, weighs nothing
    public function testALineWeighsItsFormatTimesItsQuantity(): void
    {
        $offer = $this->createOffer(false);
        $offer->format->setWeight(250);
        $provider = $this->createProvider($offer);

        $this->assertSame(750, $provider->getWeight($provider->toBasketData($offer, 3)));
        $this->assertNull($provider->getWeight(['item' => ['weight' => null], 'quantity' => 3]));
        $this->assertNull($provider->getWeight(['item' => [], 'quantity' => 3]));
    }

    /** @param array<string, mixed> $services */
    private function createProvider(PrintOffer $offer, array $services = []): GalleryPrintBasketItemProvider
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        // The given collaborators over the defaults, rather than a "??" per argument: the defaults are the ones that need arranging, and only where nothing was handed over
        $services += $this->defaultServices($offer);

        return new GalleryPrintBasketItemProvider(
            $services['printService'],
            $services['copyRepository'],
            $services['formatRepository'],
            $services['orderRepository'],
            $this->createStub(PrintFulfilmentRegistry::class),
            $services['orderSender'],
            $services['configService'],
            $services['entityManager'],
            $translator,
            $services['urlGenerator'],
            $this->createStub(LoggerInterface::class),
        );
    }

    /** @return array<string, mixed> */
    private function defaultServices(PrintOffer $offer): array
    {
        $printService = $this->createStub(GalleryPrintService::class);
        $printService->method('findOffer')->willReturn($offer);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('Galerie');

        return [
            'printService' => $printService,
            'copyRepository' => $this->createStub(GalleryPrintCopyRepository::class),
            'formatRepository' => $this->createStub(GalleryPrintFormatRepository::class),
            'orderRepository' => $this->createStub(GalleryPrintOrderRepository::class),
            'orderSender' => $this->createStub(GalleryPrintOrderSender::class),
            'configService' => $configService,
            'entityManager' => $this->createStub(EntityManagerInterface::class),
            'urlGenerator' => $this->createStub(UrlGeneratorInterface::class),
        ];
    }

    // The copy is claimed by an UPDATE and the order's own collection stays empty until it is read back, so the provider tells the sender itself that the order holds a numbered copy - asking the order answered no on every sale, and the print went to the lab unsigned
    public function testAPaidNumberedEditionIsQueuedAsALimitedEdition(): void
    {
        $offer = $this->createOffer(true);

        $copyRepository = $this->createStub(GalleryPrintCopyRepository::class);
        $copyRepository->method('claimNumber')->willReturn(new GalleryPrintCopy()->setNumber(1));

        $orderSender = $this->createMock(GalleryPrintOrderSender::class);
        $orderSender->expects($this->once())->method('defer')->with($this->anything(), true);

        $this->createProvider($offer, [
            'copyRepository' => $copyRepository,
            'orderSender' => $orderSender,
        ])->onBasketPaid(new Basket(), [$offer->getId() => ['quantity' => 1]], []);
    }

    // An open edition has nothing to sign, so it is queued as an ordinary print
    public function testAPaidOpenEditionIsQueuedAsAnOrdinaryPrint(): void
    {
        $offer = $this->createOffer(false);

        $orderSender = $this->createMock(GalleryPrintOrderSender::class);
        $orderSender->expects($this->once())->method('defer')->with($this->anything(), false);

        $this->createProvider($offer, ['orderSender' => $orderSender])
            ->onBasketPaid(new Basket(), [$offer->getId() => ['quantity' => 1]], []);
    }

    // The race validateCheckout() cannot close: the edition sold out between the checkout and the payment. The customer has paid, so the order is kept and flagged rather than lost - and nothing is queued
    public function testAnEditionSoldOutAfterPaymentQueuesNothing(): void
    {
        $offer = $this->createOffer(true);

        $copyRepository = $this->createStub(GalleryPrintCopyRepository::class);
        $copyRepository->method('claimNumber')->willReturn(null);

        $orderSender = $this->createMock(GalleryPrintOrderSender::class);
        $orderSender->expects($this->never())->method('defer');

        $this->createProvider($offer, [
            'copyRepository' => $copyRepository,
            'orderSender' => $orderSender,
        ])->onBasketPaid(new Basket(), [$offer->getId() => ['quantity' => 1]], []);
    }

    // A print withdrawn between the checkout and the payment used to be skipped in silence: the order is now flagged for a human and nothing is queued
    public function testAPrintWithdrawnBeforeThePaymentFlagsTheOrder(): void
    {
        $offer = $this->createOffer(false);

        $printService = $this->createStub(GalleryPrintService::class);
        $printService->method('findOffer')->willReturn(null);

        $orderSender = $this->createMock(GalleryPrintOrderSender::class);
        $orderSender->expects($this->never())->method('defer');

        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createProvider($offer, [
            'printService' => $printService,
            'orderSender' => $orderSender,
            'entityManager' => $entityManager,
        ])->onBasketPaid(new Basket(), [$offer->getId() => ['quantity' => 1]], []);

        $this->assertInstanceOf(GalleryPrintOrder::class, $persisted[0]);
        $this->assertSame('label.print_unavailable_after_payment', $persisted[0]->getLastError());
    }

    // A rehearsal burns no number, sends no letter and orders nothing from the lab: the order is written cancelled, as the trace of the checkout tried
    public function testATestBasketProducesNothingReal(): void
    {
        $offer = $this->createOffer(true);

        $copyRepository = $this->createMock(GalleryPrintCopyRepository::class);
        $copyRepository->expects($this->never())->method('claimNumber');

        $orderSender = $this->createMock(GalleryPrintOrderSender::class);
        $orderSender->expects($this->never())->method('defer');

        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->createProvider($offer, [
            'copyRepository' => $copyRepository,
            'orderSender' => $orderSender,
            'entityManager' => $entityManager,
        ])->onBasketPaid(new Basket()->setTestMode(true), [$offer->getId() => ['quantity' => 1]], []);

        $this->assertCount(1, $persisted);
        $this->assertSame(GalleryPrintOrder::STATE_CANCELLED, $persisted[0]->getState());
        $this->assertSame('label.print_order_test_mode', $persisted[0]->getLastError());
    }

    // A refund before the lab was told cancels the order and hands its numbers back to the edition
    public function testARefundCancelsAnOrderThatNeverLeftAndFreesItsNumbers(): void
    {
        $order = new GalleryPrintOrder();

        $orderRepository = $this->createStub(GalleryPrintOrderRepository::class);
        $orderRepository->method('findBy')->willReturn([$order]);

        $copyRepository = $this->createMock(GalleryPrintCopyRepository::class);
        $copyRepository->expects($this->once())->method('releaseNumbers')->with($order)->willReturn(1);

        $this->createProvider($this->createOffer(true), [
            'orderRepository' => $orderRepository,
            'copyRepository' => $copyRepository,
        ])->onBasketRefunded(new Basket(), []);

        $this->assertSame(GalleryPrintOrder::STATE_CANCELLED, $order->getState());
        $this->assertSame('label.print_order_refunded', $order->getLastError());
    }

    // A print the lab already holds is not the bundle's to stop: its numbers stay sold, and the order is flagged for a cancellation by hand
    #[DataProvider('provideStatesPastTheLab')]
    public function testARefundAfterTheLabFlagsTheOrderAndKeepsItsNumbers(string $state): void
    {
        $order = new GalleryPrintOrder()->setState($state);

        $orderRepository = $this->createStub(GalleryPrintOrderRepository::class);
        $orderRepository->method('findBy')->willReturn([$order]);

        $copyRepository = $this->createMock(GalleryPrintCopyRepository::class);
        $copyRepository->expects($this->never())->method('releaseNumbers');

        $this->createProvider($this->createOffer(true), [
            'orderRepository' => $orderRepository,
            'copyRepository' => $copyRepository,
        ])->onBasketRefunded(new Basket(), []);

        $this->assertSame($state, $order->getState());
        $this->assertSame('label.print_order_refunded_at_lab', $order->getLastError());
    }

    // Every state an order reaches once the lab has it
    /** @return iterable<string, array{string}> */
    public static function provideStatesPastTheLab(): iterable
    {
        yield 'sent' => [GalleryPrintOrder::STATE_SENT];
        yield 'producing' => [GalleryPrintOrder::STATE_PRODUCING];
        yield 'shipped' => [GalleryPrintOrder::STATE_SHIPPED];
    }

    // A test basket produced nothing, so its refund takes nothing back
    public function testARefundedTestBasketTouchesNothing(): void
    {
        $orderRepository = $this->createMock(GalleryPrintOrderRepository::class);
        $orderRepository->expects($this->never())->method('findBy');

        $this->createProvider($this->createOffer(true), ['orderRepository' => $orderRepository])
            ->onBasketRefunded(new Basket()->setTestMode(true), []);
    }
}
