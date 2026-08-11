<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Model\GalleryMediaBatch;
use c975L\GalleryBundle\Service\GalleryMediaFactory;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

class GalleryMediaFactoryTest extends TestCase
{
    private function createUploadedFile(string $clientName = 'media.webp'): UploadedFile
    {
        return new UploadedFile(__FILE__, $clientName, test: true);
    }

    // The real slugger, not a stub: what the factory is expected to leave on each media is the slug itself
    private function createFactory(): GalleryMediaFactory
    {
        return new GalleryMediaFactory(new GalleryMediaSlugger(new AsciiSlugger()));
    }

    // One media per uploaded file, all of them sharing the batch's credits and rights
    public function testOneMediaIsCreatedPerFileWithTheBatchCreditsAndRights(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');

        $medias = $this->createFactory()->createFromUploads(
            $category,
            [$this->createUploadedFile('a.webp'), $this->createUploadedFile('b.webp')],
            new GalleryMediaBatch(credits: 'Studio 975L', rightsReserved: true),
        );

        $this->assertCount(2, $medias);
        $this->assertSame('Studio 975L', $medias[0]->getCredits());
        $this->assertSame('Studio 975L', $medias[1]->getCredits());
        $this->assertTrue($medias[0]->isRightsReserved());
    }

    // Both ends of the association, which is what carries the medias into the cascade that saves them with a category created to hold them
    public function testTheMediasAreAttachedToTheCategoryOnBothSides(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');

        $medias = $this->createFactory()->createFromUploads($category, [$this->createUploadedFile()]);

        $this->assertSame($category, $medias[0]->getCategory());
        $this->assertTrue($category->getMedias()->contains($medias[0]));
    }

    // A batch never reorders what the category already holds
    public function testTheBatchIsAppendedAfterTheExistingPositions(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');
        $category->addMedia(new GalleryMedia()->setPosition(0));
        $category->addMedia(new GalleryMedia()->setPosition(5));

        $medias = $this->createFactory()->createFromUploads(
            $category,
            [$this->createUploadedFile('a.webp'), $this->createUploadedFile('b.webp')],
        );

        $this->assertSame([6, 7], array_map(static fn (GalleryMedia $media): int => $media->getPosition(), $medias));
    }

    public function testTheFirstMediaOfAnEmptyCategoryStartsAtZero(): void
    {
        $medias = $this->createFactory()->createFromUploads(new GalleryCategory()->setSlug('voyages'), [$this->createUploadedFile()]);

        $this->assertSame(0, $medias[0]->getPosition());
    }

    // Nothing else tells one media of a batch from another, so the original filename seeds the title rather than leaving it empty
    public function testTheTitleIsSeededFromTheOriginalFilename(): void
    {
        $medias = $this->createFactory()->createFromUploads(
            new GalleryCategory()->setSlug('voyages'),
            [$this->createUploadedFile('col_du-galibier.webp')],
        );

        $this->assertSame('Col Du Galibier', $medias[0]->getTitle());
    }

    // Set before the flush that names the uploaded file, the stored file being named after it (see GalleryMedia::getVichMediaPath)
    public function testEachMediaLeavesTheFactoryWithItsSlug(): void
    {
        $medias = $this->createFactory()->createFromUploads(
            new GalleryCategory()->setSlug('voyages'),
            [$this->createUploadedFile('col_du-galibier.webp')],
        );

        $this->assertSame('col-du-galibier', $medias[0]->getSlug());
        $this->assertSame('medias/gallery/voyages/col-du-galibier', $medias[0]->getVichMediaPath());
    }

    // A camera hands out the same name twice often enough that a batch can't stop on it - the second one is suffixed rather than refused
    public function testTwoFilesOfTheSameNameGetDistinctSlugs(): void
    {
        $medias = $this->createFactory()->createFromUploads(
            new GalleryCategory()->setSlug('voyages'),
            [$this->createUploadedFile('img_2024.webp'), $this->createUploadedFile('img_2024.webp')],
        );

        $this->assertSame(['img-2024', 'img-2024-2'], array_map(static fn (GalleryMedia $media): ?string => $media->getSlug(), $medias));
    }

    // An empty credits field is no credits at all, not a media credited to ""
    public function testEmptyCreditsAreStoredAsNone(): void
    {
        $medias = $this->createFactory()->createFromUploads(new GalleryCategory()->setSlug('voyages'), [$this->createUploadedFile()], new GalleryMediaBatch(credits: ''));

        $this->assertNull($medias[0]->getCredits());
    }

    // The one field that spares retouching a whole batch: every title is the root, numbered, instead of whatever the camera called the file
    public function testATitleRootNumbersEveryTitleOfTheBatch(): void
    {
        $medias = $this->createFactory()->createFromUploads(
            new GalleryCategory()->setSlug('mineraux'),
            [$this->createUploadedFile('img_2024.webp'), $this->createUploadedFile('img_2025.webp')],
            new GalleryMediaBatch(titleRoot: 'Cailloux couleur'),
        );

        $this->assertSame(
            ['Cailloux couleur 1', 'Cailloux couleur 2'],
            array_map(static fn (GalleryMedia $media): ?string => $media->getTitle(), $medias),
        );
    }

