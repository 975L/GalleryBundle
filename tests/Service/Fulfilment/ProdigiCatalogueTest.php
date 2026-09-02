<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service\Fulfilment;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Model\PrintCatalogueEntry;
use c975L\GalleryBundle\Service\Fulfilment\ProdigiCatalogue;
use c975L\GalleryBundle\Service\Fulfilment\ProdigiEnvironment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// The range this bundle proposes for Prodigi, and what it answers when asked to check it against the lab
class ProdigiCatalogueTest extends TestCase
{
    // Both are what an order is keyed on later - a duplicate of either would be two rows for one product
    public function testEverySlugAndEveryReferenceAppearsOnce(): void
    {
        $entries = $this->catalogue()->getEntries();
        $slugs = array_map(static fn (PrintCatalogueEntry $entry): string => $entry->slug, $entries);
        $skus = array_map(static fn (PrintCatalogueEntry $entry): string => $entry->sku, $entries);

        $this->assertSame($slugs, array_unique($slugs));
        $this->assertSame($skus, array_unique($skus));
    }

    // The slug is stored in orders and the sku is sent to the lab, so both have to fit the columns holding them
    public function testEveryLineFitsTheColumnsItIsWrittenTo(): void
    {
        foreach ($this->catalogue()->getEntries() as $entry) {
            $this->assertLessThanOrEqual(50, \strlen($entry->slug), $entry->slug);
            $this->assertLessThanOrEqual(255, \strlen($entry->label), $entry->slug);
            $this->assertLessThanOrEqual(100, \strlen($entry->sku), $entry->slug);
            $this->assertGreaterThan(0, $entry->widthCm, $entry->slug);
            $this->assertGreaterThan(0, $entry->heightCm, $entry->slug);
        }
    }

    // The centimetres are the shop's words and the reference is the lab's, but they describe one sheet: a line whose two sides disagree would sell a size the lab does not print
    public function testTheCentimetresAgreeWithTheReferenceTheyAreSoldUnder(): void
    {
        foreach ($this->catalogue()->getEntries() as $entry) {
            $size = substr((string) strrchr($entry->sku, '-'), 1);

            // The ISO sizes name themselves rather than measure themselves, and are checked by their ratio alone below
            if (!preg_match('/^(\d+)X(\d+)$/', $size, $matches)) {
                continue;
            }

            // Within a centimetre and a half, the lab rounding its own sizes for the metric world - a 30" sheet it sells as 75 cm is 76.2 of them
            $this->assertEqualsWithDelta((int) $matches[1] * 2.54, $entry->widthCm, 1.5, $entry->slug);
            $this->assertEqualsWithDelta((int) $matches[2] * 2.54, $entry->heightCm, 1.5, $entry->slug);
        }
    }

    // The heading the sizes are gathered under, and the sentence justifying its price - a line carrying neither would draw as an unnamed group
    public function testEveryLineNamesItsPaperAndSaysWhatItIsFor(): void
    {
        foreach ($this->catalogue()->getEntries() as $entry) {
            $this->assertNotSame('', $entry->paper, $entry->slug);
            $this->assertNotSame('', $entry->paperDescription, $entry->slug);
            $this->assertLessThanOrEqual(100, \strlen($entry->paper), $entry->slug);
            $this->assertLessThanOrEqual(255, \strlen($entry->paperDescription), $entry->slug);
        }
    }

    // One paper is one heading and one sentence: two wordings of the same paper would draw it twice, under two headings a visitor reads as two papers
    public function testAPaperIsDescribedTheSameWayOnEveryLineThatUsesIt(): void
    {
        $descriptions = [];

        foreach ($this->catalogue()->getEntries() as $entry) {
            $descriptions[$entry->paper][$entry->paperDescription] = true;
        }

        foreach ($descriptions as $paper => $wordings) {
            $this->assertCount(1, $wordings, $paper);
        }
    }

    public function testTheLabIsAskedAboutEveryReferenceGiven(): void
    {
        $asked = [];
        $catalogue = $this->catalogue(static function (string $method, string $url) use (&$asked): MockResponse {
            $asked[] = $url;

            return new MockResponse('{}');
        });

        $this->assertSame([], $catalogue->findUnknownSkus(['GLOBAL-FAP-8X8', 'GLOBAL-PAP-8X8']));
        $this->assertCount(2, $asked);
        $this->assertStringEndsWith('/products/GLOBAL-PAP-8X8', $asked[1]);
    }

    // The whole point of the check: a reference the bundle shipped and the lab has since retired
    public function testAReferenceTheLabNoLongerHasIsReported(): void
    {
        $catalogue = $this->catalogue(static fn (string $method, string $url): MockResponse => new MockResponse(
            '{}',
            ['http_code' => str_contains($url, 'GONE') ? 404 : 200],
        ));

        $this->assertSame(['GLOBAL-FAP-GONE'], $catalogue->findUnknownSkus(['GLOBAL-FAP-8X8', 'GLOBAL-FAP-GONE']));
    }

    // A key the lab refuses says nothing about the products, and reporting them as unknown would import an empty catalogue
    public function testARefusedKeyIsNotReadAsAnEmptyCatalogue(): void
    {
        $catalogue = $this->catalogue(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 401]));

        $this->assertNull($catalogue->findUnknownSkus(['GLOBAL-FAP-8X8']));
    }

    public function testALabHavingABadDayIsNotReadAsAnEmptyCatalogueEither(): void
    {
        $catalogue = $this->catalogue(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 503]));

        $this->assertNull($catalogue->findUnknownSkus(['GLOBAL-FAP-8X8']));
    }

    public function testWithoutAKeyNothingIsCheckedAndNothingIsClaimed(): void
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn(null);

        $catalogue = new ProdigiCatalogue(new MockHttpClient(), new ProdigiEnvironment($configService));

        $this->assertNull($catalogue->findUnknownSkus(['GLOBAL-FAP-8X8']));
    }

    private function catalogue(?callable $handler = null): ProdigiCatalogue
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('an-api-key');

        return new ProdigiCatalogue(new MockHttpClient($handler ?? static fn (): MockResponse => new MockResponse('{}')), new ProdigiEnvironment($configService));
    }
}
