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

/**
 * Prodigi's range, as a shop is worth starting from: the five shapes a photograph comes in - square, 3:2, 4:3, 16:9
 * and the ISO sizes - three of each, on the four papers that cover a gallery - photographic for saturated colour, matte art for everyday prints,
 * and the two cottons an art print is actually sold on: Hahnemühle Photo Rag, smooth and safe on any subject, and
 * Hahnemühle German Etching, whose texture is what black and white and matter are printed on.
 *
 * Held as plain data, on the model of GallerySampleCatalog, and sitting beside the driver rather than in the bundle's
 * trunk: this is what Prodigi sells and nothing here is true of any other lab.
 *
 * Every reference below was read back from GET /products/{sku} rather than composed from the naming pattern - which is
 * how the small 16:9 turned out to be printed on matte art alone, and the 23x30 to be missing from the Photo Rag.
 *
 * Each line carries the paper on its own, with the sentence saying what that paper is for: four papers across three
 * sizes is twelve lines a visitor reads as one flat list, where the same sizes under four described headings is a
 * choice he can actually make.
 *
 * Deliberately short of everything the lab prints. What is left out is not missing: a catalogue is read by the admin
 * pricing it, and a hundred lines to sort through is how a shop ends up publishing none of them. A size wanted and not
 * here is one row to add by hand, its reference following the lab's own pattern - inches, and the paper's prefix.
 *
 * The prices are placeholders on a plain curve (roughly 0.60 EUR per cm^0.75, a fifth off on the photographic paper),
 * rounded to five euros. They are there so a catalogue does not import at zero, and they are not anyone's prices: the
 * importer brings every line in unpublished, and pricing them is the first thing an admin does.
 */
