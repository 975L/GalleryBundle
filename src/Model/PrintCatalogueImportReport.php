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
 * What one run of the importer did, in the terms the admin who pressed the button reads it back in.
 *
 * Unchecked is its own answer and not an empty list of unknowns: a catalogue imported without an API key was verified
 * against nothing, and saying so is the difference between "the lab confirmed these" and "nobody asked".
 */
readonly class PrintCatalogueImportReport
{
    /** @param list<string> $unknownSkus */
    public function __construct(
        public int $imported,
        public int $alreadyPresent,
        public array $unknownSkus,
        public bool $unchecked,
    ) {
    }
}
