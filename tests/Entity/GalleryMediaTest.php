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
use c975L\UiBundle\Video\VideoPlatform;
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
        $category = new GalleryCategory()->setSlug('voyages');
        $media = new GalleryMedia()->setCategory($category)->setSlug('col-du-galibier');

        $this->assertSame('medias/gallery/voyages/col-du-galibier', $media->getVichMediaPath());
    }

    public function testGetVichMediaPathFallsBackWhenMediaHasNoCategory(): void
    {
        $media = new GalleryMedia()->setSlug('col-du-galibier');

        $this->assertSame('medias/gallery/uncategorized/col-du-galibier', $media->getVichMediaPath());
    }

    // A media stored before slugs existed still resolves to a path, rather than naming its file after nothing
    public function testGetVichMediaPathFallsBackWhenMediaHasNoSlug(): void
    {
        $media = new GalleryMedia()->setCategory(new GalleryCategory()->setSlug('voyages'));

        $this->assertSame('medias/gallery/voyages/media', $media->getVichMediaPath());
    }

    public function testDerivativeFilenamesInsertSuffixBeforeTheExtension(): void
    {
        $media = new GalleryMedia()->setFilename('medias/gallery/main/voyages/media.webp');

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

    // What an admin pastes is the page they were watching the video on - the platform reads itself off it, and what gets stored is that platform's own privacy-first embed url
    public function testAPastedUrlIsStoredAsThePlatformCanonicalEmbedUrl(): void
    {
        $youtube = new GalleryMedia()->setExternalUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $tiktok = new GalleryMedia()->setExternalUrl('https://www.tiktok.com/@kalaan/video/6860377138386734341');

        $this->assertTrue($youtube->isVideo());
        $this->assertSame('youtube', $youtube->getMediaType());
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $youtube->getEmbedUrl());

        $this->assertTrue($tiktok->isVideo());
        $this->assertSame('tiktok', $tiktok->getMediaType());
        $this->assertSame('https://www.tiktok.com/embed/v2/6860377138386734341', $tiktok->getEmbedUrl());
    }

    // The whole point of storing a url rather than an id: a gallery can hold a video from somewhere nobody declared - an instance of one's own among them
    public function testAnUnknownPlatformIsFramedExactlyAsPasted(): void
    {
        $media = new GalleryMedia()->setExternalUrl('https://peertube.example.org/videos/embed/abcd-1234');

        $this->assertTrue($media->isVideo());
        $this->assertSame(GalleryMedia::MEDIA_TYPE_EMBED, $media->getMediaType());
        $this->assertSame('https://peertube.example.org/videos/embed/abcd-1234', $media->getEmbedUrl());
    }

    // The type is derived, never stored alongside the url, so the two can't be left contradicting each other - clearing the url is what turns a video back into the still it always carried
    public function testClearingTheUrlTurnsTheMediaBackIntoAnImage(): void
    {
        $media = new GalleryMedia()->setExternalUrl('https://youtu.be/dQw4w9WgXcQ');

        $media->setExternalUrl('');

        $this->assertSame(GalleryMedia::MEDIA_TYPE_IMAGE, $media->getMediaType());
        $this->assertNull($media->getExternalUrl());
        $this->assertNull($media->getEmbedUrl());
        $this->assertFalse($media->isVideo());
    }

    public function testAnEmptyUrlIsStoredAsNull(): void
    {
        $this->assertNull(new GalleryMedia()->setExternalUrl('   ')->getExternalUrl());
        $this->assertNull(new GalleryMedia()->setExternalUrl(null)->getExternalUrl());
    }

    // An url ends up as an iframe's src, so anything but http(s) is dropped rather than stored - a javascript: one would otherwise run in the site's own origin
    public function testAnUrlThatIsNotHttpIsDropped(): void
    {
        $this->assertNull(new GalleryMedia()->setExternalUrl('javascript:alert(document.domain)')->getExternalUrl());
        $this->assertNull(new GalleryMedia()->setExternalUrl('data:text/html;base64,PHNjcmlwdD4=')->getExternalUrl());
        $this->assertNull(new GalleryMedia()->setExternalUrl('//www.youtube.com/embed/dQw4w9WgXcQ')->getExternalUrl());
        $this->assertSame('http://peertube.example.org/videos/embed/abcd', new GalleryMedia()->setExternalUrl('http://peertube.example.org/videos/embed/abcd')->getExternalUrl());
    }

    // A copy-paste brings its own whitespace, which is not a reason to file a valid url under "embed"
    public function testAPaddedUrlStillResolvesToItsPlatform(): void
    {
        $media = new GalleryMedia()->setExternalUrl('  https://youtu.be/dQw4w9WgXcQ  ');

        $this->assertSame('youtube', $media->getMediaType());
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $media->getEmbedUrl());
    }

    // What reserves the player's box before it loads - portrait only where the platform is, and the landscape default for a player nobody can know the shape of
    public function testTheAspectRatioFollowsThePlatform(): void
    {
        $this->assertSame('9 / 16', new GalleryMedia()->setExternalUrl('https://www.tiktok.com/embed/v2/6860377138386734341')->getAspectRatio());
        $this->assertSame('16 / 9', new GalleryMedia()->setExternalUrl('https://youtu.be/dQw4w9WgXcQ')->getAspectRatio());
        $this->assertSame('16 / 9', new GalleryMedia()->setExternalUrl('https://peertube.example.org/videos/embed/abcd')->getAspectRatio());
        $this->assertSame('16 / 9', new GalleryMedia()->getAspectRatio());
    }

    // The site's own copy: nothing framed, nothing to consent to, and a video that outlives whatever a platform decides
    public function testAnUploadedVideoMakesTheMediaASelfHostedOne(): void
    {
        $media = new GalleryMedia()->setVideoFilename('medias/gallery/kalaan/skate-a1b2c3.mp4');

        $this->assertSame(GalleryMedia::MEDIA_TYPE_VIDEO, $media->getMediaType());
        $this->assertTrue($media->isVideo());
        $this->assertTrue($media->isSelfHostedVideo());
        // Nothing to frame: the browser plays the file itself
        $this->assertNull($media->getEmbedUrl());
    }

    // A media that carries both plays its own copy - an url left over from before the file was uploaded is not a reason to send a visitor to a third party
    public function testTheSiteOwnFileWinsOverAPastedUrl(): void
    {
        $media = new GalleryMedia()
            ->setExternalUrl('https://youtu.be/dQw4w9WgXcQ')
            ->setVideoFilename('medias/gallery/kalaan/skate-a1b2c3.mp4');

        $this->assertSame(GalleryMedia::MEDIA_TYPE_VIDEO, $media->getMediaType());
        $this->assertTrue($media->isSelfHostedVideo());
        $this->assertNull($media->getEmbedUrl());
        // The url is still there, and is what the media goes back to if the file is removed
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $media->getExternalUrl());
    }

    // Removing the file falls back to whatever the media carried before it, rather than to a still it never was
    public function testRemovingTheFileFallsBackToTheUrlItStillCarries(): void
    {
        $media = new GalleryMedia()
            ->setExternalUrl('https://youtu.be/dQw4w9WgXcQ')
            ->setVideoFilename('skate.mp4');

        $media->setVideoFilename(null);

        $this->assertSame('youtube', $media->getMediaType());
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $media->getEmbedUrl());
        $this->assertFalse($media->isSelfHostedVideo());
    }

    public function testRemovingTheFileOfAMediaWithNoUrlLeavesAnImage(): void
    {
        $media = new GalleryMedia()->setVideoFilename('skate.mp4');

        $media->setVideoFilename('');

        $this->assertSame(GalleryMedia::MEDIA_TYPE_IMAGE, $media->getMediaType());
        $this->assertNull($media->getVideoFilename());
        $this->assertFalse($media->isVideo());
    }

    // A self-hosted video is the one player whose real shape the browser reads off the file itself, so the stylesheet lets it dictate it (see .gallery-video--video) - the entity has nothing to reserve on its behalf
    public function testASelfHostedVideoTakesTheDefaultRatio(): void
    {
        $this->assertSame('16 / 9', new GalleryMedia()->setVideoFilename('skate.mp4')->getAspectRatio());
    }

    // Read by the badge naming a video in the grid and by the admin screens - every platform UiBundle declares has to be in it, or a media renders a translation key
    public function testMediaTypesCoverEveryDeclaredPlatform(): void
    {
        $types = GalleryMedia::mediaTypes();

        $this->assertContains(GalleryMedia::MEDIA_TYPE_IMAGE, $types);
        $this->assertContains(GalleryMedia::MEDIA_TYPE_VIDEO, $types);
        $this->assertContains(GalleryMedia::MEDIA_TYPE_EMBED, $types);
        foreach (VideoPlatform::values() as $platform) {
            $this->assertContains($platform, $types);
        }
    }

    // Nothing is copied aside unless the batch that created the media asked for it
    public function testNoOriginalIsKeptByDefault(): void
    {
        $this->assertNull(new GalleryMedia()->getOriginalDirectory());
    }

    public function testAMediaAskedToKeepItsOriginalNamesTheDirectoryToCopyItTo(): void
    {
        $media = new GalleryMedia()->setKeepOriginal(true);

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
        $this->assertFalse(new GalleryMedia()->wantsWatermark());
        $this->assertTrue(new GalleryMedia()->setWatermark(true)->wantsWatermark());
    }

    // The watermark answers for the file being stored, not for the media: a stamped file carries the signature in its pixels, so nothing is kept once the upload is over and a media read back from the database asks the question again
    public function testTheWatermarkIsNotStoredOnTheMedia(): void
    {
        $properties = new \ReflectionClass(GalleryMedia::class)->getProperties();

        foreach ($properties as $property) {
            if (in_array($property->getName(), ['watermark', 'watermarkPosition'], true)) {
                $this->assertSame([], $property->getAttributes(Column::class), $property->getName() . ' must not be a column');
            }
        }
    }

    // A media carrying no payload reads as an empty one rather than as null, so a template never has to ask twice
    public function testGetDataValueReadsOneFieldOfTheSitesOwnPayload(): void
    {
        $media = new GalleryMedia();

        $this->assertSame([], $media->getData());
        $this->assertNull($media->getDataValue('photographer'));
        $this->assertSame('none', $media->getDataValue('photographer', 'none'));

        $media->setData(['photographer' => 'Laurent']);

        $this->assertSame('Laurent', $media->getDataValue('photographer'));
        $this->assertSame('none', $media->getDataValue('absent', 'none'));
    }
}
