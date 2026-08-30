<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Model;

use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;

/**
 * A photograph at one size - what is actually bought, and what a basket line holds.
 *
 * Neither the photograph nor the format is sellable alone, so neither is what the checkout is handed. The pair has no
 * table of its own either: it is the catalogue crossed with what is marked printable, and writing a row per crossing
 * would mean maintaining a hundred rows an admin never asked for.
 */
readonly class PrintOffer
{
    public function __construct(
        public GalleryMedia $media,
        public GalleryPrintFormat $format,
    ) {
    }

    // What PaymentBundle keys this line on, and what findItem() is given back. Two ids and not one because the pair has no id of its own
    public function getId(): string
    {
        return sprintf('%d:%s', (int) $this->media->getId(), (string) $this->format->getSlug());
    }

    /**
     * Reads back what getId() wrote, or null when the string is not one it produced.
     *
     * @return array{0: int, 1: string}|null
     */
    public static function parseId(int | string $id): ?array
    {
        if (!\is_string($id) || !str_contains($id, ':')) {
            return null;
        }

        [$mediaId, $formatSlug] = explode(':', $id, 2);

        if ('' === $formatSlug || !ctype_digit($mediaId)) {
            return null;
        }

        return [(int) $mediaId, $formatSlug];
    }
}
