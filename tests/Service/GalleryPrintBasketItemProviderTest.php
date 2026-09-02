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
use c975L\GalleryBundle\Model\PrintOffer;
use c975L\GalleryBundle\Repository\GalleryPrintCopyRepository;
use c975L\GalleryBundle\Service\GalleryPrintBasketItemProvider;
use c975L\GalleryBundle\Service\GalleryPrintEmailInterface;
use c975L\GalleryBundle\Service\GalleryPrintService;
use c975L\GalleryBundle\Service\PrintFulfilmentRegistry;
use c975L\PaymentBundle\Entity\Basket;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
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
            $this->createStub(PrintFulfilmentRegistry::class),
            $services['printEmail'],
            $services['configService'],
            $this->createStub(EntityManagerInterface::class),
            $services['messageBus'],
            $translator,
            $services['urlGenerator'],
        );
    }

    /** @return array<string, mixed> */
    private function defaultServices(PrintOffer $offer): array
    {
        $printService = $this->createStub(GalleryPrintService::class);
        $printService->method('findOffer')->willReturn($offer);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('Galerie');

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        return [
            'printService' => $printService,
            'copyRepository' => $this->createStub(GalleryPrintCopyRepository::class),
            'printEmail' => $this->createStub(GalleryPrintEmailInterface::class),
            'configService' => $configService,
            'messageBus' => $messageBus,
            'urlGenerator' => $this->createStub(UrlGeneratorInterface::class),
        ];
    }

    // The copy is claimed by an UPDATE and the order's own collection stays empty until it is read back, so asking the order whether it holds a numbered copy answered no on every sale: the certificate was never asked for and the print went to the lab unsigned
    public function testAPaidNumberedEditionWaitsForItsCertificateInsteadOfGoingToTheLab(): void
    {
        $offer = $this->createOffer(true);

        $copyRepository = $this->createStub(GalleryPrintCopyRepository::class);
        $copyRepository->method('claimNumber')->willReturn(new GalleryPrintCopy()->setNumber(1));

        $printEmail = $this->createMock(GalleryPrintEmailInterface::class);
        $printEmail->expects($this->once())->method('editionSold');
        $printEmail->expects($this->once())->method('editionAwaitingSignature');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->createProvider($offer, [
            'copyRepository' => $copyRepository,
            'printEmail' => $printEmail,
            'messageBus' => $messageBus,
        ])->onBasketPaid(new Basket(), [$offer->getId() => ['quantity' => 1]], []);
    }

    // A shop that issues its certificates on its own turns the hold off: the number is still claimed and the sale still announced, but the print leaves for the lab with the open editions and nobody is asked to release an order that is already gone
    public function testANumberedEditionGoesToTheLabWhenTheHoldIsOff(): void
    {
        $offer = $this->createOffer(true);

        $copyRepository = $this->createStub(GalleryPrintCopyRepository::class);
        $copyRepository->method('claimNumber')->willReturn(new GalleryPrintCopy()->setNumber(1));

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $slug): mixed => 'gallery-print-edition-hold' === $slug ? false : 'Galerie',
        );

        $printEmail = $this->createMock(GalleryPrintEmailInterface::class);
        $printEmail->expects($this->once())->method('editionSold');
        $printEmail->expects($this->never())->method('editionAwaitingSignature');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $this->createProvider($offer, [
            'copyRepository' => $copyRepository,
            'configService' => $configService,
            'printEmail' => $printEmail,
            'messageBus' => $messageBus,
        ])->onBasketPaid(new Basket(), [$offer->getId() => ['quantity' => 1]], []);
    }

    // An open edition has nothing to sign, so it is sent as soon as it is paid
    public function testAPaidOpenEditionGoesStraightToTheLab(): void
    {
        $offer = $this->createOffer(false);

        $printEmail = $this->createMock(GalleryPrintEmailInterface::class);
        $printEmail->expects($this->never())->method('editionSold');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $this->createProvider($offer, [
            'printEmail' => $printEmail,
            'messageBus' => $messageBus,
        ])->onBasketPaid(new Basket(), [$offer->getId() => ['quantity' => 1]], []);
    }

    // The race validateCheckout() cannot close: the edition sold out between the checkout and the payment. The customer has paid, so the order is kept and flagged rather than lost - and nothing is sent, neither to the lab nor to him
    public function testAnEditionSoldOutAfterPaymentSendsNothing(): void
    {
        $offer = $this->createOffer(true);

        $copyRepository = $this->createStub(GalleryPrintCopyRepository::class);
        $copyRepository->method('claimNumber')->willReturn(null);

        $printEmail = $this->createMock(GalleryPrintEmailInterface::class);
        $printEmail->expects($this->never())->method('editionSold');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->createProvider($offer, [
            'copyRepository' => $copyRepository,
            'printEmail' => $printEmail,
            'messageBus' => $messageBus,
        ])->onBasketPaid(new Basket(), [$offer->getId() => ['quantity' => 1]], []);
    }
}
