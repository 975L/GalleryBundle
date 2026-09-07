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
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Model\PrintCatalogueEntry;
use c975L\GalleryBundle\Model\PrintCatalogueImportReport;
use c975L\GalleryBundle\Repository\GalleryPrintFormatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fills an empty print catalogue from what the configured lab proposes, and never fills it twice.
 *
 * A shop opening its prints has a screen asking for a slug, a size, a price and a reference the lab knows - fifteen
 * fields it has no way of guessing, and one wrong reference is an order refused after it was paid. This writes the
 * lines the lab confirms it can print, and leaves everything a shop decides to the shop.
 *
 * What the bundle decided and later changed its mind on - the sentence describing a paper, the resolution a size is
 * offered at - is brought up to date on the rows still carrying what it shipped, and on those only: a sentence the
 * admin rewrote, a dpi the admin set, are the shop's and stay.
 */
class PrintCatalogueImporter
{
    /** @param iterable<PrintCatalogueProviderInterface> $catalogues */
    public function __construct(
        private readonly iterable $catalogues,
        private readonly ConfigServiceInterface $configService,
        private readonly GalleryPrintFormatRepository $formatRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        // The site's language and not the admin's: the sentence written here is read by the customer, whatever language the back-office was opened in
        #[Autowire(param: 'kernel.default_locale')]
        private readonly string $locale = 'en',
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

    // Imports what is missing, refreshes what is still as shipped, and reports what it did - skipped on slug and on sku alike, the slug being what an old order names a format by and the sku the product itself, so a shop already selling 30x45 on matte art is not given a second row for it under another name
    public function import(): PrintCatalogueImportReport
    {
        $catalogue = $this->getCatalogue();

        // Nothing was imported, so there is nothing whose references went unchecked either
        if (null === $catalogue) {
            return new PrintCatalogueImportReport(0, 0, [], false);
        }

        [$missing, $refreshed] = $this->sortOut($catalogue);

        // Asked only about what is about to be written, so a catalogue already imported costs nothing to run again
        $unknown = [] === $missing ? [] : $catalogue->findUnknownSkus(array_map(static fn (PrintCatalogueEntry $entry): string => $entry->sku, $missing));
        $imported = $this->write($missing, $unknown);

        if ($imported > 0 || $refreshed > 0) {
            $this->entityManager->flush();
        }

        return new PrintCatalogueImportReport($imported, \count($catalogue->getEntries()) - \count($missing), $unknown ?? [], null === $unknown, $refreshed);
    }

    // Splits the catalogue in two against what the shop already has: the lines it has never seen, and the count of those it holds whose shipped sentence or resolution it just brought up to date
    /** @return array{0: list<PrintCatalogueEntry>, 1: int} */
    private function sortOut(PrintCatalogueProviderInterface $catalogue): array
    {
        $existing = $this->findExisting();
        $missing = [];
        $refreshed = 0;

        foreach ($catalogue->getEntries() as $entry) {
            $format = $existing[$entry->slug] ?? $existing[$entry->sku] ?? null;

            if (null === $format) {
                $missing[] = $entry;
            } elseif ($this->refresh($format, $entry)) {
                ++$refreshed;
            }
        }

        return [$missing, $refreshed];
    }

    // Writes the lines the lab knows, its description already in the customer's words - a reference the lab does not know is left out rather than written, it would be a row an admin could publish and sell whose every order would be refused
    /**
     * @param list<PrintCatalogueEntry> $missing
     * @param list<string>|null         $unknown
     */
    private function write(array $missing, ?array $unknown): int
    {
        $imported = 0;

        foreach ($missing as $entry) {
            if (null !== $unknown && \in_array($entry->sku, $unknown, true)) {
                continue;
            }

            $this->entityManager->persist($entry->toFormat($this->describe($entry)));
            ++$imported;
        }

        return $imported;
    }

    /**
     * The rows already there, reachable by their slug and by their sku alike.
     *
     * @return array<string, GalleryPrintFormat>
     */
    private function findExisting(): array
    {
        $existing = [];

        foreach ($this->formatRepository->findAll() as $format) {
            $existing[(string) $format->getSlug()] = $format;
            $existing[(string) $format->getSku()] = $format;
        }

        return $existing;
    }

    // Brings a row up to what the catalogue now ships, where it still carries what the catalogue shipped before - the English sentence of an earlier release, the resolution the entity defaults to - and says whether anything changed
    private function refresh(GalleryPrintFormat $format, PrintCatalogueEntry $entry): bool
    {
        $changed = false;
        $description = $this->describe($entry);
        $current = (string) $format->getPaperDescription();

        // Only the sentence of an earlier release is rewritten - a row without one was built by hand, and its silence is the admin's
        if ($description !== $current && $this->describe($entry, 'en') === $current) {
            $format->setPaperDescription($description);
            $changed = true;
        }

        if (GalleryPrintFormat::DEFAULT_DPI === $format->getDpi() && $entry->dpi !== $format->getDpi()) {
            $format->setDpi($entry->dpi);
            $changed = true;
        }

        return $changed;
    }

    // The paper's sentence in the site's language - or as the catalogue wrote it, when it wrote a sentence rather than an id
    private function describe(PrintCatalogueEntry $entry, ?string $locale = null): string
    {
        return $this->translator->trans($entry->paperDescription, [], 'gallery', $locale ?? $this->locale);
    }
}
