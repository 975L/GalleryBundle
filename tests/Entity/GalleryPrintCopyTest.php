<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Entity;

use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Model\PrintCopySnapshot;
use PHPUnit\Framework\TestCase;

// A certificate is signed by hand and posted, so from that day the buyer holds the document: everything the site can still redraw has to keep saying what the paper says. These are the tests of that promise
class GalleryPrintCopyTest extends TestCase
{
    private function snapshot(): PrintCopySnapshot
    {
        return new PrintCopySnapshot(
            format: '30x40-hahnemuhle',
            formatLabel: '30 x 40 cm, Hahnemühle Photo Rag',
            sku: 'GLOBAL-FAP-30X40',
            price: 18000,
            workTitle: 'Aiguille du Midi, 6h12',
            credits: 'Laurent Marquet',
            issuer: '975L',
        );
    }

    private function sold(GalleryMedia $media): GalleryPrintCopy
    {
        return new GalleryPrintCopy()
            ->setMedia($media)
            ->setNumber(7)
            ->applySnapshot($this->snapshot())
        ;
    }

    // The whole point: retitling the photograph, re-crediting it or renaming the catalogue line afterwards must not reach a certificate already issued
    public function testWhatWasSoldSurvivesEveryLaterEdit(): void
    {
        $media = new GalleryMedia()
            ->setTitle('Aiguille du Midi, 6h12')
            ->setCredits('Laurent Marquet')
            ->setEditionSize(30)
        ;
        $copy = $this->sold($media);

        $media->setTitle('Aiguille du Midi (recadrée)');
        $media->setCredits('975L');

        $this->assertSame('Aiguille du Midi, 6h12', $copy->getWorkTitle());
        $this->assertSame('Laurent Marquet', $copy->getCredits());
        $this->assertSame('30 x 40 cm, Hahnemühle Photo Rag', $copy->getFormatLabel());
        $this->assertSame('975L', $copy->getIssuer());
    }

    // The lab's reference and the catalogue key are two different strings, and sending the wrong one is an order a printer cannot make
    public function testTheLabReferenceIsNotTheCatalogueKey(): void
    {
        $copy = $this->sold(new GalleryMedia()->setEditionSize(30));

        $this->assertSame('GLOBAL-FAP-30X40', $copy->getSku());
        $this->assertSame('30x40-hahnemuhle', $copy->getFormat());
    }

    public function testASoldCopyIsNamedByItsFrozenTitleAndItsRank(): void
    {
        $media = new GalleryMedia()->setTitle('Aiguille du Midi, 6h12')->setEditionSize(30);
        $copy = $this->sold($media);

        $media->setTitle('Autre chose');

        $this->assertSame('Aiguille du Midi, 6h12 7/30', (string) $copy);
    }

    // A row written when the edition was announced and still waiting for a buyer has nothing frozen yet, so it reads the photograph as it stands - which is what the back-office list is looking at
    public function testAnUnsoldRowStillReadsTheLivePhotograph(): void
    {
        $media = new GalleryMedia()->setTitle('Aiguille du Midi, 6h12')->setEditionSize(30);
        $copy = new GalleryPrintCopy()->setMedia($media)->setNumber(7);

        $media->setTitle('Aiguille du Midi (recadrée)');

        $this->assertSame('Aiguille du Midi (recadrée) 7/30', (string) $copy);
        $this->assertTrue($copy->isAvailable());
    }
}
