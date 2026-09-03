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

// Builds the schema.org graph a photograph's, a gallery's and the index's page publish as JSON-LD, out of the fields those pages already show.
// A photograph is an ImageObject and a video a VideoObject, which is what an image search reads: the four properties a licence rests on - who took it, what the credit says, what the copyright says, and where a print is bought - are the very ones Google's "Licensable" badge is drawn from, and they are already typed in the back office.
// No offers node here: what a print costs belongs to whoever sells it (see ShopBundle, the one place of the ecosystem emitting one), and this graph only says where the page offering it is.
class GallerySnippetBuilder
{
    // $contentUrl, $thumbnailUrl and $url are resolved by the caller, only a template turning a stored file into an absolute url
    // $printAvailable is the offer the page itself prints under the photograph (see gallery_print_available()), handed over rather than asked again: it is the only thing "acquireLicensePage" may name, and a second reading of the shop would answer a different question than the one the visitor is looking at
    public function buildMedia(GalleryMedia $media, ?string $contentUrl = null, ?string $thumbnailUrl = null, ?string $url = null, bool $printAvailable = false, ?string $embedUrl = null): array
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
            ...$this->media($media, $contentUrl, $thumbnailUrl, $url, $printAvailable, $embedUrl),
        ]);
    }

    // The gallery itself, as the collection of photographs it is: the medias are listed in the order the page prints them, each leading to its own page
    /**
     * @param list<array{name: string, url: string}> $items the photographs the page shows, in reading order
     */
    public function buildGallery(GalleryCategory $category, array $items = [], ?string $url = null): array
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
            'description' => $this->plainText($category->getSummarySocialNetwork()),
            'mainEntity' => $this->itemList($items),
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

    // The same graph, encoded for a <script type="application/ld+json">; empty string when there is nothing to publish
    public function buildJson(array $snippet): string
    {
        if ([] === $snippet) {
            return '';
        }

        // JSON_HEX_TAG keeps a "</script>" typed into a field from closing the tag, JSON_INVALID_UTF8_SUBSTITUTE keeps a stray byte from emptying the whole graph
        return json_encode($snippet, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // A video is a type of its own rather than an image carrying a file: an image search reads none of a video's own properties off an ImageObject, and the still is its thumbnail, never its content
    private function media(GalleryMedia $media, string $contentUrl, ?string $thumbnailUrl, ?string $url, bool $printAvailable, string $embedUrl): array
    {
        $video = $media->isVideo();
        $published = $media->getCreatedAt()?->format('Y-m-d') ?? '';

        return [
            '@type' => $video ? 'VideoObject' : 'ImageObject',
            'name' => $this->name($media),
            'description' => $this->plainText($media->getDescription()),
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
            // Where the licence is acquired, which is this very page: it is where the print is ordered (see print/_offer.html.twig), and it is what earns the licensable badge in an image search
            'acquireLicensePage' => $printAvailable ? trim((string) $url) : '',
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

    /**
     * @param list<array{name: string, url: string}> $items
     */
    private function itemList(array $items): array
    {
        $elements = [];
        $position = 0;

        foreach ($items as $item) {
            $name = trim($item['name']);
            $url = trim($item['url']);

            // An entry with nothing to point at is dropped rather than numbered: a list whose positions skip one is malformed
            if ('' === $name || '' === $url) {
                continue;
            }

            $elements[] = [
                '@type' => 'ListItem',
                'position' => ++$position,
                'name' => $name,
                'url' => $url,
            ];
        }

        if ([] === $elements) {
            return [];
        }

        return [
            '@type' => 'ItemList',
            // What this page holds and not what the whole gallery does: a page that grows on scroll publishes what it was served with
            'numberOfItems' => count($elements),
            'itemListElement' => $elements,
        ];
    }

    // A description is rich text; a graph carries the words only
    private function plainText(mixed $html): string
    {
        $text = html_entity_decode(strip_tags((string) $html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    // Drops everything left empty, so an unfilled field never reaches the graph as a blank property
    private function clean(array $snippet): array
    {
        return array_filter($snippet, static fn ($value) => !\in_array($value, ['', [], null, 0], true));
    }
}
