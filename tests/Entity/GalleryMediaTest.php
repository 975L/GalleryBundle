<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Entity;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class GalleryMediaTest extends TestCase
{
    // Drives UiBundle's VichImageResizeListener::processMultiSizeDerivatives() - see VichMultiSizeImageInterface
    public function testMultiSizeImageContractExposesTheDeclaredTargetSizes(): void
    {
        $media = new GalleryMedia();

        $this->assertSame(GalleryMedia::MEDIUM_WIDTH, $media->getImageWidth());
        $this->assertSame(GalleryMedia::THUMBNAIL_SIZE, $media->getThumbnailSize());
        $this->assertSame(GalleryMedia::HIGHRES_WIDTH, $media->getHighresWidth());
    }

    // The stored file is named after the slug the media is reached by, so the file on disk and the page pointing at it read the same
    public function testGetVichMediaPathUsesTheCategoryAndMediaSlugs(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $media = (new GalleryMedia())->setCategory($category)->setSlug('col-du-galibier');

        $this->assertSame('medias/gallery/voyages/col-du-galibier', $media->getVichMediaPath());
    }

    public function testGetVichMediaPathFallsBackWhenMediaHasNoCategory(): void
    {
        $media = (new GalleryMedia())->setSlug('col-du-galibier');

        $this->assertSame('medias/gallery/uncategorized/col-du-galibier', $media->getVichMediaPath());
    }

    // A media stored before slugs existed still resolves to a path, rather than naming its file after nothing
    public function testGetVichMediaPathFallsBackWhenMediaHasNoSlug(): void
    {
        $media = (new GalleryMedia())->setCategory((new GalleryCategory())->setSlug('voyages'));

        $this->assertSame('medias/gallery/voyages/media', $media->getVichMediaPath());
    }

    public function testDerivativeFilenamesInsertSuffixBeforeTheExtension(): void
    {
        $media = (new GalleryMedia())->setFilename('medias/gallery/main/voyages/media.webp');

        $this->assertSame('medias/gallery/main/voyages/media-thumb.webp', $media->getThumbnailFilename());
        $this->assertSame('medias/gallery/main/voyages/media-highres.webp', $media->getHighresFilename());
    }

    public function testDerivativeFilenamesAreNullWhenThereIsNoFilenameYet(): void
    {
        $media = new GalleryMedia();

        $this->assertNull($media->getThumbnailFilename());
        $this->assertNull($media->getHighresFilename());
    }

    public function testSetFileBumpsUpdatedAtOnlyWhenAFileIsActuallySet(): void
    {
        $media = new GalleryMedia();
        $this->assertNull($media->getUpdatedAt());

        $media->setFile(new UploadedFile(__FILE__, 'media.webp', test: true));
        $this->assertNotNull($media->getUpdatedAt());
    }

    public function testSetFileWithNullDoesNotBumpUpdatedAt(): void
    {
        $media = new GalleryMedia();

        $media->setFile(null);

        $this->assertNull($media->getUpdatedAt());
    }

    public function testAMediaIsAnImageWithNoVideoOfItsOwnByDefault(): void
    {
        $media = new GalleryMedia();

        $this->assertSame(GalleryMedia::MEDIA_TYPE_IMAGE, $media->getMediaType());
        $this->assertFalse($media->isVideo());
        $this->assertNull($media->getEmbedUrl());
    }

    public function testEmbedUrlIsBuiltFromTheIdOnCookieFreeHosts(): void
    {
        $youtube = (new GalleryMedia())->setMediaType(GalleryMedia::MEDIA_TYPE_YOUTUBE)->setExternalId('dQw4w9WgXcQ');
        $tiktok = (new GalleryMedia())->setMediaType(GalleryMedia::MEDIA_TYPE_TIKTOK)->setExternalId('6860377138386734341');

        $this->assertTrue($youtube->isVideo());
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $youtube->getEmbedUrl());
        $this->assertTrue($tiktok->isVideo());
        $this->assertSame('https://www.tiktok.com/embed/v2/6860377138386734341', $tiktok->getEmbedUrl());
    }

    // A type without an id has nothing to embed, and an id left behind by a type switched back to "image" must not resurrect the player
    public function testHalfDeclaredVideosStayImages(): void
    {
        $typeOnly = (new GalleryMedia())->setMediaType(GalleryMedia::MEDIA_TYPE_YOUTUBE);
        $idOnly = (new GalleryMedia())->setExternalId('dQw4w9WgXcQ');

        $this->assertFalse($typeOnly->isVideo());
        $this->assertFalse($idOnly->isVideo());
        $this->assertNull($typeOnly->getEmbedUrl());
        $this->assertNull($idOnly->getEmbedUrl());
    }

    // Fed by imports as much as by the admin form, so an unknown value degrades to a still rather than dying halfway
    public function testAnUnknownMediaTypeFallsBackToImage(): void
    {
        $media = (new GalleryMedia())->setMediaType('vimeo');

        $this->assertSame(GalleryMedia::MEDIA_TYPE_IMAGE, $media->getMediaType());

        $media->setMediaType(null);

        $this->assertSame(GalleryMedia::MEDIA_TYPE_IMAGE, $media->getMediaType());
    }

    // An emptied form field arrives as "", which would otherwise make isVideo() true on a blank id
    public function testAnEmptyExternalIdIsStoredAsNull(): void
    {
        $media = (new GalleryMedia())->setMediaType(GalleryMedia::MEDIA_TYPE_TIKTOK)->setExternalId('');

        $this->assertNull($media->getExternalId());
        $this->assertFalse($media->isVideo());
    }

    // Nothing is copied aside unless the batch that created the media asked for it
    public function testNoOriginalIsKeptByDefault(): void
    {
        $this->assertNull((new GalleryMedia())->getOriginalDirectory());
    }

    public function testAMediaAskedToKeepItsOriginalNamesTheDirectoryToCopyItTo(): void
    {
        $media = (new GalleryMedia())->setKeepOriginal(true);

        $this->assertSame(GalleryMedia::ORIGINAL_DIRECTORY, $media->getOriginalDirectory());
    }

    // The checkbox is only ever answered at upload time, so a media whose file is replaced later goes on keeping what it already had
    public function testAMediaThatAlreadyHasAnOriginalKeepsOneWhenItsFileIsReplaced(): void
    {
        $media = new GalleryMedia();
        $media->setOriginalFilename('medias/gallery/mineraux/cailloux-a1b2c3-original.jpg');

        $this->assertSame(GalleryMedia::ORIGINAL_DIRECTORY, $media->getOriginalDirectory());
    }

    // Derived from the stored filename, which UiMediaNamer forces to webp - the original is the one file of the set that is not, so it carries its own name rather than a derived one
    public function testTheDerivativesKeepTheStoredExtensionWhereTheOriginalDoesNot(): void
    {
        $media = new GalleryMedia();
        $media->setFilename('medias/gallery/mineraux/cailloux-a1b2c3.webp');
        $media->setOriginalFilename('medias/gallery/mineraux/cailloux-a1b2c3-original.jpg');

        $this->assertSame('medias/gallery/mineraux/cailloux-a1b2c3-thumb.webp', $media->getThumbnailFilename());
        $this->assertSame('medias/gallery/mineraux/cailloux-a1b2c3-highres.webp', $media->getHighresFilename());
        $this->assertSame('medias/gallery/mineraux/cailloux-a1b2c3-original.jpg', $media->getOriginalFilename());
    }

    // The corner comes from a form choice, and a value nobody named is stored as none at all - which is what falls back to the site-wide corner rather than to nothing
    public function testAnUnknownCornerIsStoredAsNoneAtAll(): void
    {
        $media = new GalleryMedia();

        $media->setWatermarkPosition(VichWatermarkableInterface::POSITION_TOP_RIGHT);
        $this->assertSame(VichWatermarkableInterface::POSITION_TOP_RIGHT, $media->getWatermarkPosition());

        $media->setWatermarkPosition('middle-of-nowhere');
        $this->assertNull($media->getWatermarkPosition());
    }

    // Off unless whoever is uploading the file asked for it
    public function testAMediaWantsNoWatermarkUnlessItWasAskedFor(): void
    {
        $this->assertFalse((new GalleryMedia())->wantsWatermark());
        $this->assertTrue((new GalleryMedia())->setWatermark(true)->wantsWatermark());
    }

    // The watermark answers for the file being stored, not for the media: a stamped file carries the signature in its pixels, so nothing is kept once the upload is over and a media read back from the database asks the question again
    public function testTheWatermarkIsNotStoredOnTheMedia(): void
    {
        $properties = (new \ReflectionClass(GalleryMedia::class))->getProperties();

        foreach ($properties as $property) {
            if (in_array($property->getName(), ['watermark', 'watermarkPosition'], true)) {
                $this->assertSame([], $property->getAttributes(Column::class), $property->getName() . ' must not be a column');
            }
        }
    }
}
