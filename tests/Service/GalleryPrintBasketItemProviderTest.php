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

    /** @param array<string, mixed> $services */
    private function createProvider(PrintOffer $offer, array $services = []): GalleryPrintBasketItemProvider
    {
        $printService = $this->createStub(GalleryPrintService::class);
        $printService->method('findOffer')->willReturn($offer);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('Galerie');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $messageBus = $services['messageBus'] ?? $this->createStub(MessageBusInterface::class);
        if ($messageBus instanceof MessageBusInterface && !isset($services['messageBus'])) {
            $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));
        }

        return new GalleryPrintBasketItemProvider(
            $printService,
            $services['copyRepository'] ?? $this->createStub(GalleryPrintCopyRepository::class),
            $this->createStub(PrintFulfilmentRegistry::class),
            $services['printEmail'] ?? $this->createStub(GalleryPrintEmailInterface::class),
            $configService,
            $this->createStub(EntityManagerInterface::class),
            $messageBus,
            $translator,
        );
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
