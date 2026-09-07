<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service\Fulfilment;

use c975L\GalleryBundle\Contract\PrintCatalogueProviderInterface;
use c975L\GalleryBundle\Model\PrintCatalogueEntry;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Prodigi's range, as a shop is worth starting from: the five shapes a photograph comes in on the four papers that cover a gallery, each line carrying its paper, the translation id of the sentence saying what that paper is for, and the resolution its size is read at - held as plain data beside the driver, every reference read back from GET /products/{sku} rather than composed from the naming pattern, deliberately short of everything the lab prints, and priced with placeholders the importer brings in unpublished
class ProdigiCatalogue implements PrintCatalogueProviderInterface
{
    // Each paper as the lab names it, with the translation id of the sentence saying what it is for (see translations/gallery.*.xlf, print_paper.*)
    private const array LUSTRE = ['Photographic Art Print 240 g', 'print_paper.lustre'];
    private const array MATTE = ['Enhanced Matte Art 200 g', 'print_paper.matte'];
    private const array PHOTO_RAG = ['Hahnemühle Photo Rag 308 g', 'print_paper.photo_rag'];
    private const array GERMAN_ETCHING = ['Hahnemühle German Etching 310 g', 'print_paper.german_etching'];

    // Up to 30 cm a print is held and read at 300 dpi; up to 40 it sits on a shelf; beyond, it hangs on a wall
    private const array DPI_BY_LONG_EDGE = [30 => 300, 40 => 240];
    private const int DPI_LARGE = 200;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ProdigiEnvironment $environment,
    ) {
    }

    public function getName(): string
    {
        return 'prodigi';
    }

    /** @return list<PrintCatalogueEntry> */
    public function getEntries(): array
    {
        return [
            $this->line('lustre-20x20', 20, 20, 'GLOBAL-PAP-8X8', self::LUSTRE, 4500, 10),
            $this->line('lustre-30x30', 30, 30, 'GLOBAL-PAP-12X12', self::LUSTRE, 8000, 20),
            $this->line('lustre-40x40', 40, 40, 'GLOBAL-PAP-16X16', self::LUSTRE, 12000, 30),
            $this->line('lustre-45x45', 45, 45, 'GLOBAL-PAP-18X18', self::LUSTRE, 14000, 35),
            $this->line('lustre-20x30', 20, 30, 'GLOBAL-PAP-8X12', self::LUSTRE, 6000, 40),
            $this->line('lustre-30x45', 30, 45, 'GLOBAL-PAP-12X18', self::LUSTRE, 10500, 50),
            $this->line('lustre-40x60', 40, 60, 'GLOBAL-PAP-16X24', self::LUSTRE, 16500, 60),
            $this->line('lustre-23x30', 23, 30, 'GLOBAL-PAP-9X12', self::LUSTRE, 6500, 70),
            $this->line('lustre-30x40', 30, 40, 'GLOBAL-PAP-12X16', self::LUSTRE, 10000, 80),
            $this->line('lustre-45x60', 45, 60, 'GLOBAL-PAP-18X24', self::LUSTRE, 18000, 90),
            $this->line('lustre-51x91', 51, 91, 'GLOBAL-PAP-20X36', self::LUSTRE, 27000, 100),
            $this->line('lustre-a4', 21, 30, 'GLOBAL-PAP-A4', self::LUSTRE, 6000, 110),
            $this->line('lustre-a3', 30, 42, 'GLOBAL-PAP-A3', self::LUSTRE, 10000, 120),
            $this->line('lustre-a2', 42, 59, 'GLOBAL-PAP-A2', self::LUSTRE, 17000, 130),
            $this->line('mat-20x20', 20, 20, 'GLOBAL-FAP-8X8', self::MATTE, 5500, 140),
            $this->line('mat-30x30', 30, 30, 'GLOBAL-FAP-12X12', self::MATTE, 10000, 150),
            $this->line('mat-40x40', 40, 40, 'GLOBAL-FAP-16X16', self::MATTE, 15000, 160),
            $this->line('mat-45x45', 45, 45, 'GLOBAL-FAP-18X18', self::MATTE, 17500, 165),
            $this->line('mat-20x30', 20, 30, 'GLOBAL-FAP-8X12', self::MATTE, 7500, 170),
            $this->line('mat-30x45', 30, 45, 'GLOBAL-FAP-12X18', self::MATTE, 13500, 180),
            $this->line('mat-40x60', 40, 60, 'GLOBAL-FAP-16X24', self::MATTE, 20500, 190),
            $this->line('mat-23x30', 23, 30, 'GLOBAL-FAP-9X12', self::MATTE, 8000, 200),
            $this->line('mat-30x40', 30, 40, 'GLOBAL-FAP-12X16', self::MATTE, 12000, 210),
            $this->line('mat-45x60', 45, 60, 'GLOBAL-FAP-18X24', self::MATTE, 22500, 220),
            $this->line('mat-23x41', 23, 41, 'GLOBAL-FAP-9X16', self::MATTE, 10000, 230),
            $this->line('mat-51x91', 51, 91, 'GLOBAL-FAP-20X36', self::MATTE, 33500, 240),
            $this->line('mat-a4', 21, 30, 'GLOBAL-FAP-A4', self::MATTE, 7500, 250),
            $this->line('mat-a3', 30, 42, 'GLOBAL-FAP-A3', self::MATTE, 12500, 260),
            $this->line('mat-a2', 42, 59, 'GLOBAL-FAP-A2', self::MATTE, 21000, 270),
            $this->line('photo-rag-20x20', 20, 20, 'GLOBAL-HPR-8X8', self::PHOTO_RAG, 7500, 280),
            $this->line('photo-rag-30x30', 30, 30, 'GLOBAL-HPR-12X12', self::PHOTO_RAG, 14000, 290),
            $this->line('photo-rag-40x40', 40, 40, 'GLOBAL-HPR-16X16', self::PHOTO_RAG, 21500, 300),
            $this->line('photo-rag-45x45', 45, 45, 'GLOBAL-HPR-18X18', self::PHOTO_RAG, 25000, 305),
            $this->line('photo-rag-20x30', 20, 30, 'GLOBAL-HPR-8X12', self::PHOTO_RAG, 10000, 310),
            $this->line('photo-rag-30x45', 30, 45, 'GLOBAL-HPR-12X18', self::PHOTO_RAG, 18500, 320),
            $this->line('photo-rag-40x60', 40, 60, 'GLOBAL-HPR-16X24', self::PHOTO_RAG, 29000, 330),
            $this->line('photo-rag-30x40', 30, 40, 'GLOBAL-HPR-12X16', self::PHOTO_RAG, 17000, 340),
            $this->line('photo-rag-45x60', 45, 60, 'GLOBAL-HPR-18X24', self::PHOTO_RAG, 31500, 350),
            $this->line('photo-rag-51x91', 51, 91, 'GLOBAL-HPR-20X36', self::PHOTO_RAG, 47000, 360),
            $this->line('photo-rag-a4', 21, 30, 'GLOBAL-HPR-A4', self::PHOTO_RAG, 10500, 370),
            $this->line('photo-rag-a3', 30, 42, 'GLOBAL-HPR-A3', self::PHOTO_RAG, 18000, 380),
            $this->line('photo-rag-a2', 42, 59, 'GLOBAL-HPR-A2', self::PHOTO_RAG, 29500, 390),
            $this->line('german-etching-20x20', 20, 20, 'GLOBAL-HGE-8X8', self::GERMAN_ETCHING, 7500, 400),
            $this->line('german-etching-30x30', 30, 30, 'GLOBAL-HGE-12X12', self::GERMAN_ETCHING, 14000, 410),
            $this->line('german-etching-40x40', 40, 40, 'GLOBAL-HGE-16X16', self::GERMAN_ETCHING, 21500, 420),
            $this->line('german-etching-45x45', 45, 45, 'GLOBAL-HGE-18X18', self::GERMAN_ETCHING, 25000, 425),
            $this->line('german-etching-20x30', 20, 30, 'GLOBAL-HGE-8X12', self::GERMAN_ETCHING, 10000, 430),
            $this->line('german-etching-30x45', 30, 45, 'GLOBAL-HGE-12X18', self::GERMAN_ETCHING, 18500, 440),
            $this->line('german-etching-40x60', 40, 60, 'GLOBAL-HGE-16X24', self::GERMAN_ETCHING, 29000, 450),
            $this->line('german-etching-23x30', 23, 30, 'GLOBAL-HGE-9X12', self::GERMAN_ETCHING, 11500, 460),
            $this->line('german-etching-30x40', 30, 40, 'GLOBAL-HGE-12X16', self::GERMAN_ETCHING, 17000, 470),
            $this->line('german-etching-45x60', 45, 60, 'GLOBAL-HGE-18X24', self::GERMAN_ETCHING, 31500, 480),
            $this->line('german-etching-51x91', 51, 91, 'GLOBAL-HGE-20X36', self::GERMAN_ETCHING, 47000, 490),
            $this->line('german-etching-a4', 21, 30, 'GLOBAL-HGE-A4', self::GERMAN_ETCHING, 10500, 500),
            $this->line('german-etching-a3', 30, 42, 'GLOBAL-HGE-A3', self::GERMAN_ETCHING, 18000, 510),
            $this->line('german-etching-a2', 42, 59, 'GLOBAL-HGE-A2', self::GERMAN_ETCHING, 29500, 520),
        ];
    }

    // One line of the range, its label composed from the size and the paper, and its resolution from the size alone
    private function line(string $slug, int $widthCm, int $heightCm, string $sku, array $paper, int $price, int $position): PrintCatalogueEntry
    {
        [$name, $description] = $paper;

        return new PrintCatalogueEntry($slug, sprintf('%d × %d cm — %s', $widthCm, $heightCm, $name), $widthCm, $heightCm, $sku, $name, $description, $price, $position, self::dpiFor(max($widthCm, $heightCm)));
    }

    // The resolution a size is offered at, by its long edge: 300 dpi is a print read in the hand, and a sheet past 30 cm hangs on a wall and is read from further - a 4000-pixel square prints at 40 cm perfectly well, and asking 300 dpi of it would refuse it
    private static function dpiFor(int $longEdgeCm): int
    {
        foreach (self::DPI_BY_LONG_EDGE as $limit => $dpi) {
            if ($longEdgeCm <= $limit) {
                return $dpi;
            }
        }

        return self::DPI_LARGE;
    }

    /**
     * Asks the lab about each reference at once rather than one after the other - a catalogue is a few dozen products,
     * and checking them in sequence would take a minute of an admin's time for nothing.
     *
     * @param list<string> $skus
     *
     * @return list<string>|null
     */
    public function findUnknownSkus(array $skus): ?array
    {
        $key = $this->environment->getApiKey();

        // Nothing to check with, which is not the same as everything checking out - the importer says so instead of claiming a verification it never made
        if (null === $key) {
            return null;
        }

        $base = $this->environment->getEndpoint();
        $statuses = [];
        $responses = [];

        try {
            foreach ($skus as $sku) {
                $responses[$sku] = $this->httpClient->request('GET', $base . '/products/' . $sku, [
                    'headers' => ['X-API-Key' => $key],
                ]);
            }

            // Every status is read, and every response dropped, before anything is decided: only the status is of interest, and a response left unread throws when it is disposed of - so leaving early would raise the error of a request nobody is waiting on any more
            foreach ($responses as $sku => $response) {
                $statuses[(string) $sku] = $response->getStatusCode();
                $response->cancel();
            }
        } catch (ExceptionInterface) {
            // Whatever is left unread is dropped here rather than at the end of the method, where disposing of a failed response would throw a second time - out of a catch, and in the admin's face instead of the "unchecked" this returns
            foreach ($responses as $response) {
                $response->cancel();
            }

            // The lab could not be reached at all, so no reference was checked and none is reported as missing
            return null;
        }

        $unknown = [];

        foreach ($statuses as $sku => $status) {
            // A refused key or a lab having a bad day says nothing about the product - answering "unknown" there would empty a catalogue that is perfectly good. Only a 404 is the lab actually saying it does not have it
            if (401 === $status || 403 === $status || $status >= 500) {
                return null;
            }

            if (200 !== $status) {
                $unknown[] = $sku;
            }
        }

        return $unknown;
    }
}
