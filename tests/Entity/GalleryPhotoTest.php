<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Entity;

use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class GalleryPhotoTest extends TestCase
{
    // Drives UiBundle's VichImageResizeListener::processMultiSizeDerivatives() - see VichMultiSizeImageInterface
    public function testMultiSizeImageContractExposesTheDeclaredTargetSizes(): void
    {
        $photo = new GalleryPhoto();

        $this->assertSame(GalleryPhoto::MEDIUM_WIDTH, $photo->getImageWidth());
        $this->assertSame(GalleryPhoto::THUMBNAIL_SIZE, $photo->getThumbnailSize());
        $this->assertSame(GalleryPhoto::HIGHRES_WIDTH, $photo->getHighresWidth());
    }

    public function testGetVichMediaPathUsesGalleryAndCategorySlugs(): void
    {
        $gallery = (new Gallery())->setSlug('main');
        $category = (new GalleryCategory())->setGallery($gallery)->setSlug('voyages');
        $photo = (new GalleryPhoto())->setCategory($category);

        $this->assertSame('medias/gallery/main/voyages/photo', $photo->getVichMediaPath());
    }

    public function testGetVichMediaPathFallsBackWhenCategoryHasNoGallery(): void
    {
        $category = (new GalleryCategory())->setSlug('voyages');
        $photo = (new GalleryPhoto())->setCategory($category);

        $this->assertSame('medias/gallery/gallery/voyages/photo', $photo->getVichMediaPath());
    }

    public function testGetVichMediaPathFallsBackWhenPhotoHasNoCategory(): void
    {
        $photo = new GalleryPhoto();

        $this->assertSame('medias/gallery/gallery/uncategorized/photo', $photo->getVichMediaPath());
    }

    public function testDerivativeFilenamesInsertSuffixBeforeTheExtension(): void
    {
        $photo = (new GalleryPhoto())->setFilename('medias/gallery/main/voyages/photo.webp');

        $this->assertSame('medias/gallery/main/voyages/photo-thumb.webp', $photo->getThumbnailFilename());
        $this->assertSame('medias/gallery/main/voyages/photo-highres.webp', $photo->getHighresFilename());
    }

    public function testDerivativeFilenamesAreNullWhenThereIsNoFilenameYet(): void
    {
        $photo = new GalleryPhoto();

        $this->assertNull($photo->getThumbnailFilename());
        $this->assertNull($photo->getHighresFilename());
    }

    public function testSetFileBumpsUpdatedAtOnlyWhenAFileIsActuallySet(): void
    {
        $photo = new GalleryPhoto();
        $this->assertNull($photo->getUpdatedAt());

        $photo->setFile(new UploadedFile(__FILE__, 'photo.webp', test: true));
        $this->assertNotNull($photo->getUpdatedAt());
    }

    public function testSetFileWithNullDoesNotBumpUpdatedAt(): void
    {
        $photo = new GalleryPhoto();

        $photo->setFile(null);

        $this->assertNull($photo->getUpdatedAt());
    }

    public function testAPhotoIsAnImageWithNoVideoOfItsOwnByDefault(): void
    {
        $photo = new GalleryPhoto();

        $this->assertSame(GalleryPhoto::MEDIA_TYPE_IMAGE, $photo->getMediaType());
        $this->assertFalse($photo->isVideo());
        $this->assertNull($photo->getEmbedUrl());
    }

    public function testEmbedUrlIsBuiltFromTheIdOnCookieFreeHosts(): void
    {
        $youtube = (new GalleryPhoto())->setMediaType(GalleryPhoto::MEDIA_TYPE_YOUTUBE)->setExternalId('dQw4w9WgXcQ');
        $tiktok = (new GalleryPhoto())->setMediaType(GalleryPhoto::MEDIA_TYPE_TIKTOK)->setExternalId('6860377138386734341');

        $this->assertTrue($youtube->isVideo());
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $youtube->getEmbedUrl());
        $this->assertTrue($tiktok->isVideo());
        $this->assertSame('https://www.tiktok.com/embed/v2/6860377138386734341', $tiktok->getEmbedUrl());
    }

    // A type without an id has nothing to embed, and an id left behind by a type switched back to "image" must not resurrect the player
    public function testHalfDeclaredVideosStayImages(): void
    {
        $typeOnly = (new GalleryPhoto())->setMediaType(GalleryPhoto::MEDIA_TYPE_YOUTUBE);
        $idOnly = (new GalleryPhoto())->setExternalId('dQw4w9WgXcQ');

        $this->assertFalse($typeOnly->isVideo());
        $this->assertFalse($idOnly->isVideo());
        $this->assertNull($typeOnly->getEmbedUrl());
        $this->assertNull($idOnly->getEmbedUrl());
    }

    // Fed by imports as much as by the admin form, so an unknown value degrades to a still rather than dying halfway
    public function testAnUnknownMediaTypeFallsBackToImage(): void
    {
        $photo = (new GalleryPhoto())->setMediaType('vimeo');

        $this->assertSame(GalleryPhoto::MEDIA_TYPE_IMAGE, $photo->getMediaType());

        $photo->setMediaType(null);

        $this->assertSame(GalleryPhoto::MEDIA_TYPE_IMAGE, $photo->getMediaType());
    }

    // An emptied form field arrives as "", which would otherwise make isVideo() true on a blank id
    public function testAnEmptyExternalIdIsStoredAsNull(): void
    {
        $photo = (new GalleryPhoto())->setMediaType(GalleryPhoto::MEDIA_TYPE_TIKTOK)->setExternalId('');

        $this->assertNull($photo->getExternalId());
        $this->assertFalse($photo->isVideo());
    }
}
