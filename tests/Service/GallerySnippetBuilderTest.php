<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Service\GallerySnippetBuilder;
use PHPUnit\Framework\TestCase;

// What an image search reads of a photograph: the four properties a licence rests on, and the type a video is published under
class GallerySnippetBuilderTest extends TestCase
{
    public function testAPhotographIsPublishedAsAnImageObject(): void
    {
        $snippet = $this->builder()->buildMedia(
            $this->media(),
            'https://example.org/medias/gallery/lac.webp',
            'https://example.org/medias/gallery/lac-thumbnail.webp',
            'https://example.org/galerie/montagne/lac',
        );

        $this->assertSame('ImageObject', $snippet['@type']);
        $this->assertSame('https://schema.org', $snippet['@context']);
        $this->assertSame('Le lac', $snippet['name']);
        $this->assertSame('https://example.org/medias/gallery/lac.webp', $snippet['contentUrl']);
        $this->assertSame('https://example.org/medias/gallery/lac-thumbnail.webp', $snippet['thumbnailUrl']);
        $this->assertSame('https://example.org/galerie/montagne/lac', $snippet['url']);
    }

    // The three properties an image search prints a licence from, plus the person behind the line
    public function testAPhotographPublishesItsCreditAndItsCopyright(): void
    {
        $snippet = $this->builder()->buildMedia($this->media()->setCredits('Laurent Marquet')->setRightsReserved(true), 'https://example.org/lac.webp');

        $this->assertSame(['@type' => 'Person', 'name' => 'Laurent Marquet'], $snippet['creator']);
        $this->assertSame('Laurent Marquet', $snippet['creditText']);
        $this->assertSame('© Laurent Marquet', $snippet['copyrightNotice']);
    }

    // A photograph nobody claimed says nothing rather than a notice naming nobody
    public function testAPhotographWithoutACreditPublishesNeitherCreatorNorNotice(): void
    {
        $snippet = $this->builder()->buildMedia($this->media()->setRightsReserved(true), 'https://example.org/lac.webp');

        $this->assertArrayNotHasKey('creator', $snippet);
        $this->assertArrayNotHasKey('creditText', $snippet);
        $this->assertArrayNotHasKey('copyrightNotice', $snippet);
    }

    // The page a print is ordered on is this very one, which is what earns the licensable badge
    public function testAPrintablePhotographNamesThePageItsLicenceIsAcquiredOn(): void
    {
        $snippet = $this->builder()->buildMedia($this->media()->setPrintable(true), 'https://example.org/lac.webp', null, 'https://example.org/galerie/montagne/lac', true);

        $this->assertSame('https://example.org/galerie/montagne/lac', $snippet['acquireLicensePage']);
    }

    public function testAPhotographNotForSalePublishesNoLicencePage(): void
    {
        $snippet = $this->builder()->buildMedia($this->media(), 'https://example.org/lac.webp', null, 'https://example.org/galerie/montagne/lac');

        $this->assertArrayNotHasKey('acquireLicensePage', $snippet);
    }

    // The page prints no offer - a shop closed, a format nobody published: the photograph is still flagged, and the page it would name leads to nothing to order
    public function testAPrintablePhotographNamesNoPageWhereThePageOffersNothing(): void
    {
        $snippet = $this->builder()->buildMedia($this->media()->setPrintable(true), 'https://example.org/lac.webp', null, 'https://example.org/galerie/montagne/lac', false);

        $this->assertArrayNotHasKey('acquireLicensePage', $snippet);
    }

    // A video is read as a video: its own file is the content, the still is the thumbnail, and the date it was filed is an upload date
    public function testAVideoIsPublishedAsAVideoObject(): void
    {
        $media = $this->media()->setVideoFilename('lac.mp4');

        $snippet = $this->builder()->buildMedia($media, 'https://example.org/lac.mp4', 'https://example.org/lac.webp');

        $this->assertSame('VideoObject', $snippet['@type']);
        $this->assertArrayHasKey('uploadDate', $snippet);
        $this->assertArrayNotHasKey('datePublished', $snippet);
    }

    // A video framed from a platform has no file of the site's own: the player is what it names, and the still stays its thumbnail
    public function testAnEmbeddedVideoIsPublishedWithItsPlayer(): void
    {
        $media = $this->media()->setExternalUrl('https://www.youtube.com/watch?v=abcdefghijk');

        $snippet = $this->builder()->buildMedia($media, null, 'https://example.org/lac.webp', null, false, $media->getEmbedUrl());

        $this->assertSame('VideoObject', $snippet['@type']);
        $this->assertSame($media->getEmbedUrl(), $snippet['embedUrl']);
        $this->assertSame('https://example.org/lac.webp', $snippet['thumbnailUrl']);
        $this->assertArrayNotHasKey('contentUrl', $snippet);
    }

