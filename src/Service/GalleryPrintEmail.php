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
use c975L\GalleryBundle\Email\GalleryEmailTemplateProvider;
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\PaymentBundle\Email\BasketEmailSender;

// Sends through PaymentBundle's own sender rather than a mailer of its own, so a print letter carries the shop's header, footer, language and layout exactly as an order confirmation does - and so its sentences are rewritten in the same back-office screen as the others (see GalleryEmailTemplateProvider)
class GalleryPrintEmail implements GalleryPrintEmailInterface
{
    public function __construct(
        private readonly BasketEmailSender $basketEmailSender,
        private readonly GalleryCertificateService $certificateService,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function editionSold(GalleryPrintOrder $order): void
    {
        $basket = $order->getBasket();
        $numbered = $this->numberedCopies($order);

        if (null === $basket || [] === $numbered) {
            return;
        }

        $this->basketEmailSender->send(
            $basket,
            'email.print_edition_sold_subject',
            GalleryEmailTemplateProvider::TEMPLATE_EDITION_SOLD,
            [
                'numbers' => $this->describe($numbered),
                // The first one's, an order holding several certificates carrying a link each on paper - what the letter offers is a way in, not the whole register
                'certificate_url' => $this->certificateService->getVerificationUrl($numbered[0]) ?? '',
            ],
        );
    }

    public function editionAwaitingSignature(GalleryPrintOrder $order): void
    {
        $basket = $order->getBasket();
        $numbered = $this->numberedCopies($order);
        $shopEmail = $this->configService->get('shop-email-from');

        // No address configured means no shop to write to - the order still waits in the back-office, which is where it is acted on anyway
        if (null === $basket || [] === $numbered || !\is_string($shopEmail) || '' === $shopEmail) {
            return;
        }

        $this->basketEmailSender->send(
            $basket,
            'email.print_edition_signature_subject',
            GalleryEmailTemplateProvider::TEMPLATE_EDITION_SIGNATURE,
            ['numbers' => $this->describe($numbered)],
            $shopEmail,
        );
    }

    public function shipped(GalleryPrintOrder $order): void
    {
        $basket = $order->getBasket();

        // An order written in the back-office may name nobody, and BasketEmailSender falling back on the site's own address would post the customer's notice to the shop
        if (null === $basket || null === $basket->getEmail() || '' === $basket->getEmail()) {
            return;
        }

        $this->basketEmailSender->send(
            $basket,
            'email.print_shipped_subject',
            GalleryEmailTemplateProvider::TEMPLATE_PRINT_SHIPPED,
        );
    }

    public function cancelled(GalleryPrintOrder $order): void
    {
        $basket = $order->getBasket();
        $shopEmail = $this->configService->get('shop-email-from');

        // No address configured means no shop to write to - the order still reads "cancelled" in the back-office, which is where it is acted on anyway
        if (null === $basket || !\is_string($shopEmail) || '' === $shopEmail) {
            return;
        }

        $this->basketEmailSender->send(
            $basket,
            'email.print_cancelled_subject',
            GalleryEmailTemplateProvider::TEMPLATE_PRINT_CANCELLED,
            ['number' => (string) $basket->getNumber()],
            $shopEmail,
        );
    }

    /** @return list<GalleryPrintCopy> */
    private function numberedCopies(GalleryPrintOrder $order): array
    {
        return array_values(array_filter(
            $order->getCopies()->toArray(),
            static fn (GalleryPrintCopy $copy): bool => null !== $copy->getNumber(),
        ));
    }

    // "7/30, 8/30" - what the letter names the copies by, and the only form in which an edition is ever written down
    /** @param list<GalleryPrintCopy> $copies */
    private function describe(array $copies): string
    {
        return implode(', ', array_map(
            static fn (GalleryPrintCopy $copy): string => sprintf('%d/%d', (int) $copy->getNumber(), (int) $copy->getMedia()?->getEditionSize()),
            $copies,
        ));
    }
}
