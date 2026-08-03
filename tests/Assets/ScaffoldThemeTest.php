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

// The scaffolded gallery.css is a hand-maintained copy of the token defaults, so it drifts on its own
// Only this bundle's own tokens are asserted: the colors, fonts and shapes it reads from UiBundle are
// offered by that bundle's own themes/ui.css, or admin-editable and deliberately absent from both
class ScaffoldThemeTest extends TestCase
{
    // A. Every token the bundle declares is offered, so the file stays the single place to look
    public function testScaffoldOffersEveryDeclaredToken(): void
    {
        $missing = array_diff(array_keys($this->compiledRoot()), array_keys($this->scaffoldTokens()));

        $this->assertSame([], array_values($missing), sprintf(
            'sass/_variables.scss declares %s that the scaffolded gallery.css never mentions: a design has no way to discover them short of reading the compiled stylesheet.',
            implode(', ', $missing)
        ));
    }

    // B. What the scaffold shows as a default really is the one in force
    public function testScaffoldValuesMatchTheCompiledDefaults(): void
    {
        $compiled = $this->compiledRoot();
        $drifted = [];
        foreach ($this->scaffoldTokens() as $name => $value) {
            if (isset($compiled[$name]) && $compiled[$name] !== $value) {
                $drifted[] = sprintf('%s (bundle: "%s", scaffold: "%s")', $name, $compiled[$name], $value);
            }
        }

        $this->assertSame([], $drifted, sprintf(
            "The scaffolded gallery.css no longer mirrors sass/_variables.scss:\n- %s",
            implode("\n- ", $drifted)
        ));
    }

    // C. Nothing is ever active, a value here outliving any later change to the bundle's default
    public function testScaffoldShipsEverythingCommentedOut(): void
    {
        $bare = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($this->scaffoldPath()));

        preg_match_all('/^\s*(--[a-z0-9-]+):/m', $bare, $matches);

        $this->assertSame([], $matches[1], sprintf(
            'The scaffolded gallery.css declares %s outside a comment: a fresh site would freeze that value instead of following the bundle.',
            implode(', ', $matches[1])
        ));
    }

    // D. A "--gallery-" token read by a rule but declared nowhere resolves to nothing at all, silently -
    // the compiled sheet is valid CSS either way, so only a typo hunt catches it
    public function testEveryGalleryTokenReadIsAlsoDeclared(): void
    {
        $undeclared = array_diff(array_keys($this->tokensReadFromSass()), array_keys($this->compiledRoot()));

        $this->assertSame([], array_values($undeclared), sprintf(
            'The sass reads %s, which :root never declares: the property resolves to nothing and the rule silently falls back to its initial value.',
            implode(', ', $undeclared)
        ));
    }

    /**
     * Every "--gallery-" token this bundle's own sass reads, whatever the rule reading it.
     *
     * @return array<string, true>
     */
    private function tokensReadFromSass(): array
    {
        $root = \dirname(__DIR__, 2) . '/sass';
        $this->assertDirectoryExists($root, sprintf('"%s" is missing, so the tokens it holds would go unchecked.', $root));

        $tokens = [];
        foreach (glob($root . '/*.scss') ?: [] as $path) {
            // Comments are stripped first: prose explaining a rule quotes "var(--x)" too
            $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', (string) file_get_contents($path));

            preg_match_all('/var\(\s*(--gallery-[a-z0-9-]+)/', $code, $matches);

            foreach ($matches[1] as $token) {
                $tokens[$token] = true;
            }
        }

        $this->assertNotEmpty($tokens, 'No sass was read, this test no longer checks anything.');

        return $tokens;
    }

    /**
     * Declarations of the compiled :root - the defaults actually served, rather than the sass that produced them.
     *
     * @return array<string, string>
     */
    private function compiledRoot(): array
    {
        $path = \dirname(__DIR__, 2) . '/public/css/styles.css';
        $this->assertFileExists($path, 'styles.css is missing, the sass has not been compiled.');

        $css = (string) file_get_contents($path);
        $start = strpos($css, ':root {');
        $this->assertNotFalse($start, 'styles.css carries no :root block.');

        return $this->declarations(substr($css, $start, (int) strpos($css, "\n}", $start) - $start));
    }

    private function scaffoldPath(): string
    {
        return \dirname(__DIR__, 2) . '/scaffold/assets/styles/themes/gallery.css';
    }

    /**
     * @return array<string, string>
     */
    private function scaffoldTokens(): array
    {
        $path = $this->scaffoldPath();
        $this->assertFileExists($path, 'The scaffolded gallery.css is missing, so nothing offers this bundle\'s tokens to a site.');

        return $this->declarations((string) file_get_contents($path));
    }

    /**
     * Token => value, whether the line is commented out (the scaffold) or not (the compiled sheet).
     *
     * @return array<string, string>
     */
    private function declarations(string $css): array
    {
        preg_match_all('/(--[a-z0-9-]+):\s*([^;]+);/', $css, $matches, PREG_SET_ORDER);

        $declarations = [];
        foreach ($matches as $match) {
            $declarations[$match[1]] = trim(preg_replace('/\s+/', ' ', $match[2]) ?? '');
        }

        return $declarations;
    }
}