class ProdigiCatalogue implements PrintCatalogueProviderInterface
{
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
            new PrintCatalogueEntry('lustre-20x20', '20 × 20 cm — Photographic Art Print 240 g', 20, 20, 'GLOBAL-PAP-8X8', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 4500, 10),
            new PrintCatalogueEntry('lustre-30x30', '30 × 30 cm — Photographic Art Print 240 g', 30, 30, 'GLOBAL-PAP-12X12', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 8000, 20),
            new PrintCatalogueEntry('lustre-40x40', '40 × 40 cm — Photographic Art Print 240 g', 40, 40, 'GLOBAL-PAP-16X16', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 12000, 30),
            new PrintCatalogueEntry('lustre-20x30', '20 × 30 cm — Photographic Art Print 240 g', 20, 30, 'GLOBAL-PAP-8X12', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 6000, 40),
            new PrintCatalogueEntry('lustre-30x45', '30 × 45 cm — Photographic Art Print 240 g', 30, 45, 'GLOBAL-PAP-12X18', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 10500, 50),
            new PrintCatalogueEntry('lustre-40x60', '40 × 60 cm — Photographic Art Print 240 g', 40, 60, 'GLOBAL-PAP-16X24', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 16500, 60),
            new PrintCatalogueEntry('lustre-23x30', '23 × 30 cm — Photographic Art Print 240 g', 23, 30, 'GLOBAL-PAP-9X12', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 6500, 70),
            new PrintCatalogueEntry('lustre-30x40', '30 × 40 cm — Photographic Art Print 240 g', 30, 40, 'GLOBAL-PAP-12X16', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 10000, 80),
            new PrintCatalogueEntry('lustre-45x60', '45 × 60 cm — Photographic Art Print 240 g', 45, 60, 'GLOBAL-PAP-18X24', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 18000, 90),
            new PrintCatalogueEntry('lustre-51x91', '51 × 91 cm — Photographic Art Print 240 g', 51, 91, 'GLOBAL-PAP-20X36', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 27000, 100),
            new PrintCatalogueEntry('lustre-a4', '21 × 30 cm — Photographic Art Print 240 g', 21, 30, 'GLOBAL-PAP-A4', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 6000, 110),
            new PrintCatalogueEntry('lustre-a3', '30 × 42 cm — Photographic Art Print 240 g', 30, 42, 'GLOBAL-PAP-A3', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 10000, 120),
            new PrintCatalogueEntry('lustre-a2', '42 × 59 cm — Photographic Art Print 240 g', 42, 59, 'GLOBAL-PAP-A2', 'Photographic Art Print 240 g', 'A satin photographic paper, its slight pearl holding saturated colour - the everyday print, and the one a photograph of a vehicle, a flower or a graffiti is best served by.', 17000, 130),
            new PrintCatalogueEntry('mat-20x20', '20 × 20 cm — Enhanced Matte Art 200 g', 20, 20, 'GLOBAL-FAP-8X8', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 5500, 140),
            new PrintCatalogueEntry('mat-30x30', '30 × 30 cm — Enhanced Matte Art 200 g', 30, 30, 'GLOBAL-FAP-12X12', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 10000, 150),
            new PrintCatalogueEntry('mat-40x40', '40 × 40 cm — Enhanced Matte Art 200 g', 40, 40, 'GLOBAL-FAP-16X16', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 15000, 160),
            new PrintCatalogueEntry('mat-20x30', '20 × 30 cm — Enhanced Matte Art 200 g', 20, 30, 'GLOBAL-FAP-8X12', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 7500, 170),
            new PrintCatalogueEntry('mat-30x45', '30 × 45 cm — Enhanced Matte Art 200 g', 30, 45, 'GLOBAL-FAP-12X18', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 13500, 180),
            new PrintCatalogueEntry('mat-40x60', '40 × 60 cm — Enhanced Matte Art 200 g', 40, 60, 'GLOBAL-FAP-16X24', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 20500, 190),
            new PrintCatalogueEntry('mat-23x30', '23 × 30 cm — Enhanced Matte Art 200 g', 23, 30, 'GLOBAL-FAP-9X12', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 8000, 200),
            new PrintCatalogueEntry('mat-30x40', '30 × 40 cm — Enhanced Matte Art 200 g', 30, 40, 'GLOBAL-FAP-12X16', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 12000, 210),
            new PrintCatalogueEntry('mat-45x60', '45 × 60 cm — Enhanced Matte Art 200 g', 45, 60, 'GLOBAL-FAP-18X24', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 22500, 220),
            new PrintCatalogueEntry('mat-23x41', '23 × 41 cm — Enhanced Matte Art 200 g', 23, 41, 'GLOBAL-FAP-9X16', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 10000, 230),
            new PrintCatalogueEntry('mat-51x91', '51 × 91 cm — Enhanced Matte Art 200 g', 51, 91, 'GLOBAL-FAP-20X36', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 33500, 240),
            new PrintCatalogueEntry('mat-a4', '21 × 30 cm — Enhanced Matte Art 200 g', 21, 30, 'GLOBAL-FAP-A4', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 7500, 250),
            new PrintCatalogueEntry('mat-a3', '30 × 42 cm — Enhanced Matte Art 200 g', 30, 42, 'GLOBAL-FAP-A3', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 12500, 260),
            new PrintCatalogueEntry('mat-a2', '42 × 59 cm — Enhanced Matte Art 200 g', 42, 59, 'GLOBAL-FAP-A2', 'Enhanced Matte Art 200 g', 'A matte art paper with no shine at all, so nothing of the image is lost to a reflection - the plain frame-ready print.', 21000, 270),
            new PrintCatalogueEntry('photo-rag-20x20', '20 × 20 cm — Hahnemühle Photo Rag 308 g', 20, 20, 'GLOBAL-HPR-8X8', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 7500, 280),
            new PrintCatalogueEntry('photo-rag-30x30', '30 × 30 cm — Hahnemühle Photo Rag 308 g', 30, 30, 'GLOBAL-HPR-12X12', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 14000, 290),
            new PrintCatalogueEntry('photo-rag-40x40', '40 × 40 cm — Hahnemühle Photo Rag 308 g', 40, 40, 'GLOBAL-HPR-16X16', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 21500, 300),
            new PrintCatalogueEntry('photo-rag-20x30', '20 × 30 cm — Hahnemühle Photo Rag 308 g', 20, 30, 'GLOBAL-HPR-8X12', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 10000, 310),
            new PrintCatalogueEntry('photo-rag-30x45', '30 × 45 cm — Hahnemühle Photo Rag 308 g', 30, 45, 'GLOBAL-HPR-12X18', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 18500, 320),
            new PrintCatalogueEntry('photo-rag-40x60', '40 × 60 cm — Hahnemühle Photo Rag 308 g', 40, 60, 'GLOBAL-HPR-16X24', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 29000, 330),
            new PrintCatalogueEntry('photo-rag-30x40', '30 × 40 cm — Hahnemühle Photo Rag 308 g', 30, 40, 'GLOBAL-HPR-12X16', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 17000, 340),
            new PrintCatalogueEntry('photo-rag-45x60', '45 × 60 cm — Hahnemühle Photo Rag 308 g', 45, 60, 'GLOBAL-HPR-18X24', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 31500, 350),
            new PrintCatalogueEntry('photo-rag-51x91', '51 × 91 cm — Hahnemühle Photo Rag 308 g', 51, 91, 'GLOBAL-HPR-20X36', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 47000, 360),
            new PrintCatalogueEntry('photo-rag-a4', '21 × 30 cm — Hahnemühle Photo Rag 308 g', 21, 30, 'GLOBAL-HPR-A4', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 10500, 370),
            new PrintCatalogueEntry('photo-rag-a3', '30 × 42 cm — Hahnemühle Photo Rag 308 g', 30, 42, 'GLOBAL-HPR-A3', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 18000, 380),
            new PrintCatalogueEntry('photo-rag-a2', '42 × 59 cm — Hahnemühle Photo Rag 308 g', 42, 59, 'GLOBAL-HPR-A2', 'Hahnemühle Photo Rag 308 g', 'A smooth 100% cotton rag, the safest of the fine art papers: it flatters fine detail and betrays no subject. The one to take when hesitating.', 29500, 390),
            new PrintCatalogueEntry('german-etching-20x20', '20 × 20 cm — Hahnemühle German Etching 310 g', 20, 20, 'GLOBAL-HGE-8X8', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 7500, 400),
            new PrintCatalogueEntry('german-etching-30x30', '30 × 30 cm — Hahnemühle German Etching 310 g', 30, 30, 'GLOBAL-HGE-12X12', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 14000, 410),
            new PrintCatalogueEntry('german-etching-40x40', '40 × 40 cm — Hahnemühle German Etching 310 g', 40, 40, 'GLOBAL-HGE-16X16', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 21500, 420),
            new PrintCatalogueEntry('german-etching-20x30', '20 × 30 cm — Hahnemühle German Etching 310 g', 20, 30, 'GLOBAL-HGE-8X12', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 10000, 430),
            new PrintCatalogueEntry('german-etching-30x45', '30 × 45 cm — Hahnemühle German Etching 310 g', 30, 45, 'GLOBAL-HGE-12X18', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 18500, 440),
            new PrintCatalogueEntry('german-etching-40x60', '40 × 60 cm — Hahnemühle German Etching 310 g', 40, 60, 'GLOBAL-HGE-16X24', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 29000, 450),
            new PrintCatalogueEntry('german-etching-23x30', '23 × 30 cm — Hahnemühle German Etching 310 g', 23, 30, 'GLOBAL-HGE-9X12', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 11500, 460),
            new PrintCatalogueEntry('german-etching-30x40', '30 × 40 cm — Hahnemühle German Etching 310 g', 30, 40, 'GLOBAL-HGE-12X16', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 17000, 470),
            new PrintCatalogueEntry('german-etching-45x60', '45 × 60 cm — Hahnemühle German Etching 310 g', 45, 60, 'GLOBAL-HGE-18X24', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 31500, 480),
            new PrintCatalogueEntry('german-etching-51x91', '51 × 91 cm — Hahnemühle German Etching 310 g', 51, 91, 'GLOBAL-HGE-20X36', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 47000, 490),
            new PrintCatalogueEntry('german-etching-a4', '21 × 30 cm — Hahnemühle German Etching 310 g', 21, 30, 'GLOBAL-HGE-A4', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 10500, 500),
            new PrintCatalogueEntry('german-etching-a3', '30 × 42 cm — Hahnemühle German Etching 310 g', 30, 42, 'GLOBAL-HGE-A3', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 18000, 510),
            new PrintCatalogueEntry('german-etching-a2', '42 × 59 cm — Hahnemühle German Etching 310 g', 42, 59, 'GLOBAL-HGE-A2', 'Hahnemühle German Etching 310 g', 'A velvety-textured 100% cotton rag, whose grain is what black and white, architecture and matter are printed on. The signed print.', 29500, 520),
        ];
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
