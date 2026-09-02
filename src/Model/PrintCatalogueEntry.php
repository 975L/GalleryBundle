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

/**
 * One line of a lab's catalogue as the bundle ships it, before a shop has said anything about it.
 *
 * Not a GalleryPrintFormat because it is not one yet: this carries what the lab decides (the size it prints, the
 * reference it prints it under) and nothing a shop decides (whether it sells it, and at what price). What the importer
 * writes from it is a row an admin still has to price and publish.
 */
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
    ) {
    }

    // The row this line becomes, unpublished and at the resolution the entity defaults to - a format nobody has priced must not be on sale, whatever the placeholder price says
    public function toFormat(): GalleryPrintFormat
    {
        return new GalleryPrintFormat()
            ->setSlug($this->slug)
            ->setLabel($this->label)
            ->setWidthCm($this->widthCm)
            ->setHeightCm($this->heightCm)
            ->setSku($this->sku)
            ->setPaper($this->paper)
            ->setPaperDescription($this->paperDescription)
            ->setPrice($this->price)
            ->setPosition($this->position)
            ->setPublished(false)
        ;
    }
}
