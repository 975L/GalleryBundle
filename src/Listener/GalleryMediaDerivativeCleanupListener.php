<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Listener;

use c975L\GalleryBundle\Entity\GalleryMedia;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

// Vich's own "delete_on_update"/"delete_on_remove" only handles a GalleryMedia's own stored (medium) file - the -thumb/-highres siblings UiBundle's VichImageResizeListener generates alongside it, and the -original it copies aside when the media asks for one, are plain files Vich has no idea about, so they're removed here. Single place responsible for that, on every path: file replaced (preUpdate), media deleted from the CRUD, category cascade, or import replacing a category's whole collection through orphanRemoval (preRemove). Priority 100 on preUpdate runs this before Vich's own "clean" listener (priority 50, see VichUploaderExtension::registerListeners) has a chance to erase the old filename, so GalleryMedia::getFilename() here still reflects the file being replaced. The actual deletion is deferred to postFlush so a failed flush never removes still-in-use files. #[AsDoctrineListener] only reads the class-level attribute (TARGET_CLASS) - Doctrine then calls whichever method matches each tagged event's name, hence one attribute per event here rather than per method.
#[AsDoctrineListener(event: Events::preUpdate, priority: 100)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class GalleryMediaDerivativeCleanupListener
{
    /** @var string[] */
    private array $pendingRemovals = [];

    public function __construct(private readonly ParameterBagInterface $parameterBag)
    {
    }

    // Only when a new file is actually being uploaded - a plain metadata edit keeps its derivatives
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof GalleryMedia || !$entity->getFile() instanceof File) {
            return;
        }

        $this->queueDerivatives($entity);
    }

    // Fired for a CRUD delete, a category cascade, and an orphanRemoval alike (see GalleryImportProvider replacing a category's medias)
    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof GalleryMedia) {
            return;
        }

        $this->queueDerivatives($entity);
    }

    // Queued as absolute paths rather than as names under one root: the derivatives sit in public/, the kept original outside it (see GalleryMedia::ORIGINAL_DIRECTORY), and both go the same way
    private function queueDerivatives(GalleryMedia $media): void
    {
        $projectDir = $this->parameterBag->get('kernel.project_dir');

        foreach ([$media->getThumbnailFilename(), $media->getHighresFilename()] as $filename) {
            if (null !== $filename) {
                $this->pendingRemovals[] = $projectDir . '/public/' . $filename;
            }
        }

        // Vich knows nothing of it either, it being a plain copy taken before the resize (see UiBundle's VichImageResizeListener::keepOriginal) - a media deleted with its original left behind would leave the heaviest file of the set on disk, and the one nothing links to any more
        if (null !== $media->getOriginalFilename()) {
            $this->pendingRemovals[] = $projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/' . $media->getOriginalFilename();
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pendingRemovals) {
            return;
        }

        $filesystem = new Filesystem();
        $filesystem->remove($this->pendingRemovals);

        // A category deleted with its medias leaves its own directory behind on both sides, empty and named after a slug nothing points at any more - it is what the medias of one category are grouped under (see GalleryMedia::getVichMediaPath)
        // Only ever removed once actually empty, so a directory still holding a stored (medium) file - Vich removes those in this very postFlush, and nothing guarantees it has run first - is simply left alone rather than fought over
        foreach ($this->emptyDirectories() as $directory) {
            $filesystem->remove($directory);
        }

        $this->pendingRemovals = [];
    }

    /** @return string[] */
    private function emptyDirectories(): array
    {
        $directories = array_unique(array_map('dirname', $this->pendingRemovals));

        return array_filter(
            $directories,
            static fn (string $directory): bool => is_dir($directory) && !(new \FilesystemIterator($directory))->valid()
        );
    }
}
