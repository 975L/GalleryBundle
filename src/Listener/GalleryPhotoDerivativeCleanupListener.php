<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Listener;

use c975L\GalleryBundle\Entity\GalleryPhoto;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

// Vich's own "delete_on_update"/"delete_on_remove" only handles a GalleryPhoto's own stored (medium) file - the -thumb/-highres siblings UiBundle's VichImageResizeListener generates alongside it are plain files Vich has no idea about, so they're removed here. Single place responsible for that, on every path: file replaced (preUpdate), photo deleted from the CRUD, category cascade, or import replacing a category's whole collection through orphanRemoval (preRemove). Priority 100 on preUpdate runs this before Vich's own "clean" listener (priority 50, see VichUploaderExtension::registerListeners) has a chance to erase the old filename, so GalleryPhoto::getFilename() here still reflects the file being replaced. The actual deletion is deferred to postFlush so a failed flush never removes still-in-use files. #[AsDoctrineListener] only reads the class-level attribute (TARGET_CLASS) - Doctrine then calls whichever method matches each tagged event's name, hence one attribute per event here rather than per method.
#[AsDoctrineListener(event: Events::preUpdate, priority: 100)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class GalleryPhotoDerivativeCleanupListener
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
        if (!$entity instanceof GalleryPhoto || !$entity->getFile() instanceof File) {
            return;
        }

        $this->queueDerivatives($entity);
    }

    // Fired for a CRUD delete, a category cascade, and an orphanRemoval alike (see GalleryImportProvider replacing a category's photos)
    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof GalleryPhoto) {
            return;
        }

        $this->queueDerivatives($entity);
    }

    private function queueDerivatives(GalleryPhoto $photo): void
    {
        foreach ([$photo->getThumbnailFilename(), $photo->getHighresFilename()] as $filename) {
            if (null !== $filename) {
                $this->pendingRemovals[] = $filename;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pendingRemovals) {
            return;
        }

        $filesystem = new Filesystem();
        $publicDir = $this->parameterBag->get('kernel.project_dir') . '/public/';

        foreach ($this->pendingRemovals as $filename) {
            $filesystem->remove($publicDir . $filename);
        }

        $this->pendingRemovals = [];
    }
}
