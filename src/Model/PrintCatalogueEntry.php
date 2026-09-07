<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Model;

use c975L\GalleryBundle\Entity\GalleryPrintFormat;

// One line of a lab's catalogue as the bundle ships it, carrying what the lab decides (the size it prints, the reference it prints it under) and nothing a shop decides, so what the importer writes from it is a row an admin still has to price and publish - the paper's description being a translation id of the gallery domain resolved in the site's locale (see PrintCatalogueImporter), or a plain sentence, which the translator hands back untouched
readonly class PrintCatalogueEntry
{
    public function __construct(
        public string $slug,
        public string $label,
        public int $widthCm,
        public int $heightCm,
        public string $sku,
        public string $paper = '',
        public string $paperDescription = '',
        public int $price = 0,
        public int $position = 0,
        // The resolution the size is offered at: a large print is looked at from further away than a small one, and asking 300 dpi of a wall print refuses files that print perfectly well on it
        public int $dpi = GalleryPrintFormat::DEFAULT_DPI,
    ) {
    }

    // The row this line becomes, unpublished, its description already in the customer's words - a format nobody has priced must not be on sale, whatever the placeholder price says
    public function toFormat(string $paperDescription): GalleryPrintFormat
    {
        return new GalleryPrintFormat()
            ->setSlug($this->slug)
            ->setLabel($this->label)
            ->setWidthCm($this->widthCm)
            ->setHeightCm($this->heightCm)
            ->setDpi($this->dpi)
            ->setSku($this->sku)
            ->setPaper($this->paper)
            ->setPaperDescription($paperDescription)
            ->setPrice($this->price)
            ->setPosition($this->position)
            ->setPublished(false)
        ;
    }
}
