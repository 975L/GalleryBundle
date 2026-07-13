<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Listener;

use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Listener\GalleryPhotoDerivativeCleanupListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class GalleryPhotoDerivativeCleanupListenerTest extends TestCase
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

    private function createListener(): GalleryPhotoDerivativeCleanupListener
    {
        $parameterBag = $this->createStub(ParameterBagInterface::class);
        $parameterBag->method('get')->willReturn($this->projectDir);

        return new GalleryPhotoDerivativeCleanupListener($parameterBag);
    }

    private function touch(string $relativePath): void
    {
        $this->filesystem->dumpFile($this->projectDir . '/public/' . $relativePath, 'x');
    }

    public function testPostFlushRemovesTheOldDerivativesWhenAFileWasReplaced(): void
    {
        $photo = new GalleryPhoto();
        $photo->setFilename('medias/gallery/main/vacances/photo-old.webp');
        $this->touch('medias/gallery/main/vacances/photo-old-thumb.webp');
        $this->touch('medias/gallery/main/vacances/photo-old-highres.webp');

        // Simulates the form having bound a newly uploaded file - before Vich (which runs later, at
        // a lower listener priority) renames it and overwrites this entity's own filename
        $photo->setFile(new UploadedFile(__FILE__, 'new.webp', test: true));

        $em = $this->createStub(EntityManagerInterface::class);
        $changeSet = [];

        $listener = $this->createListener();
        $listener->preUpdate(new PreUpdateEventArgs($photo, $em, $changeSet));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->assertFileDoesNotExist($this->projectDir . '/public/medias/gallery/main/vacances/photo-old-thumb.webp');
        $this->assertFileDoesNotExist($this->projectDir . '/public/medias/gallery/main/vacances/photo-old-highres.webp');
    }

    public function testPreUpdateIgnoresEntitiesWithNoPendingFile(): void
    {
        $photo = new GalleryPhoto();
        $photo->setFilename('medias/gallery/main/vacances/photo-old.webp');
        $this->touch('medias/gallery/main/vacances/photo-old-thumb.webp');

        $em = $this->createStub(EntityManagerInterface::class);
        $changeSet = [];

        $listener = $this->createListener();
        $listener->preUpdate(new PreUpdateEventArgs($photo, $em, $changeSet));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->assertFileExists($this->projectDir . '/public/medias/gallery/main/vacances/photo-old-thumb.webp');
    }

    public function testPreUpdateIgnoresEntitiesThatAreNotGalleryPhoto(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $changeSet = [];

        $listener = $this->createListener();
        $listener->preUpdate(new PreUpdateEventArgs(new \stdClass(), $em, $changeSet));
        $listener->postFlush(new PostFlushEventArgs($em));

        $this->addToAssertionCount(1);
    }
}
