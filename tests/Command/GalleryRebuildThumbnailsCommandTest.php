<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Command;

use c975L\GalleryBundle\Command\GalleryRebuildThumbnailsCommand;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Service\GalleryThumbnailRebuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GalleryRebuildThumbnailsCommandTest extends TestCase
{
    /**
     * @param array<int, GalleryMedia> $medias
     * @param array<int, bool>         $outcomes
     */
    private function createTester(array $medias, array $outcomes): CommandTester
    {
        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findAll')->willReturn($medias);

        $rebuilder = $this->createStub(GalleryThumbnailRebuilder::class);
        $rebuilder->method('rebuild')->willReturn(...$outcomes);

        return new CommandTester(new GalleryRebuildThumbnailsCommand($mediaRepository, $rebuilder));
    }

    private function createMedia(string $slug, string $filename): GalleryMedia
    {
        $media = new GalleryMedia()->setSlug($slug)->setFilename($filename);
        new GalleryCategory()->setSlug('col-du-galibier')->addMedia($media);

        return $media;
    }

    public function testExecuteCountsTheRebuiltThumbnails(): void
    {
        $tester = $this->createTester([
            $this->createMedia('lacets', 'medias/lacets.webp'),
            $this->createMedia('sommet', 'medias/sommet.webp'),
        ], [true, true]);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('2 thumbnail(s) rebuilt', $tester->getDisplay());
    }

    // A media with no file left is named, and the run carries on with the rest of the gallery
    public function testExecuteNamesTheMediasItCouldNotRebuild(): void
    {
        $tester = $this->createTester([
            $this->createMedia('lacets', 'medias/lacets.webp'),
            $this->createMedia('sommet', 'medias/sommet.webp'),
        ], [true, false]);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('col-du-galibier/sommet', $display);
        $this->assertStringContainsString('1 thumbnail(s) rebuilt', $display);
    }

    // A dry run writes nothing at all, so the rebuilder is never even asked
    public function testDryRunListsWithoutRebuilding(): void
    {
        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('findAll')->willReturn([$this->createMedia('lacets', 'medias/lacets.webp')]);

        $rebuilder = $this->createMock(GalleryThumbnailRebuilder::class);
        $rebuilder->expects($this->never())->method('rebuild');

        $tester = new CommandTester(new GalleryRebuildThumbnailsCommand($mediaRepository, $rebuilder));
        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('medias/lacets-thumb.webp', $display);
        $this->assertStringContainsString('1 thumbnail(s) would be rebuilt', $display);
    }
}
