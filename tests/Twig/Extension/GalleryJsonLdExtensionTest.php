<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Twig\Extension;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Service\GallerySnippetBuilder;
use c975L\GalleryBundle\Twig\Extension\GalleryJsonLdExtension;
use PHPUnit\Framework\TestCase;
use Twig\Attribute\AsTwigFunction;
use Twig\Extension\AttributeExtension;

// What the three public pages call, the graph itself being GallerySnippetBuilder's business (see GallerySnippetBuilderTest)
class GalleryJsonLdExtensionTest extends TestCase
{
    public function testGetFunctionsExposesTheThreeGraphs(): void
    {
        $names = array_map(
            static fn ($function): string => $function->getName(),
            new AttributeExtension(GalleryJsonLdExtension::class)->getFunctions()
        );

        $this->assertSame(['gallery_media_json_ld', 'gallery_json_ld', 'gallery_index_json_ld'], $names);
    }

    // The payload is written straight into a <script>, so it is marked safe rather than escaped
    public function testTheThreeGraphsAreMarkedSafeForHtml(): void
    {
        foreach (new \ReflectionClass(GalleryJsonLdExtension::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(AsTwigFunction::class) as $attribute) {
                $this->assertSame(['html'], $attribute->newInstance()->isSafe, $method->getName());
            }
        }
    }

    public function testAPhotographIsHandedOverEncoded(): void
    {
        $json = $this->extension()->mediaJsonLd($this->media(), 'https://example.org/lac.webp', null, 'https://example.org/galerie/montagne/lac');

        $this->assertJson($json);
        $this->assertSame('ImageObject', json_decode($json, true)['@type']);
    }

    // The offer and the player travel through the function as they are given, the page being the one that knows both
    public function testTheOfferAndThePlayerReachTheGraph(): void
    {
        $media = $this->media()->setExternalUrl('https://www.youtube.com/watch?v=abcdefghijk');

        $snippet = json_decode(
            $this->extension()->mediaJsonLd($media, null, 'https://example.org/lac.webp', 'https://example.org/galerie/montagne/lac', true, $media->getEmbedUrl()),
            true
        );

        $this->assertSame('VideoObject', $snippet['@type']);
        $this->assertSame($media->getEmbedUrl(), $snippet['embedUrl']);
        $this->assertSame('https://example.org/galerie/montagne/lac', $snippet['acquireLicensePage']);
    }

    public function testAGalleryAndTheIndexAreHandedOverEncoded(): void
    {
        $items = [['name' => 'Le lac', 'url' => 'https://example.org/galerie/montagne/lac']];

        $this->assertSame('ImageGallery', json_decode($this->extension()->galleryJsonLd($this->category(), $items), true)['@type']);
        $this->assertSame('ItemList', json_decode($this->extension()->indexJsonLd($items), true)['@type']);
    }

    // Nothing to publish is an empty string and not "[]", the template testing it before opening its <script>
    public function testNothingToPublishIsAnEmptyString(): void
    {
        $this->assertSame('', $this->extension()->mediaJsonLd($this->media()));
        $this->assertSame('', $this->extension()->indexJsonLd([]));
    }

    private function extension(): GalleryJsonLdExtension
    {
        return new GalleryJsonLdExtension(new GallerySnippetBuilder());
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