    // Only a video is framed: an image handed a player url would publish an ImageObject naming no image at all
    public function testAPhotographPublishesNoPlayer(): void
    {
        $snippet = $this->builder()->buildMedia($this->media(), 'https://example.org/lac.webp', null, null, false, 'https://www.youtube.com/embed/abcdefghijk');

        $this->assertArrayNotHasKey('embedUrl', $snippet);
    }

    // No file, no graph: an image object without a content url names no image, and a video with no player has nothing to play
    public function testAMediaWithoutAFilePublishesNothing(): void
    {
        $this->assertSame([], $this->builder()->buildMedia($this->media()));
    }

    // A photograph left untitled is named by its gallery rather than published nameless
    public function testAnUntitledPhotographTakesTheNameOfItsGallery(): void
    {
        $media = $this->media()->setTitle(null);

        $this->assertSame('Montagne', $this->builder()->buildMedia($media, 'https://example.org/lac.webp')['name']);
    }

    public function testAGalleryListsThePhotographsThePageShows(): void
    {
        $snippet = $this->builder()->buildGallery(
            $this->category(),
            [
                ['name' => 'Le lac', 'url' => 'https://example.org/galerie/montagne/lac'],
                ['name' => 'Le sommet', 'url' => 'https://example.org/galerie/montagne/sommet'],
            ],
            'https://example.org/galerie/montagne',
        );

        $this->assertSame('ImageGallery', $snippet['@type']);
        $this->assertSame('Montagne', $snippet['name']);
        $this->assertSame(2, $snippet['mainEntity']['numberOfItems']);
        $this->assertSame(
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Le sommet', 'url' => 'https://example.org/galerie/montagne/sommet'],
            $snippet['mainEntity']['itemListElement'][1]
        );
    }

    // A list whose positions skip one is malformed, so an entry pointing nowhere is dropped rather than numbered
    public function testAnEntryWithNothingToPointAtIsLeftOutOfTheList(): void
    {
        $snippet = $this->builder()->buildIndex([
            ['name' => 'Montagne', 'url' => 'https://example.org/galerie/montagne'],
            ['name' => 'Sans adresse', 'url' => ''],
            ['name' => 'Mer', 'url' => 'https://example.org/galerie/mer'],
        ]);

        $this->assertSame('ItemList', $snippet['@type']);
        $this->assertSame(2, $snippet['numberOfItems']);
        $this->assertSame([1, 2], array_column($snippet['itemListElement'], 'position'));
        $this->assertSame(['Montagne', 'Mer'], array_column($snippet['itemListElement'], 'name'));
    }

    public function testAnEmptyIndexPublishesNothing(): void
    {
        $this->assertSame([], $this->builder()->buildIndex([]));
        $this->assertSame('', $this->builder()->buildJson([]));
    }

    // A caption is rich text, and the graph carries the words only: the markup is stripped before the graph is even encoded
    public function testTheMarkupOfACaptionNeverReachesTheGraph(): void
    {
        $media = $this->media()->setDescription('</script><script>alert(1)</script>');

        $snippet = $this->builder()->buildMedia($media, 'https://example.org/lac.webp');

        $this->assertSame('alert(1)', $snippet['description']);
    }

    // The credit is published as typed, so a "</script>" left in it is what JSON_HEX_TAG is there for: it reaches the graph, escaped, and closes nothing
    public function testTheEncodedGraphNeverClosesItsOwnTag(): void
    {
        $media = $this->media()->setCredits('</script><script>alert(1)</script>');

        $json = $this->builder()->buildJson($this->builder()->buildMedia($media, 'https://example.org/lac.webp'));

        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringContainsString('\u003C/script\u003E', $json);
    }

    private function builder(): GallerySnippetBuilder
    {
        return new GallerySnippetBuilder();
    }

    private function media(): GalleryMedia
    {
        return new GalleryMedia()
            ->setTitle('Le lac')
            ->setSlug('lac')
            ->setCategory($this->category());
    }

    private function category(): GalleryCategory
    {
        return new GalleryCategory()
            ->setTitle('Montagne')
            ->setSlug('montagne');
    }
}
