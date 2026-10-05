<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Service\GalleryPickableMediaProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

class GalleryPickableMediaProviderTest extends TestCase
{
    private function createProvider(GalleryMedia ...$medias): GalleryPickableMediaProvider
    {
        $repository = $this->createStub(GalleryMediaRepository::class);
        $repository->method('findPickable')->willReturn($medias);

        return new GalleryPickableMediaProvider($repository);
    }

    // The medium file goes out, the thumbnail beside it only shown
    public function testAPhotographIsOfferedWithItsMediumFileAndItsThumbnail(): void
    {
        $media = new GalleryMedia()->setTitle('Lac')->setFilename('medias/gallery/montagne/lac.webp')->setMimeType('image/webp');

        $pickable = $this->createProvider($media)->findPickableMedia('', 10)[0];

        $this->assertSame('medias/gallery/montagne/lac.webp', $pickable->path);
        $this->assertSame('Lac', $pickable->title);
        $this->assertSame('image/webp', $pickable->mimeType);
        $this->assertSame('medias/gallery/montagne/lac-thumb.webp', $pickable->thumbnailPath);
    }

    // The site's own copy of the video, its still as thumbnail
    public function testAVideoIsOfferedWithItsOwnFile(): void
    {
        $media = new GalleryMedia()->setSlug('cascade')->setFilename('medias/gallery/montagne/cascade.webp')->setVideoFilename('medias/gallery/montagne/cascade.mp4')->setVideoMimeType('video/mp4');

        $pickable = $this->createProvider($media)->findPickableMedia('', 10)[0];

        $this->assertSame('medias/gallery/montagne/cascade.mp4', $pickable->path);
        $this->assertSame('cascade', $pickable->title);
        $this->assertSame('video/mp4', $pickable->mimeType);
    }

    public function testTheLabelIsTheGallery(): void
    {
        $label = $this->createProvider()->getPickableMediaLabel();

        $this->assertInstanceOf(TranslatableMessage::class, $label);
        $this->assertSame('label.gallery', $label->getMessage());
    }
}
