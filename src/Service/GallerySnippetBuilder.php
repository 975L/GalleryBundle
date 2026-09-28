<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Model\GalleryLicense;
use c975L\GalleryBundle\Model\PrintOffer;
use c975L\UiBundle\Service\JsonLdBuilder;

// Builds the schema.org graph a photograph's, a gallery's and the index's page publish as JSON-LD, out of the fields they already show: a photograph is an ImageObject (a video a VideoObject) carrying the four properties Google's "Licensable" badge is drawn from, and its prints a Product of their own (see buildPrint()).
class GallerySnippetBuilder
{
    public function __construct(private readonly JsonLdBuilder $jsonLdBuilder = new JsonLdBuilder())
    {
    }

    // The urls ($contentUrl, $thumbnailUrl, $url, $licenseUrl from config "gallery-license-url") come absolute from the caller. $printAvailable is the offer the page itself prints (see gallery_print_available()), handed over rather than asked again so the graph answers what the visitor is looking at
    public function buildMedia(GalleryMedia $media, ?string $contentUrl = null, ?string $thumbnailUrl = null, ?string $url = null, bool $printAvailable = false, ?string $embedUrl = null, ?string $licenseUrl = null): array
    {
        $contentUrl = trim((string) $contentUrl);
        // Only a video is framed, so an image handed a player url publishes none rather than an ImageObject with nothing to name
        $embedUrl = $media->isVideo() ? trim((string) $embedUrl) : '';

        // No file and no player, no graph: an ImageObject without a contentUrl names no image, and a video with neither has nothing to play
        if ('' === $contentUrl && '' === $embedUrl) {
            return [];
        }

        return $this->clean([
            '@context' => 'https://schema.org',
            ...$this->media($media, $contentUrl, $thumbnailUrl, $url, $printAvailable, $embedUrl, trim((string) $licenseUrl)),
        ]);
    }

