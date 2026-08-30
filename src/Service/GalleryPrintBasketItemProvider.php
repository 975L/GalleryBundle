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
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Message\GalleryPrintOrderMessage;
use c975L\GalleryBundle\Model\PrintCopySnapshot;
use c975L\GalleryBundle\Model\PrintOffer;
use c975L\GalleryBundle\Repository\GalleryPrintCopyRepository;
use c975L\PaymentBundle\Contract\BasketItemProviderInterface;
use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Service\VatCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Plugs prints into PaymentBundle's basket and checkout (see BasketItemProviderInterface). What is sold is a photograph at one size, which is why every id here names both
class GalleryPrintBasketItemProvider implements BasketItemProviderInterface
{
    public function __construct(
        private readonly GalleryPrintService $printService,
        private readonly GalleryPrintCopyRepository $copyRepository,
        private readonly PrintFulfilmentRegistry $fulfilmentRegistry,
        private readonly GalleryPrintEmailInterface $printEmail,
        private readonly ConfigServiceInterface $configService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getKind(): string
    {
        return 'gallery_print';
    }

    public function findItem(int | string $id): ?object
    {
        return $this->printService->findOffer($id);
    }

    public function validateAddition(object $item, int $quantity): ?string
    {
        // A removal never needs stock, so it must not be blocked by a sold-out edition
        if ($quantity <= 0) {
            return null;
        }

        if (!$item instanceof PrintOffer) {
            return $this->translator->trans('label.print_unavailable', [], 'gallery');
        }

        // Everything the sale page checked before showing a button, asked again: the page may have been open since the photograph was withdrawn, the shop closed or the original replaced by a smaller one
        if (!$this->printService->isPrintable($item->media) || !$item->format->isPublished()) {
            return $this->translator->trans('label.print_unavailable', [], 'gallery');
        }

        // The catalogue offers this size, but this photograph may not have the pixels for it - which is a question about the file and not about the format (see GalleryPrintService::getOffers)
        $offered = array_any(
            $this->printService->getOffers($item->media),
            static fn (PrintOffer $offer): bool => $offer->format->getSlug() === $item->format->getSlug(),
        );

        if (!$offered) {
            return $this->translator->trans('label.print_format_unavailable', [], 'gallery');
        }

        // Asked one click at a time, so it can only say the edition is out - not whether this basket already holds the last copies. validateCheckout() below is where the whole quantity is weighed
        $remaining = $this->printService->getRemaining($item->media);
        if (null !== $remaining && $remaining < 1) {
            return $this->translator->trans('label.print_edition_sold_out', [], 'gallery');
        }

        return null;
    }

    // The only check between a basket and a payment, and the one that matters for an edition: baskets sit for days, and thirty prints can sell in between
    public function validateCheckout(Basket $basket, array $itemsOfThisKind): ?string
    {
        // Several sizes of the same photograph draw from one edition - "thirty, all sizes and mountings combined" - so what is weighed is the total per photograph and not per line
        $wanted = [];

        foreach ($itemsOfThisKind as $id => $itemContent) {
            $offer = $this->printService->findOffer($id);

            if (null === $offer) {
                return $this->translator->trans('label.print_unavailable', [], 'gallery');
            }

            $error = $this->validateAddition($offer, (int) $itemContent['quantity']);
            if (null !== $error) {
                return $error;
            }

            $mediaId = (int) $offer->media->getId();
            $wanted[$mediaId] = ($wanted[$mediaId] ?? 0) + (int) $itemContent['quantity'];
        }

        foreach ($itemsOfThisKind as $id => $itemContent) {
            $offer = $this->printService->findOffer($id);
            $remaining = null === $offer ? null : $this->printService->getRemaining($offer->media);

            if (null !== $offer && null !== $remaining && $wanted[(int) $offer->media->getId()] > $remaining) {
                return $this->translator->trans('label.print_edition_not_enough', ['%count%' => $remaining], 'gallery');
            }
        }

        return null;
    }

    public function toBasketData(object $item, int $quantity): array
    {
        /** @var PrintOffer $item */
        $media = $item->media;
        $format = $item->format;
        $total = $quantity * (int) $format->getPrice();

        return [
            'item' => [
                'mediaId' => $media->getId(),
                'title' => $media->getTitle(),
                'slug' => $media->getSlug(),
                'image' => $media->getThumbnailFilename(),
                // Frozen here rather than read back from the catalogue: what an order says it sold has to keep saying it after a price rise or a change of paper
                'format' => $format->getSlug(),
                'formatLabel' => $format->getLabel(),
                'sku' => $format->getSku(),
                'price' => $format->getPrice(),
                'vat' => $format->getVat(),
                // What tells the two checkout paths apart once the basket is paid - one is printed straight away, the other waits for a signature
                'editionSize' => $media->getEditionSize(),
            ],
            'parent' => [
                'title' => $media->getCategory()?->getTitle(),
                'slug' => $media->getCategory()?->getSlug(),
            ],
            'type' => 'gallery_print',
            'quantity' => $quantity,
            'totalVat' => VatCalculator::included($total, $format->getVat()),
            'total' => $total,
        ];
    }

    // A print is a sheet of paper in a tube - nothing here is ever delivered by e-mail
    public function getContentFlags(array $itemData): int
    {
        return Basket::CONTENT_FLAG_PHYSICAL;
    }

    // Nothing to carry across the payment: what was ordered is on the basket, and the lab is only told about it once the money is in
    public function onBasketValidated(Basket $basket, array $itemsOfThisKind, array $requestData): array
    {
        return [];
    }

    /**
     * Writes what was sold, numbers it where the edition demands it, and sends it to the lab.
     *
     * Reached from the payment provider's webhook as well as from the customer's return, so it reads nothing off the
     * current request. It also runs once and once only, which is what lets it claim numbers: a second run would burn a
     * second set.
     */
    public function onBasketPaid(Basket $basket, array $itemsOfThisKind, array $checkoutData): void
    {
        if ([] === $itemsOfThisKind) {
            return;
        }

        $order = new GalleryPrintOrder()
            ->setBasket($basket)
            ->setProvider($this->fulfilmentRegistry->get()?->getName() ?? 'manual')
        ;

        // Flushed before any copy is claimed, the claim being an UPDATE that needs the order's id - and needing it is what keeps the claim a single statement
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $soldOut = false;

        // Counted here rather than asked of the order afterwards: a number is claimed by an UPDATE (see GalleryPrintCopyRepository::claimNumber) and an open copy only sets its owning side, so the order's own collection stays empty until it is read back from the base
        $hasLimitedEdition = false;

        foreach ($itemsOfThisKind as $id => $itemContent) {
            $offer = $this->printService->findOffer($id);

            if (null === $offer) {
                continue;
            }

            $snapshot = $this->snapshot($offer, $itemContent['item'] ?? []);

            for ($unit = 0; $unit < (int) $itemContent['quantity']; ++$unit) {
                if ($offer->media->isLimitedEdition()) {
                    $copy = $this->copyRepository->claimNumber($offer->media, $order, $snapshot);

                    // validateCheckout() weighed the edition minutes ago, so this is the race it cannot close: two checkouts settling at once. The customer has paid and is owed a print, so the order is kept and flagged for a human rather than being lost
                    if (null === $copy) {
                        $soldOut = true;

                        continue;
                    }

                    $hasLimitedEdition = true;

                    continue;
                }

                $this->entityManager->persist(
                    new GalleryPrintCopy()
                        ->setMedia($offer->media)
                        ->setOrder($order)
                        ->setSoldAt(new \DateTimeImmutable())
                        ->applySnapshot($snapshot)
                );
            }
        }

        if ($soldOut) {
            $order->setLastError($this->translator->trans('label.print_edition_sold_out_after_payment', [], 'gallery'));
        }

        $this->entityManager->flush();

        if ($soldOut) {
            return;
        }

        // An art edition stops here: its certificate has to be signed and posted, so the admin releases it in the back-office and the sending happens then. An open edition goes straight to the lab
        if ($hasLimitedEdition) {
            $this->printEmail->editionSold($order);
            $this->printEmail->editionAwaitingSignature($order);

            return;
        }

        $this->messageBus->dispatch(new GalleryPrintOrderMessage((int) $order->getId()));
    }

    /**
     * Reads what the certificate will state, once, at the sale.
     *
     * Taken from the basket's own line wherever it holds it: that line was written when the visitor clicked, and its
     * title, format and price are what they were shown and agreed to - a catalogue repriced between the click and the
     * payment must not change what is certified. The catalogue is only fallen back on for a basket written before the
     * line carried the value.
     *
     * The credits and the issuer are read here instead, the basket having no reason to carry either: neither is shown
     * at checkout, and the sale is the moment that counts for both.
     *
     * @param array<string, mixed> $itemData
     */
    private function snapshot(PrintOffer $offer, array $itemData): PrintCopySnapshot
    {
        return new PrintCopySnapshot(
            format: (string) ($itemData['format'] ?? $offer->format->getSlug()),
            formatLabel: (string) ($itemData['formatLabel'] ?? $offer->format->getLabel()),
            sku: $itemData['sku'] ?? $offer->format->getSku(),
            price: (int) ($itemData['price'] ?? $offer->format->getPrice()),
            workTitle: (string) ($itemData['title'] ?? $offer->media->getTitle()),
            credits: $offer->media->getCredits(),
            issuer: (string) $this->configService->get('site-name'),
        );
    }
}
