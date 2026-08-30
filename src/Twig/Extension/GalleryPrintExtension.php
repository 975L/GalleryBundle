<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Twig\Extension;

use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Model\PrintOffer;
use c975L\GalleryBundle\Service\GalleryPrintService;
use Twig\Attribute\AsTwigFunction;

/**
 * What a public page has to know about prints, which is the least possible.
 *
 * A template asks "is this one for sale" and gets a plain yes or no, whatever the reason - the shop is closed, the
 * photograph was not marked, its original is gone, no format fits it. Every one of those answers false, and no template
 * should have to know which.
 */
class GalleryPrintExtension
{
    public function __construct(
        private readonly GalleryPrintService $printService,
    ) {
    }

    #[AsTwigFunction('gallery_print_available')]
    public function isPrintable(GalleryMedia $media): bool
    {
        return $this->printService->isPrintable($media);
    }

    // The sizes to show, cheapest first, so the page can state what a print starts at
    /** @return list<PrintOffer> */
    #[AsTwigFunction('gallery_print_offers')]
    public function getOffers(GalleryMedia $media): array
    {
        $offers = $this->printService->getOffers($media);

        usort($offers, static fn (PrintOffer $a, PrintOffer $b): int => (int) $a->format->getPrice() <=> (int) $b->format->getPrice());

        return $offers;
    }

    // How many of an edition are left, null when the photograph is sold as an open one - the difference between a page that says "3 left of 30" and one that says nothing at all
    #[AsTwigFunction('gallery_print_remaining')]
    public function getRemaining(GalleryMedia $media): ?int
    {
        return $this->printService->getRemaining($media);
    }
}