    // The gallery itself, as the collection of photographs it is: the medias are listed in the order the page prints them, each leading to its own page
    /**
     * @param list<array{name: string, url: string}> $items  the photographs the page shows, in reading order
     * @param int                                    $offset how many photographs the pages before this one showed, which the positions start after
     */
    public function buildGallery(GalleryCategory $category, array $items = [], ?string $url = null, int $offset = 0): array
    {
        $name = trim((string) $category->getTitle());

        if ('' === $name) {
            return [];
        }

        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => 'ImageGallery',
            'name' => $name,
            'url' => trim((string) $url),
            // The sentence the gallery is shared with, which is the only prose it carries (see GalleryCategory::$summarySocialNetwork)
            'description' => $this->jsonLdBuilder->plainText($category->getSummarySocialNetwork()),
            'mainEntity' => $this->itemList($items, $offset),
        ]);
    }

    /**
     * The galleries a visitor picks from, as the list the index prints - the same shape a shop's catalogue page publishes.
     *
     * @param list<array{name: string, url: string}> $items
     */
    public function buildIndex(array $items = []): array
    {
        $list = $this->itemList($items);

        return [] === $list ? [] : ['@context' => 'https://schema.org', ...$list];
    }

    // The prints of one photograph, as the Product a merchant listing reads: one Offer per size and paper, each at the price the page prints under it
    /**
     * @param list<PrintOffer> $offers    the sizes the page offers
     * @param ?int             $remaining what is left of a numbered edition, null for an open one: none left is sold out
     */
    public function buildPrint(GalleryMedia $media, array $offers, string $currency, ?int $remaining = null, ?string $imageUrl = null, ?string $url = null): array
    {
        $name = trim((string) $media->getTitle());
        $currency = trim($currency);

        if ('' === $name || '' === $currency || [] === $offers) {
            return [];
        }

        $availability = 0 === $remaining ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock';

        return $this->clean([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $name,
            'image' => trim((string) $imageUrl),
            'description' => $this->jsonLdBuilder->plainText($media->getDescription()),
            'offers' => array_map(fn (PrintOffer $offer): array => $this->clean([
                '@type' => 'Offer',
                'name' => trim((string) $offer->format->getLabel()),
                // Prices are stored in cents, schema.org expects the amount as it is charged
                'price' => number_format((int) $offer->format->getPrice() / 100, 2, '.', ''),
                'priceCurrency' => $currency,
                'availability' => $availability,
                'itemCondition' => 'https://schema.org/NewCondition',
                'url' => trim((string) $url),
            ]), $offers),
        ]);
    }

    // The same graph, encoded for a <script type="application/ld+json">; empty string when there is nothing to publish
    public function buildJson(array $snippet): string
    {
        return $this->jsonLdBuilder->encode($snippet);
    }

    // A video is a type of its own rather than an image carrying a file: an image search reads none of a video's own properties off an ImageObject, and the still is its thumbnail, never its content
    private function media(GalleryMedia $media, string $contentUrl, ?string $thumbnailUrl, ?string $url, bool $printAvailable, string $embedUrl, string $licenseUrl): array
    {
        $video = $media->isVideo();
        $published = $media->getCreatedAt()?->format('Y-m-d') ?? '';

        return [
            '@type' => $video ? 'VideoObject' : 'ImageObject',
            'name' => $this->name($media),
            'description' => $this->jsonLdBuilder->plainText($media->getDescription()),
            'contentUrl' => $contentUrl,
            // Where a video hosted elsewhere is played, which is what schema.org reads in place of a file it cannot fetch - the very url the player is framed with (see components/Gallery/Video.html.twig)
            'embedUrl' => $embedUrl,
            'thumbnailUrl' => trim((string) $thumbnailUrl),
            // What a video rich result is refused without, and what an image carries all the same: the day it was filed
            $video ? 'uploadDate' : 'datePublished' => $published,
            'url' => trim((string) $url),
            // Who took it, and the line the page prints under it - a name and its wording being two different things to a machine
            'creator' => $this->creator($media),
            'creditText' => trim((string) $media->getCredits()),
            'copyrightNotice' => $this->copyrightNotice($media),
            // The licence of the gallery the photograph is filed in (a Creative Commons deed, or the site's page when all rights are reserved), and where more is acquired: this very page when a print is ordered on it (see print/_offer.html.twig), the site's page otherwise
            'license' => GalleryLicense::deedUrl($media->getCategory()?->getLicense() ?? GalleryLicense::RESERVED) ?? $licenseUrl,
            'acquireLicensePage' => $printAvailable ? trim((string) $url) : $licenseUrl,
        ];
    }

    // The photograph's own title, and failing that its gallery's: an image search prints the name, and a photograph left untitled would be published as a nameless one
    private function name(GalleryMedia $media): string
    {
        $name = trim((string) $media->getTitle());

        return '' === $name ? trim((string) $media->getCategory()?->getTitle()) : $name;
    }

    // The credit read as a person: it is the name typed under the photograph, which is who took it unless the site left it empty
    private function creator(GalleryMedia $media): array
    {
        $credits = trim((string) $media->getCredits());

        return '' === $credits ? [] : ['@type' => 'Person', 'name' => $credits];
    }

    // Said only where the box is ticked: a photograph nobody claimed publishes no notice rather than one naming nobody
    private function copyrightNotice(GalleryMedia $media): string
    {
        if (!$media->isRightsReserved()) {
            return '';
        }

        $credits = trim((string) $media->getCredits());

        return '' === $credits ? '' : '© ' . $credits;
    }

    // The shared ItemList without its "@context": nested in an ImageGallery it is a property, and the index adds the context back itself
    /**
     * @param list<array{name: string, url: string}> $items
     * @param int                                    $offset the entries the pages before this one listed, which the positions start after
     */
    private function itemList(array $items, int $offset = 0): array
    {
        return array_diff_key($this->jsonLdBuilder->itemList($items, $offset), ['@context' => true]);
    }

    // Drops everything left empty, so an unfilled field never reaches the graph as a blank property
    private function clean(array $snippet): array
    {
        return array_filter($snippet, static fn ($value) => !\in_array($value, ['', [], null, 0], true));
    }
}
