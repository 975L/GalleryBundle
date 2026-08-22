<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Assets;

use c975L\UiBundle\Video\VideoPlatform;
use PHPUnit\Framework\TestCase;

// The shape of a player is declared twice by necessity - in UiBundle's registry, which is PHP, and in this bundle's stylesheet, which is what actually frames it, a CSP forbidding the inline style that would carry the value across
// Nothing keeps the two in step at runtime, so this does: declaring a platform in the registry without giving it a ratio here fails the build rather than shipping a portrait video in a landscape frame
class VideoPlatformRatioTest extends TestCase
{
    private const string VARIABLES = 'sass/_variables.scss';
    private const string STYLESHEET = 'sass/_gallery.scss';
    private const string THEME = 'scaffold/assets/styles/themes/gallery.css';

    public function testEveryDeclaredPlatformCarriesItsRegistryRatio(): void
    {
        $variables = $this->read(self::VARIABLES);

        foreach (VideoPlatform::cases() as $platform) {
            $this->assertMatchesRegularExpression(
                sprintf('/--gallery-video-ratio-%s:\s*%s;/', preg_quote($platform->value, '/'), preg_quote($platform->aspectRatio(), '/')),
                $variables,
                sprintf('"%s" declares no "%s" ratio for "%s", so its player is framed in the default shape.', self::VARIABLES, $platform->aspectRatio(), $platform->value)
            );
        }
    }

    // The variable is nothing without a rule reading it - the class the component writes is "gallery-video--{mediaType}", which is the platform's own value
    public function testEveryDeclaredPlatformHasAModifierReadingItsRatio(): void
    {
        $stylesheet = $this->read(self::STYLESHEET);

        foreach (VideoPlatform::cases() as $platform) {
            $this->assertMatchesRegularExpression(
                sprintf('/\.gallery-video--%s\s*\{[^}]*aspect-ratio:\s*var\(--gallery-video-ratio-%s\)/s', preg_quote($platform->value, '/'), preg_quote($platform->value, '/')),
                $stylesheet,
                sprintf('"%s" has no ".gallery-video--%s" rule reading its own ratio.', self::STYLESHEET, $platform->value)
            );
        }
    }

    // A video framed from a platform nobody declared carries the "embed" type, which no modifier matches - the shape it lands on is the one on the base class
    public function testTheBaseClassCarriesADefaultRatioForUndeclaredPlatforms(): void
    {
        $this->assertMatchesRegularExpression('/--gallery-video-ratio-default:\s*16 \/ 9;/', $this->read(self::VARIABLES));
        $this->assertMatchesRegularExpression('/\.gallery-video\s*\{[^}]*aspect-ratio:\s*var\(--gallery-video-ratio-default\)/s', $this->read(self::STYLESHEET));
    }

    // A video of the site's own is the one player carrying no declared shape - the browser reads it off the file, so what has to be guarded is the cap keeping a portrait one inside the viewport, and the fact that it is spelled as a height where a framed player's is spelled as a width
    public function testAVideoOfTheSitesOwnIsCappedByTheViewportHeight(): void
    {
        $this->assertMatchesRegularExpression('/--gallery-video-self-hosted-max-height:\s*70vh;/', $this->read(self::VARIABLES));
        $this->assertMatchesRegularExpression('/\.gallery-video--video video\s*\{[^}]*max-height:\s*var\(--gallery-video-self-hosted-max-height\)/s', $this->read(self::STYLESHEET));
        $this->assertStringContainsString(
            '--gallery-video-self-hosted-max-height:',
            $this->read(self::THEME),
            sprintf('"%s" never offers "--gallery-video-self-hosted-max-height", so a site cannot take it over.', self::THEME)
        );
    }

    // The cap alone would leave a portrait player centred in a box the width of a landscape one, its own border framing empty space and the previous/next arrows stranded in the margin - only the player and its container shrinking to what the browser reads off the file brings them back against it
    public function testTheSelfHostedPlayerIsShrunkToTheFilesOwnShape(): void
    {
        $stylesheet = $this->read(self::STYLESHEET);

        $this->assertMatchesRegularExpression('/\.gallery-video--video\s*\{[^}]*width:\s*fit-content/s', $stylesheet);
        $this->assertMatchesRegularExpression('/\.gallery-media-container:has\(\.gallery-video--video\)\s*\{[^}]*width:\s*fit-content/s', $stylesheet);
    }

    // Every token this bundle ships is offered to the app commented out, or a site can't take it over (see the README's Theme section)
    public function testTheScaffoldThemeOffersEveryRatioToken(): void
    {
        $theme = $this->read(self::THEME);

        foreach ([...VideoPlatform::values(), 'default'] as $name) {
            $this->assertStringContainsString(
                sprintf('--gallery-video-ratio-%s:', $name),
                $theme,
                sprintf('"%s" never offers "--gallery-video-ratio-%s", so a site cannot take it over.', self::THEME, $name)
            );
        }
    }

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
