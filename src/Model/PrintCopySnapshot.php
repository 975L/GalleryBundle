<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Model;

/**
 * Everything a certificate states, read once at the sale and written onto the copy.
 *
 * A certificate is signed by hand and posted; from that moment the paper the buyer holds is the document, and anything
 * the site can still redraw has to say the same thing. So none of this is looked up again: the photograph can be
 * retitled, the format renamed or repriced, the site itself renamed, and the copy keeps saying what was sold.
 *
 * Carried as one object rather than as six arguments because both paths that sell a copy - the numbered claim, written
 * in sql, and the open edition, written through the orm - have to freeze exactly the same list, and a list spelled out
 * twice is a list that drifts.
 */
final readonly class PrintCopySnapshot
{
    public function __construct(
        // The catalogue key, kept for the machines - the lab's sku is looked up from it, and it is what tells two sizes of one photograph apart
        public string $format,
        // The catalogue line as a human reads it ("30 x 40 cm, Hahnemühle Photo Rag"), which is what the certificate prints - the key above means nothing to a buyer
        public string $formatLabel,
        // The lab's own reference for that size and that paper. Frozen like the rest, and for the sharpest reason of all: repointing a catalogue line at another paper must not change what a lab prints for an order already paid
        public ?string $sku,
        public int $price,
        public string $workTitle,
        public ?string $credits,
        // Who issued the certificate, as the site was called that day. Frozen for the same reason as the rest: a site that is renamed has not reissued the certificates it already signed
        public string $issuer,
    ) {
    }
}
