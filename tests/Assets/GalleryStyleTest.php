<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Assets;

use c975L\GalleryBundle\Twig\Extension\GalleryStyleExtension;
use PHPUnit\Framework\TestCase;

// A ready-made style, and the passe-partout beside it, are each spelled in four places by necessity - the list an admin picks from (config/configs.json), the list that reaches the markup (GalleryStyleExtension), the templates handing it to the layout and the block that paints it (the stylesheet)
// A value missing from any of them fails nothing at runtime: it is simply offered and paints the site's colors, which is exactly the silence this catches
class GalleryStyleTest extends TestCase
{
    public function testTheOfferedValuesAreTheOnesWrittenOut(): void
    {
        $this->assertSame(GalleryStyleExtension::STYLES, $this->declaredChoices('gallery-style'), 'The "gallery-style" config and GalleryStyleExtension no longer offer the same styles, so one of them is either unreachable or silently dropped.');
        $this->assertSame(GalleryStyleExtension::FRAMES, $this->declaredChoices('gallery-frame'), 'The "gallery-frame" config and GalleryStyleExtension no longer offer the same widths, so one of them is either unreachable or silently dropped.');
    }

    public function testEveryValueCarriesItsOwnBlock(): void
    {
        $css = $this->compiledStylesheet();

        foreach (GalleryStyleExtension::STYLES as $style) {
            $this->assertStringContainsString(sprintf(':root:has(body.gallery-page--%s)', $style), $css, sprintf('The stylesheet paints nothing for the "%s" style, so picking it leaves the gallery in the site\'s own colors.', $style));
        }

        foreach (GalleryStyleExtension::FRAMES as $frame) {
            $this->assertStringContainsString(sprintf(':root:has(body.gallery-page--frame-%s)', $frame), $css, sprintf('The stylesheet sets no width for the "%s" frame, so picking it leaves the passe-partout at the token\'s own default.', $frame));
        }
    }

    // The whole point of a style: it retunes the palette every bundle paints from, rather than a namespace only this bundle's own rules read
    // SiteBundle writes "color: var(--text)" through a "*" rule, so a color declared on the body alone never reaches the h1 or a composed block - a real declaration always beating an inherited value - and a style written that way would leave a gallery's title in the site's color on the gallery's own ground
    public function testEveryStyleRetunesTheSitesOwnPalette(): void
    {
        foreach (GalleryStyleExtension::STYLES as $style) {
            $block = $this->styleBlock($style);

            foreach (['--background', '--text'] as $token) {
                $this->assertMatchesRegularExpression(sprintf('/(?<![a-z-])%s:/', preg_quote($token, '/')), $block, sprintf('The "%s" style never declares "%s", so the site\'s own color still paints everything the gallery does not style itself.', $style, $token));
            }
        }
    }

    // Every screen of the viewer, the index and the medias as much as the categories: a ground the visitor loses on opening a photo is worse than no ground at all
    public function testEveryViewerPageCarriesTheBodyClass(): void
    {
        $templates = glob(\dirname(__DIR__, 2) . '/templates/gallery/*.html.twig') ?: [];
        $this->assertNotSame([], $templates, 'No page of the viewer was read, this test no longer checks anything.');

        foreach ($templates as $template) {
            $this->assertStringContainsString('{% block bodyClass %}{{ gallery_body_class() }}{% endblock %}', (string) file_get_contents($template), sprintf('"%s" never fills the layout\'s "bodyClass" block, so that page alone stays on the site\'s own background.', basename($template)));
        }
    }

    // A rating is a flex row of its own (UiBundle's .rating-vote), so the centring a gallery page states in text has no hold on it - without this rule the score sits at the left edge of a page whose every other line is centred, and nothing at runtime says so
    public function testTheScoreIsCentredOnTheGalleryPages(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.gallery-page \.rating-vote\s*\{[^}]*justify-content:\s*center/s',
            $this->compiledStylesheet(),
            'The stylesheet no longer centres the rating on a gallery page, so the score reads at the left edge under a centred media.'
        );
    }

    // The caption is framed like every other panel of the site rather than in a look of its own, which only holds while the rule really reads the site's surface, radius and shadow through the four card tokens
    public function testTheCaptionIsFramedAsACard(): void
    {
        preg_match('/\.gallery-media-description\s*\{([^}]*)\}/', $this->compiledStylesheet(), $matches);

        $this->assertNotEmpty($matches, 'No block was read for ".gallery-media-description", this test no longer checks anything.');

        foreach (['padding', 'background', 'border-radius', 'box-shadow'] as $property) {
            $this->assertMatchesRegularExpression(
                sprintf('/%s:\s*var\(--gallery-media-description-/', preg_quote($property, '/')),
                $matches[1],
                sprintf('".gallery-media-description" no longer sets its "%s" from a token, so the caption is read outside the card a site can retune.', $property)
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function declaredChoices(string $slug): array
    {
        $configs = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/config/configs.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($configs);

        foreach ($configs as $config) {
            if ($slug === ($config['slug'] ?? null)) {
                return $config['choices'] ?? [];
            }
        }

        $this->fail(sprintf('config/configs.json declares no "%s" entry, so nothing can be picked from the back office.', $slug));
    }

    private function styleBlock(string $style): string
    {
        preg_match(sprintf('/:root:has\(body\.gallery-page--%s\)\s*\{([^}]*)\}/', preg_quote($style, '/')), $this->compiledStylesheet(), $matches);

        $this->assertNotEmpty($matches, sprintf('No block was read for the "%s" style, this test no longer checks anything.', $style));

        return $matches[1];
    }

    private function compiledStylesheet(): string
    {
        $path = \dirname(__DIR__, 2) . '/public/css/styles.css';
        $this->assertFileExists($path, 'styles.css is missing, the sass has not been compiled.');

        return (string) file_get_contents($path);
    }
}
