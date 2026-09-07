<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Contract\PrintCatalogueProviderInterface;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Model\PrintCatalogueEntry;
use c975L\GalleryBundle\Repository\GalleryPrintFormatRepository;
use c975L\GalleryBundle\Service\PrintCatalogueImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// Seeding a print catalogue from the lab's range, once and only once
class PrintCatalogueImporterTest extends TestCase
{
    /** @var list<GalleryPrintFormat> */
    private array $persisted = [];

    public function testTheLabsRangeIsWrittenAsFormats(): void
    {
        $report = $this->importer()->import();

        $this->assertSame(2, $report->imported);
        $this->assertCount(2, $this->persisted);
        $this->assertSame('mat-20x20', $this->persisted[0]->getSlug());
        $this->assertSame('GLOBAL-FAP-8X8', $this->persisted[0]->getSku());
    }

    // A price nobody has looked at must not be on sale, whatever the placeholder says
    public function testNothingArrivesOnSale(): void
    {
        $this->importer()->import();

        $this->assertFalse($this->persisted[0]->isPublished());
    }

    // The slug is what an old order names a format by, so a second row under it would split one product in two
    public function testAFormatAlreadyCarryingTheSlugIsLeftAlone(): void
    {
        $existing = new GalleryPrintFormat()->setSlug('mat-20x20')->setSku('SOMETHING-ELSE');
        $report = $this->importer([$existing])->import();

        $this->assertSame(1, $report->imported);
        $this->assertSame(1, $report->alreadyPresent);
        $this->assertSame('mat-30x30', $this->persisted[0]->getSlug());
    }

    // The reference is the product itself: a shop already selling it under a name of its own is not given a second row for it
    public function testAFormatAlreadyCarryingTheReferenceIsLeftAlone(): void
    {
        $existing = new GalleryPrintFormat()->setSlug('a-name-of-its-own')->setSku('GLOBAL-FAP-8X8');
        $report = $this->importer([$existing])->import();

        $this->assertSame(1, $report->imported);
        $this->assertSame('mat-30x30', $this->persisted[0]->getSlug());
    }

    // A row an admin could publish and sell, every order of which the lab would refuse
    public function testAReferenceTheLabNoLongerHasIsNotWritten(): void
    {
        $report = $this->importer([], ['GLOBAL-FAP-8X8'])->import();

        $this->assertSame(1, $report->imported);
        $this->assertSame(['GLOBAL-FAP-8X8'], $report->unknownSkus);
        $this->assertSame('mat-30x30', $this->persisted[0]->getSlug());
    }

    // Imported all the same - the references may well be good, and saying they were never checked is not the same as calling them bad
    public function testACatalogueThatCouldNotBeCheckedIsImportedAndSaidToBeUnchecked(): void
    {
        $report = $this->importer([], null)->import();

        $this->assertSame(2, $report->imported);
        $this->assertTrue($report->unchecked);
    }

    // The catalogue ships an id, the column holds a sentence, and the sentence is the customer's language - not the admin's
    public function testTheDescriptionIsWrittenInTheSitesLanguage(): void
    {
        $this->importer()->import();

        $this->assertSame('Un papier mat (fr)', $this->persisted[0]->getPaperDescription());
        $this->assertSame(240, $this->persisted[1]->getDpi());
    }

    // A row still reading what an earlier release shipped in English is brought up to the sentence the catalogue ships now - reached through its sku, whatever the shop renamed it
    public function testARowStillCarryingTheShippedSentenceIsRewritten(): void
    {
        $existing = new GalleryPrintFormat()->setSlug('a-name-of-its-own')->setSku('GLOBAL-FAP-8X8')->setPaperDescription('Un papier mat (en)');
        $report = $this->importer([$existing])->import();

        $this->assertSame(1, $report->refreshed);
        $this->assertSame('Un papier mat (fr)', $existing->getPaperDescription());
    }

    // The sentence the admin wrote is the shop's, and the shop's words are never overwritten
    public function testASentenceTheAdminWroteIsLeftAlone(): void
    {
        $existing = new GalleryPrintFormat()->setSlug('mat-20x20')->setPaperDescription('Our own words');
        $report = $this->importer([$existing])->import();

        $this->assertSame(0, $report->refreshed);
        $this->assertSame('Our own words', $existing->getPaperDescription());
    }

    // A resolution left at the default is brought to what the catalogue now says for that size; one the admin set is kept
    public function testTheResolutionIsRefreshedOnlyWhereStillAtTheDefault(): void
    {
        $untouched = new GalleryPrintFormat()->setSlug('mat-30x30')->setPaperDescription('Un papier mat (fr)');
        $chosen = new GalleryPrintFormat()->setSlug('mat-20x20')->setDpi(150)->setPaperDescription('Un papier mat (fr)');
        $report = $this->importer([$untouched, $chosen])->import();

        $this->assertSame(1, $report->refreshed);
        $this->assertSame(240, $untouched->getDpi());
        $this->assertSame(150, $chosen->getDpi());
    }

    // Run twice, the second run has nothing left to bring up to date
    public function testASecondRunRefreshesNothing(): void
    {
        $this->importer()->import();
        $report = $this->importer($this->persisted)->import();

        $this->assertSame(0, $report->imported);
        $this->assertSame(0, $report->refreshed);
    }

    public function testASiteWhoseLabProposesNoRangeImportsNothing(): void
    {
        $report = $this->importer([], [], 'another-lab')->import();

        $this->assertSame(0, $report->imported);
        $this->assertSame([], $this->persisted);
    }

    /**
     * @param list<GalleryPrintFormat> $existing
     * @param list<string>|null        $unknown
     */
    private function importer(array $existing = [], ?array $unknown = [], string $provider = 'prodigi'): PrintCatalogueImporter
    {
        $catalogue = new readonly class ($unknown) implements PrintCatalogueProviderInterface {
            /** @param list<string>|null $unknown */
            public function __construct(private ?array $unknown)
            {
            }

            public function getName(): string
            {
                return 'prodigi';
            }

            public function getEntries(): array
            {
                return [
                    new PrintCatalogueEntry('mat-20x20', '20 x 20 cm', 20, 20, 'GLOBAL-FAP-8X8', 'Matte', 'print_paper.matte', 5500, 10),
                    new PrintCatalogueEntry('mat-30x30', '30 x 30 cm', 30, 30, 'GLOBAL-FAP-12X12', 'Matte', 'print_paper.matte', 10000, 20, 240),
                ];
            }

            public function findUnknownSkus(array $skus): ?array
            {
                return $this->unknown;
            }
        };

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($provider);

        $repository = $this->createStub(GalleryPrintFormatRepository::class);
        $repository->method('findAll')->willReturn($existing);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        // Answers the id in whichever locale it is asked in, so the test reads which one the importer chose
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters, ?string $domain, ?string $locale): string => 'print_paper.matte' === $id ? sprintf('Un papier mat (%s)', $locale) : $id);

        return new PrintCatalogueImporter([$catalogue], $configService, $repository, $entityManager, $translator, 'fr');
    }
}
