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
use c975L\GalleryBundle\Contract\PrintCatalogueProviderInterface;
use c975L\GalleryBundle\Model\PrintCatalogueEntry;
use c975L\GalleryBundle\Model\PrintCatalogueImportReport;
use c975L\GalleryBundle\Repository\GalleryPrintFormatRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fills an empty print catalogue from what the configured lab proposes, and never fills it twice.
 *
 * A shop opening its prints has a screen asking for a slug, a size, a price and a reference the lab knows - fifteen
 * fields it has no way of guessing, and one wrong reference is an order refused after it was paid. This writes the
 * lines the lab confirms it can print, and leaves everything a shop decides to the shop.
 */
class PrintCatalogueImporter
{
    /** @param iterable<PrintCatalogueProviderInterface> $catalogues */
    public function __construct(
        private readonly iterable $catalogues,
        private readonly ConfigServiceInterface $configService,
        private readonly GalleryPrintFormatRepository $formatRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // The catalogue of the lab the site prints at, or null when that lab proposes none - a site printing by hand has nothing to import
    public function getCatalogue(): ?PrintCatalogueProviderInterface
    {
        $name = $this->configService->get('gallery-print-provider');

        if (!\is_string($name) || '' === $name) {
            return null;
        }

        foreach ($this->catalogues as $catalogue) {
            if ($catalogue->getName() === $name) {
                return $catalogue;
            }
        }

        return null;
    }

    /**
     * Imports what is missing and reports what it did.
     *
     * Skipped on slug and on sku alike: the slug is what an old order names a format by, and the sku is the product
     * itself, so a shop that already sells 30x45 on matte art is not given a second row for it under another name.
     */
    public function import(): PrintCatalogueImportReport
    {
        $catalogue = $this->getCatalogue();

        // Nothing was imported, so there is nothing whose references went unchecked either
        if (null === $catalogue) {
            return new PrintCatalogueImportReport(0, 0, [], false);
        }

        $missing = $this->findMissing($catalogue->getEntries());

        // Asked only about what is about to be written, so a catalogue already imported costs nothing to run again
        $unknown = [] === $missing ? [] : $catalogue->findUnknownSkus(array_map(static fn (PrintCatalogueEntry $entry): string => $entry->sku, $missing));
        $imported = 0;

        foreach ($missing as $entry) {
            // A reference the lab does not know is left out rather than written: it would be a row an admin could publish and sell, and every order of it would be refused
            if (null !== $unknown && \in_array($entry->sku, $unknown, true)) {
                continue;
            }

            $this->entityManager->persist($entry->toFormat());
            ++$imported;
        }

        if ($imported > 0) {
            $this->entityManager->flush();
        }

        return new PrintCatalogueImportReport($imported, \count($catalogue->getEntries()) - \count($missing), $unknown ?? [], null === $unknown);
    }

    /**
     * The entries no row already stands for.
     *
     * @param list<PrintCatalogueEntry> $entries
     *
     * @return list<PrintCatalogueEntry>
     */
    private function findMissing(array $entries): array
    {
        $slugs = [];
        $skus = [];

        foreach ($this->formatRepository->findAll() as $format) {
            $slugs[] = (string) $format->getSlug();
            $skus[] = (string) $format->getSku();
        }

        return array_values(array_filter(
            $entries,
            static fn (PrintCatalogueEntry $entry): bool => !\in_array($entry->slug, $slugs, true) && !\in_array($entry->sku, $skus, true),
        ));
    }
}
