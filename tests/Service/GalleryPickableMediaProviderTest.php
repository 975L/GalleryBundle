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

    // What lets a post picking it reserve the photograph in the gallery's list
    public function testAMediaIsOfferedAsTheContentItIs(): void
    {
        $media = new GalleryMedia()->setTitle('Lac')->setFilename('medias/gallery/montagne/lac.webp')->setMimeType('image/webp');
        new \ReflectionProperty(GalleryMedia::class, 'id')->setValue($media, 97);

        $pickable = $this->createProvider($media)->findPickableMedia('', 10)[0];

        $this->assertSame('gallery_media', $pickable->sourceType);
        $this->assertSame('97', $pickable->sourceId);
    }

    // A video is a content of the gallery's source too, held the same way once picked
    public function testAVideoIsOfferedAsTheContentItIs(): void
    {
        $media = new GalleryMedia()->setSlug('cascade')->setFilename('medias/gallery/montagne/cascade.webp')->setVideoFilename('medias/gallery/montagne/cascade.mp4')->setVideoMimeType('video/mp4');
        new \ReflectionProperty(GalleryMedia::class, 'id')->setValue($media, 98);

        $pickable = $this->createProvider($media)->findPickableMedia('', 10)[0];

        $this->assertSame('gallery_media', $pickable->sourceType);
        $this->assertSame('98', $pickable->sourceId);
    }

    public function testTheLabelIsTheGallery(): void
    {
        $label = $this->createProvider()->getPickableMediaLabel();

        $this->assertInstanceOf(TranslatableMessage::class, $label);
        $this->assertSame('label.gallery', $label->getMessage());
    }
}