    // A second batch continues the series rather than restarting it against the titles the first one left
    public function testATitleRootContinuesFromWhatTheCategoryAlreadyHolds(): void
    {
        $category = new GalleryCategory()->setSlug('mineraux');
        $category->addMedia(new GalleryMedia()->setPosition(3));

        $medias = $this->createFactory()->createFromUploads(
            $category,
            [$this->createUploadedFile('img_2024.webp')],
            new GalleryMediaBatch(titleRoot: 'Cailloux couleur'),
        );

        $this->assertSame('Cailloux couleur 5', $medias[0]->getTitle());
    }

    // The url must not read as an order, the order being the one thing a gallery changes - hence a hash where the title carries a number
    public function testTheSlugOfARootedBatchIsHashedRatherThanNumbered(): void
    {
        $medias = $this->createFactory()->createFromUploads(
            new GalleryCategory()->setSlug('mineraux'),
            [$this->createUploadedFile('img_2024.webp'), $this->createUploadedFile('img_2025.webp')],
            new GalleryMediaBatch(titleRoot: 'Cailloux couleur'),
        );

        foreach ($medias as $media) {
            $this->assertMatchesRegularExpression('/^cailloux-couleur-[0-9a-f]{6}$/', (string) $media->getSlug());
        }

        $this->assertNotSame($medias[0]->getSlug(), $medias[1]->getSlug());
    }

    // Nothing the browser sent reaches the url as anything but hex: the fallback seed is the client's own filename, and it goes through sha1
    public function testAHostileFilenameCannotReachTheSlug(): void
    {
        $medias = $this->createFactory()->createFromUploads(
            new GalleryCategory()->setSlug('mineraux'),
            [$this->createUploadedFile('../../evil.php')],
            new GalleryMediaBatch(titleRoot: 'Cailloux couleur'),
        );

        $this->assertMatchesRegularExpression('/^cailloux-couleur-[0-9a-f]{6}$/', (string) $medias[0]->getSlug());
    }

    // Off unless the batch asked for it, an original being the whole uploaded file
    public function testOriginalsAreOnlyKeptWhenTheBatchAsksForThem(): void
    {
        $factory = $this->createFactory();
        $category = new GalleryCategory()->setSlug('mineraux');

        $kept = $factory->createFromUploads($category, [$this->createUploadedFile()], new GalleryMediaBatch(keepOriginals: true));
        $dropped = $factory->createFromUploads($category, [$this->createUploadedFile()]);

        $this->assertSame(GalleryMedia::ORIGINAL_DIRECTORY, $kept[0]->getOriginalDirectory());
        $this->assertNull($dropped[0]->getOriginalDirectory());
    }

    // Both halves of the batch's answer reach every media it creates: whether to sign, and where - the listener that stamps reads them off the entity, not off the batch (see UiBundle's VichWatermarkableInterface)
    public function testTheWatermarkAskedForByTheBatchReachesEveryMedia(): void
    {
        $factory = $this->createFactory();
        $category = new GalleryCategory()->setSlug('mineraux');

        $signed = $factory->createFromUploads(
            $category,
            [$this->createUploadedFile(), $this->createUploadedFile()],
            new GalleryMediaBatch(watermark: true, watermarkPosition: VichWatermarkableInterface::POSITION_TOP_LEFT)
        );
        $unsigned = $factory->createFromUploads($category, [$this->createUploadedFile()]);

        foreach ($signed as $media) {
            $this->assertTrue($media->wantsWatermark());
            $this->assertSame(VichWatermarkableInterface::POSITION_TOP_LEFT, $media->getWatermarkPosition());
        }

        $this->assertFalse($unsigned[0]->wantsWatermark());
    }

    // A batch that named no corner leaves each media without one, which is what takes the corner set site-wide
    public function testABatchNamingNoCornerLeavesTheSiteWideOneToDecide(): void
    {
        $medias = $this->createFactory()->createFromUploads(
            new GalleryCategory()->setSlug('mineraux'),
            [$this->createUploadedFile()],
            new GalleryMediaBatch(watermark: true)
        );

        $this->assertTrue($medias[0]->wantsWatermark());
        $this->assertNull($medias[0]->getWatermarkPosition());
    }

    // A form field left untouched submits null rather than a file, and a batch of none creates nothing
    public function testAnythingThatIsNotAnUploadedFileIsSkipped(): void
    {
        $category = new GalleryCategory()->setSlug('voyages');

        $medias = $this->createFactory()->createFromUploads($category, [null, 'media.webp']);

        $this->assertSame([], $medias);
        $this->assertTrue($category->getMedias()->isEmpty());
    }
}
