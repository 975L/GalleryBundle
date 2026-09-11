<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\ConfigBundle\Service\LocalizedUrlGenerator;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use c975L\GalleryBundle\Service\GalleryLinkLocalizer;
use PHPUnit\Framework\TestCase;

class GalleryLinkLocalizerTest extends TestCase
{
    // A visitor reading the site in English follows a tile into English: each of the three screens is handed to its own route, the media first since its url is a category url with one more segment
    public function testEachScreenIsReadInTheLanguageBeingRead(): void
    {
        $localizer = $this->localizer();

        $this->assertSame('/en/galerie/voyages/lac', $localizer->localize('/galerie/voyages/lac'));
        $this->assertSame('/en/galerie/voyages', $localizer->localize('/galerie/voyages'));
        $this->assertSame('/en/galerie', $localizer->localize('/galerie'));
    }

    // A word linked inside a rich text is a link like any other; the same words elsewhere in the prose are prose
    public function testTheLinksOfARichTextAreRewrittenAndItsProseIsNot(): void
    {
        $this->assertSame(
            'Voir <a href="/en/galerie/voyages">nos voyages</a>, pas /galerie/voyages en toutes lettres',
            $this->localizer()->localize('Voir <a href="/galerie/voyages">nos voyages</a>, pas /galerie/voyages en toutes lettres'),
        );
    }

    // The prefix read is the one the site configured, so another first segment, another host or a deeper path is somebody else's url
    public function testAnythingElseIsGivenBackUntouched(): void
    {
        $localizer = $this->localizer();

        foreach (['/gallery/voyages', '/pages/galerie', 'https://example.com/galerie', '/galerie/voyages/lac/extra', '#top', ''] as $value) {
            $this->assertSame($value, $localizer->localize($value));
        }
    }

    private function localizer(): GalleryLinkLocalizer
    {
        $prefix = $this->createStub(GalleryRoutePrefix::class);
        $prefix->method('get')->willReturn('galerie');

        // The generator's own rule reduced to what these assertions read: the language in front, then the prefix and the parameters in route order
        $generator = $this->createStub(LocalizedUrlGenerator::class);
        $generator->method('path')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => rtrim('/en/galerie/' . implode('/', $parameters), '/')
        );

        return new GalleryLinkLocalizer($prefix, $generator);
    }
}
