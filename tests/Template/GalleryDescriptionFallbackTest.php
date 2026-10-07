<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Template;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

// The index and a gallery nobody described still carry a description, a sentence naming the site rather than an empty meta. Nothing renders the template here, so the contract is read where it is written
class GalleryDescriptionFallbackTest extends TestCase
{
    // The row an editor wrote for /galerie wins, the sentence being its fallback
    public function testTheIndexFallsBackOnASentenceNamingTheSite(): void
    {
        $this->assertStringContainsString(
            "{% set summarySocialNetwork = url_metadata_summary('text.meta_gallery'|trans({'%site%': config('site-name')}, 'gallery')) %}",
            $this->read('templates/gallery/index.html.twig')
        );
    }

    // A gallery's own summary wins once long enough to describe the page, the automatic one, written with none, falling back on a sentence naming it that a short summary closes
    public function testAGalleryWithoutASummaryFallsBackOnASentenceNamingIt(): void
    {
        $this->assertStringContainsString(
            "{% set summarySocialNetwork = categorySummary|length >= 50 ? category.summarySocialNetwork : ('text.meta_category'|trans({'%category%': category.title, '%site%': config('site-name'), '%summary%': categorySummary}, 'gallery'))|trim %}",
            $this->read('templates/gallery/category.html.twig')
        );
    }

    // Both sentences exist in every language the bundle ships
    public function testBothSentencesAreTranslated(): void
    {
        foreach (['fr', 'en', 'es'] as $locale) {
            $catalog = $this->read('translations/gallery.' . $locale . '.xlf');

            $this->assertStringContainsString('<source>text.meta_gallery</source>', $catalog, $locale);
            $this->assertStringContainsString('<source>text.meta_category</source>', $catalog, $locale);
        }
    }

    // What the gallery and the media pages hand their layout, rendered in French: a short summary or composed sentence is said inside a translated one
    public function testShortDescriptionsAreSaidInsideASentence(): void
    {
        $this->assertSame('« Photos », une galerie de photos et de vidéos de Kalaan, à parcourir en ligne. Shooting photo Kalaan', $this->describe('templates/gallery/category.html.twig', '/\\{% set categorySummary = .+?\\n\\{% set summarySocialNetwork = .+?%\\}/s', ['category' => ['title' => 'Photos', 'summarySocialNetwork' => '<p>Shooting photo Kalaan</p>']]));
        $this->assertSame('« TikToks 2 », de la galerie « TikToks » de Kalaan, à voir en ligne.', $this->describe('templates/gallery/media.html.twig', '/\\{% set siteName = .+?\\n\\{% set composedSummary = .+?%\\}\\n.+?\\n\\{% set summarySocialNetwork = .+?%\\}/s', ['category' => ['title' => 'TikToks'], 'media' => ['title' => 'TikToks 2', 'description' => null, 'credits' => 'Kalaan']]));
    }

    // Renders the lines a page sets its description with, taken out of it, against these variables
    private function describe(string $relativePath, string $pattern, array $context): string
    {
        $this->assertSame(1, preg_match($pattern, $this->read($relativePath), $matches), $relativePath . ' no longer sets its description this way - check this test still says what it means.');

        $translator = new Translator('fr');
        $translator->addLoader('xlf', new XliffFileLoader());
        $translator->addResource('xlf', \dirname(__DIR__, 2) . '/translations/gallery.fr.xlf', 'fr', 'gallery');

        $twig = new Environment(new ArrayLoader(['page' => $matches[0] . '{{ summarySocialNetwork }}']), ['autoescape' => false]);
        $twig->addExtension(new TranslationExtension($translator));
        $twig->addFunction(new TwigFunction('config', static fn (string $name): string => 'Kalaan'));

        return $twig->render('page', $context);
    }

    // Reads a bundle file from its path relative to the repo root
    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
