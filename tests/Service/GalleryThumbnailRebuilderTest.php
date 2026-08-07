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
use c975L\GalleryBundle\Service\GalleryThumbnailRebuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class GalleryThumbnailRebuilderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/gallery-thumbnail-rebuilder-' . uniqid();
        mkdir($this->projectDir . '/public/medias', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->projectDir . '/public/medias/*') ?: []);
        rmdir($this->projectDir . '/public/medias');
        rmdir($this->projectDir . '/public');
        rmdir($this->projectDir);
    }

    private function createRebuilder(): GalleryThumbnailRebuilder
    {
        $parameterBag = $this->createStub(ParameterBagInterface::class);
        $parameterBag->method('get')->willReturn($this->projectDir);

        return new GalleryThumbnailRebuilder($parameterBag);
    }

    private function createMedia(string $filename): GalleryMedia
    {
        return (new GalleryMedia())->setFilename($filename);
    }

    private function writeImage(string $filename, int $width, int $height): void
    {
        imagewebp(imagecreatetruecolor($width, $height), $this->projectDir . '/public/' . $filename);
    }

    // What the whole command is for: a thumbnail cropped square by the previous pipeline is rewritten holding the whole photo
    public function testRebuildWritesAThumbnailKeepingTheMediasProportions(): void
    {
        $this->writeImage('medias/photo-highres.webp', 2048, 1365);
        $this->writeImage('medias/photo-thumb.webp', 400, 400);

        $rebuilt = $this->createRebuilder()->rebuild($this->createMedia('medias/photo.webp'));

        $this->assertTrue($rebuilt);

        $dimensions = getimagesize($this->projectDir . '/public/medias/photo-thumb.webp');
        $this->assertSame(GalleryMedia::THUMBNAIL_SIZE, $dimensions[0]);
        $this->assertSame(400, $dimensions[1]);
    }

    // The stored (medium) file is the fallback, a gallery imported without its highres derivatives having nothing else to offer - softer than the highres would give, but a thumbnail all the same
    public function testRebuildFallsBackOnTheStoredFileWhenNoHighresExists(): void
    {
        $this->writeImage('medias/photo.webp', 1024, 683);

        $rebuilt = $this->createRebuilder()->rebuild($this->createMedia('medias/photo.webp'));

        $this->assertTrue($rebuilt);
        $this->assertFileExists($this->projectDir . '/public/medias/photo-thumb.webp');
    }

    // Never enlarged: a media smaller than the target keeps its own size, as everywhere else in the pipeline
    public function testRebuildNeverEnlargesAMediaSmallerThanTheTargetSize(): void
    {
        $this->writeImage('medias/small.webp', 300, 200);

        $this->createRebuilder()->rebuild($this->createMedia('medias/small.webp'));

        $dimensions = getimagesize($this->projectDir . '/public/medias/small-thumb.webp');
        $this->assertSame(300, $dimensions[0]);
        $this->assertSame(200, $dimensions[1]);
    }

    // A media whose files went missing is reported by the command, so it must be told apart rather than crash the run
    public function testRebuildReportsAMediaWithNoFileLeft(): void
    {
        $this->assertFalse($this->createRebuilder()->rebuild($this->createMedia('medias/gone.webp')));
    }

    // A media that never carried a file at all (a video entry imported without its still) has no filename to derive anything from
    public function testRebuildReportsAMediaWithoutFilename(): void
    {
        $this->assertFalse($this->createRebuilder()->rebuild(new GalleryMedia()));
    }
}
