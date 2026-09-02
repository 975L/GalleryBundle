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

/**
 * The print offer shown under a photograph, and what it answers a click with.
 *
 * The button hands the line to PaymentBundle's basket controller, whose message and bar are drawn by
 * components of that bundle: a page carrying the button without them fills the basket in silence, which
 * is what the offer looked like as long as those two were only ever rendered by the shop's own pages.
 */
class GalleryPrintOfferTest extends TestCase
{
    private const string OFFER = 'templates/print/_offer.html.twig';

    // The line saying the print was added and the bar carrying the count, the total and the way to the basket
    public function testTheClickIsAnsweredWhateverElseTheSiteInstalled(): void
    {
        $offer = $this->read(self::OFFER);

        $this->assertStringContainsString('<twig:c975LPayment:Basket:Message/>', $offer);
        $this->assertStringContainsString('<twig:c975LPayment:Basket:Navbar/>', $offer);
    }

    // The edition, said to the basket controller the way it reads it on a shop's own buttons: nothing is ordered past what is left
    public function testTheButtonSaysWhatIsLeftOfTheEdition(): void
    {
        $offer = $this->read(self::OFFER);

        $this->assertStringContainsString('data-limited="{{ remaining is null ? 0 : media.editionSize }}"', $offer);
        $this->assertStringContainsString('data-ordered="{{ remaining is null ? 0 : media.editionSize - remaining }}"', $offer);
    }

    // Grouped in php and never in the template, Twig having no filter for it - a "group_by" here would be a page that does not render at all
    public function testThePapersAreGatheredByTheBundleAndNotByAFilterTwigHasNot(): void
    {
        $offer = $this->read(self::OFFER);

        $this->assertStringContainsString('gallery_print_offers_by_paper(media)', $offer);
        $this->assertStringNotContainsString('group_by', $offer);
    }

    // The paper is written once above its sizes; a catalogue that names none falls back to the label, which is the flat list drawn before
    public function testASizeUnderItsPaperDoesNotRepeatThePaper(): void
    {
        $offer = $this->read(self::OFFER);

        $this->assertStringContainsString('paper is empty ? offer.format.label : offer.format.sizeLabel', $offer);
    }

    // Both come from the bundle owning the basket and never from ShopBundle, which a gallery selling prints does not require
    public function testTheAnswerIsAskedOfTheBundleOwningTheBasket(): void
    {
        $this->assertStringNotContainsString('c975LShop:', $this->read(self::OFFER));
    }

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
