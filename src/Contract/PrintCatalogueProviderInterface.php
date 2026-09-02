<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Contract;

use c975L\GalleryBundle\Model\PrintCatalogueEntry;

/**
 * The catalogue a lab is worth starting from - the sizes it prints, on which papers, under which references.
 *
 * Separate from PrintFulfilmentInterface and optional on purpose: sending an order and proposing a catalogue are two
 * different jobs, and a lab whose product range nobody wrote down still has a working driver. A driver implements this
 * only when it has something to propose.
 *
 * What it hands over is a starting point and never a price list: a shop's prices are its own, and the ones here are
 * placeholders an admin is expected to overwrite (see PrintCatalogueImporter, which imports every line unpublished).
 */
interface PrintCatalogueProviderInterface
{
    // The driver this catalogue belongs to, matching PrintFulfilmentInterface::getName() - the importer offers the catalogue of the lab the site actually prints at and no other
    public function getName(): string;

    /**
     * The catalogue itself, in the order it should be read.
     *
     * @return list<PrintCatalogueEntry>
     */
    public function getEntries(): array;

    /**
     * The references, among those given, that the lab does not know - so a catalogue that has drifted since the bundle
     * was released is reported rather than imported as dead rows.
     *
     * An empty list means everything checked out; null means nothing could be checked at all (no API key, lab
     * unreachable), which is not the same answer and is not reported as if it were.
     *
     * @param list<string> $skus
     *
     * @return list<string>|null
     */
    public function findUnknownSkus(array $skus): ?array;
}
