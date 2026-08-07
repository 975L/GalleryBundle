<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Listener;

use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Listener\GalleryMediaDerivativeCleanupListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class GalleryMediaDerivativeCleanupListenerTest extends TestCase
{
    private string $projectDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/gallery-bundle-cleanup-' . uniqid();
        $this->filesystem->mkdir($this->projectDir . '/public/medias/gallery');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    private function createListener(): GalleryMediaDerivativeCleanupListener
    {
        $parameterBag = $this->createStub(ParameterBagInterface::class);
        $parameterBag->method('get')->willReturn($this->projectDir);

        return new GalleryMediaDerivativeCleanupListener($parameterBag);
    }

    private function touch(string $relativePath): void
    {
        $this->filesystem->dumpFile($this->projectDir . '/public/' . $relativePath, 'x');
    }

    public function testPostFlushRemovesTheOldDerivativesWhenAFileWasReplaced(): void
    {
        $media = new GalleryMedia();
        $media->setFilename('medias/gallery/main/vacances/photo-old.webp');
        $this->touch('medias/gallery/main/vacances/photo-old-thumb.webp');
        $this->touch('medias/gallery/main/vacances/photo-old-highres.webp');

        // Simulates the form having bound a newly uploaded file - before Vich (which runs later, at a lower listener priority) renames it and overwrites this entity's own filename
        $media->setFile(new UploadedFile(__FILE__, 'new.webp', test: true));

        $em = $this->createStub(EntityManagerInterface::class);
        $changeSet = [];

        $listener = $this->createListener();
        $listener->preUpdate(new PreUpdateEventArgs($media, $em, $changeSet));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->assertFileDoesNotExist($this->projectDir . '/public/medias/gallery/main/vacances/photo-old-thumb.webp');
        $this->assertFileDoesNotExist($this->projectDir . '/public/medias/gallery/main/vacances/photo-old-highres.webp');
    }

    public function testPreUpdateIgnoresEntitiesWithNoPendingFile(): void
    {
        $media = new GalleryMedia();
        $media->setFilename('medias/gallery/main/vacances/photo-old.webp');
        $this->touch('medias/gallery/main/vacances/photo-old-thumb.webp');

        $em = $this->createStub(EntityManagerInterface::class);
        $changeSet = [];

        $listener = $this->createListener();
        $listener->preUpdate(new PreUpdateEventArgs($media, $em, $changeSet));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->assertFileExists($this->projectDir . '/public/medias/gallery/main/vacances/photo-old-thumb.webp');
    }

    public function testPreUpdateIgnoresEntitiesThatAreNotGalleryMedia(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $changeSet = [];

        $listener = $this->createListener();
        $listener->preUpdate(new PreUpdateEventArgs(new \stdClass(), $em, $changeSet));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->addToAssertionCount(1);
    }

    // Covers every removal path at once - CRUD delete, category cascade, and the import replacing a category's media collection through orphanRemoval
    public function testPostFlushRemovesTheDerivativesOfARemovedMedia(): void
    {
        $media = new GalleryMedia();
        $media->setFilename('medias/gallery/main/vacances/media.webp');
        $this->touch('medias/gallery/main/vacances/media-thumb.webp');
        $this->touch('medias/gallery/main/vacances/media-highres.webp');

        $em = $this->createStub(EntityManagerInterface::class);

        $listener = $this->createListener();
        $listener->preRemove(new PreRemoveEventArgs($media, $em));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->assertFileDoesNotExist($this->projectDir . '/public/medias/gallery/main/vacances/media-thumb.webp');
        $this->assertFileDoesNotExist($this->projectDir . '/public/medias/gallery/main/vacances/media-highres.webp');
    }

    public function testPreRemoveToleratesAlreadyMissingDerivativeFiles(): void
    {
        $media = new GalleryMedia();
        $media->setFilename('medias/gallery/main/vacances/media.webp');

        $em = $this->createStub(EntityManagerInterface::class);

        $listener = $this->createListener();
        $listener->preRemove(new PreRemoveEventArgs($media, $em));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->addToAssertionCount(1);
    }

    // The heaviest file of the set, and the one Vich knows nothing about either - left behind, it would sit on disk with nothing linking to it
    public function testPostFlushRemovesTheKeptOriginalOfARemovedMedia(): void
    {
        $media = new GalleryMedia();
        $media->setFilename('medias/gallery/main/vacances/media.webp');
        $media->setOriginalFilename('medias/gallery/main/vacances/media-original.jpg');
        $this->touch('medias/gallery/main/vacances/media-thumb.webp');
        $this->filesystem->dumpFile($this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/medias/gallery/main/vacances/media-original.jpg', 'x');

        $em = $this->createStub(EntityManagerInterface::class);

        $listener = $this->createListener();
        $listener->preRemove(new PreRemoveEventArgs($media, $em));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->assertFileDoesNotExist($this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/medias/gallery/main/vacances/media-original.jpg');
        $this->assertFileDoesNotExist($this->projectDir . '/public/medias/gallery/main/vacances/media-thumb.webp');
    }

    // A media with no original kept has nothing to queue outside public/, and must not go looking for one
    public function testPostFlushLeavesPrivateAloneForAMediaWithoutAnOriginal(): void
    {
        $media = new GalleryMedia();
        $media->setFilename('medias/gallery/main/vacances/media.webp');
        $this->filesystem->dumpFile($this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/medias/gallery/main/vacances/media-original.jpg', 'x');

        $em = $this->createStub(EntityManagerInterface::class);

        $listener = $this->createListener();
        $listener->preRemove(new PreRemoveEventArgs($media, $em));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->assertFileExists($this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/medias/gallery/main/vacances/media-original.jpg');
    }

    // A category deleted with its medias would otherwise leave its own directory behind on both sides, empty and named after a slug nothing points at any more
    public function testPostFlushRemovesTheCategoryDirectoryOnceEmptied(): void
    {
        $media = new GalleryMedia();
        $media->setFilename('medias/gallery/vacances/media.webp');
        $media->setOriginalFilename('medias/gallery/vacances/media-original.jpg');
        $this->touch('medias/gallery/vacances/media-thumb.webp');
        $this->touch('medias/gallery/vacances/media-highres.webp');
        $this->filesystem->dumpFile($this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/medias/gallery/vacances/media-original.jpg', 'x');

        $em = $this->createStub(EntityManagerInterface::class);

        $listener = $this->createListener();
        $listener->preRemove(new PreRemoveEventArgs($media, $em));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->assertDirectoryDoesNotExist($this->projectDir . '/public/medias/gallery/vacances');
        $this->assertDirectoryDoesNotExist($this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/medias/gallery/vacances');
        // Only the category's own directory goes, never the root every category sits under
        $this->assertDirectoryExists($this->projectDir . '/public/medias/gallery');
    }

    // Vich removes the stored (medium) files in this very postFlush, and nothing says it has run first - a directory still holding one is left alone rather than fought over
    public function testPostFlushLeavesADirectoryThatStillHoldsAFile(): void
    {
        $media = new GalleryMedia();
        $media->setFilename('medias/gallery/vacances/media.webp');
        $this->touch('medias/gallery/vacances/media.webp');
        $this->touch('medias/gallery/vacances/media-thumb.webp');

        $em = $this->createStub(EntityManagerInterface::class);

        $listener = $this->createListener();
        $listener->preRemove(new PreRemoveEventArgs($media, $em));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->assertDirectoryExists($this->projectDir . '/public/medias/gallery/vacances');
        $this->assertFileExists($this->projectDir . '/public/medias/gallery/vacances/media.webp');
    }

    public function testPreRemoveIgnoresEntitiesThatAreNotGalleryMedia(): void
    {
        $this->touch('medias/gallery/main/vacances/media-thumb.webp');

        $em = $this->createStub(EntityManagerInterface::class);

        $listener = $this->createListener();
        $listener->preRemove(new PreRemoveEventArgs(new \stdClass(), $em));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->assertFileExists($this->projectDir . '/public/medias/gallery/main/vacances/media-thumb.webp');
    }
}
