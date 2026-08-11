<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Assets;

use PHPUnit\Framework\TestCase;

// Every "theme-color-gallery-*" config is read from the stylesheet as "var(--c975l-color-gallery-x, fallback)", so an empty entry paints the fallback and nobody in the back office is told which color that is
// A fallback written as a fixed color is therefore spelled twice - in sass/_variables.scss and as the entry's own value in config/configs.json - and this is what keeps the two in step: retuning one alone would have the back office announce a color the site does not paint
// A fallback that is an expression instead ("var(--white)", a color-mix on the theme) has no fixed color to declare: such an entry stays empty on purpose, its value following the light or dark ground the page is laid on, and this checks it was left that way
class ThemeColorDefaultTest extends TestCase
{
    private const string CONFIG_PREFIX = 'theme-';
    private const string TOKEN_PREFIX = '--c975l-';

    public function testEveryFixedFallbackIsDeclaredAsTheEntrysOwnValue(): void
    {
        $fallbacks = $this->fallbacks();
        $this->assertNotSame([], $fallbacks, 'No "var(--c975l-*, ...)" was read from the sass, this test no longer checks anything.');

        foreach ($this->declaredValues() as $slug => $value) {
            $fallback = $fallbacks[$slug] ?? null;
            $this->assertNotNull($fallback, sprintf('config/configs.json declares "%s" but no rule reads it, so setting it paints nothing.', $slug));

            if ($this->isExpression($fallback)) {
                $this->assertNull($value, sprintf('"%s" carries a value although its fallback "%s" follows the theme, so the color is now frozen on a light and a dark gallery alike.', $slug, $fallback));

                continue;
            }

            $this->assertSame($fallback, $value, sprintf('"%s" is declared as "%s" but the stylesheet falls back on "%s", so the back office announces a color the site does not paint.', $slug, $value ?? 'empty', $fallback));
        }
    }

    // The value each entry of config/configs.json is loaded with, empty ones included
    /**
     * @return array<string, string|null>
     */
    private function declaredValues(): array
    {
        $configs = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/config/configs.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($configs);

        $values = [];
        foreach ($configs as $config) {
            $slug = $config['slug'] ?? '';
            if (str_starts_with($slug, self::CONFIG_PREFIX)) {
                $values[$slug] = $config['value'] ?? null;
            }
        }

        return $values;
    }

    // What every "var(--c975l-x, fallback)" of the stylesheet falls back on, keyed by the config slug the token is named after - the same mechanical mapping ThemeVariablesCssListener writes the compiled site-theme.css with
    /**
     * @return array<string, string>
     */
    private function fallbacks(): array
    {
        $sass = (string) file_get_contents(\dirname(__DIR__, 2) . '/sass/_variables.scss');

        $fallbacks = [];
        preg_match_all('/var\(\s*(' . preg_quote(self::TOKEN_PREFIX, '/') . '[a-z0-9-]+)\s*,/', $sass, $matches, \PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => [$opening, $offset]) {
            $slug = self::CONFIG_PREFIX . substr($matches[1][$index][0], \strlen(self::TOKEN_PREFIX));
            $fallbacks[$slug] = $this->argument($sass, $offset + \strlen($opening));
        }

        return $fallbacks;
    }

    // The fallback itself, read from the comma to the "var(" it closes - scanned rather than matched, a "rgba(0, 0, 0, 0.9)" or a nested color-mix() holding parentheses of its own that no regular expression pairs
    private function argument(string $sass, int $start): string
    {
        $depth = 1;
        for ($position = $start; $position < \strlen($sass); ++$position) {
            $depth += match ($sass[$position]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if (0 === $depth) {
                return trim(substr($sass, $start, $position - $start));
            }
        }

        $this->fail(sprintf('A "var(%s..." of the sass is never closed.', self::TOKEN_PREFIX));
    }

    // Whether the fallback is a color the back office could announce, or an expression resolved against the page it paints
    private function isExpression(string $fallback): bool
    {
        return str_contains($fallback, 'var(') || str_contains($fallback, 'color-mix(');
    }
}
