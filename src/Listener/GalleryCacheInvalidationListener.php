<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Listener;

use c975L\ConfigBundle\Entity\Config;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use c975L\GalleryBundle\Service\GalleryBlockCacheInvalidator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

// Drops the cached renders of this bundle's blocks whenever what they show changes - a gallery renamed or set aside, a photograph added, hidden or moved, and the settings those blocks are drawn from
// Always once the flush is over, never during it: the tag goes out after the commit, so a rollback never leaves the cache emptied for a change that was undone
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class GalleryCacheInvalidationListener
{
    // The entries the cached blocks are drawn from. The first four feed the automatic galleries: emptying either pair closes the gallery it belongs to (see GalleryLatestProvider and GalleryPrintableProvider), which adds a gallery to the listing or takes one out of it without a single row of this bundle being touched
    // The last two are read by the blocks' own markup: the prefix opens every url they print (see GalleryRoutePrefix, which owes them a cache to drop for its "no cache to clear" to hold), and the thumbnail setting picks the class each tile is drawn with (see components/Gallery/Category.html.twig and Media.html.twig)
    private const array CONFIG_SLUGS = [
        'gallery-latest-days',
        'gallery-latest-max',
        'gallery-print-enabled',
        'gallery-printable-max',
        GalleryRoutePrefix::SLUG,
        'gallery-thumbnail-whole',
    ];

    // Nothing drops the tag before the flush is over: a settings group is saved as a batch of rows and a gallery is emptied one photograph at a time, so dropping it on each would do it once per row, inside the transaction a rollback could still undo
    private bool $stale = false;

    public function __construct(private readonly GalleryBlockCacheInvalidator $invalidator)
    {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->stale) {
            return;
        }

        $this->stale = false;
        $this->invalidator->invalidateGalleries();
    }

    private function invalidate(object $entity): void
    {
        match (true) {
            $entity instanceof GalleryCategory,
            $entity instanceof GalleryMedia => $this->stale = true,
            $entity instanceof Config => $this->markIfGalleryConfig($entity),
            default => null,
        };
    }

    // Only the entries the blocks actually hang on, so saving an unrelated setting costs nothing
    private function markIfGalleryConfig(Config $config): void
    {
        if (in_array($config->getSlug(), self::CONFIG_SLUGS, true)) {
            $this->stale = true;
        }
    }
}
