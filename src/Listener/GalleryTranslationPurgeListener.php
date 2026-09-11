<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Listener;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Service\GalleryTranslator;
use c975L\UiBundle\Repository\TranslationRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

// Takes a row's translations away with the row, as ShopBundle's ShopTranslationPurgeListener does for a product - they name their owner rather than pointing at it (see UiBundle's Translation), so no foreign key cascades them, and a new row landing on the same id would inherit them
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postRemove)]
class GalleryTranslationPurgeListener
{
    // The rows being removed and what their translations are filed under, noted while each still has its id
    /** @var \WeakMap<object, array{string, int}> */
    private \WeakMap $pending;

    public function __construct(private readonly TranslationRepository $repository)
    {
        $this->pending = new \WeakMap();
    }

    // Doctrine hands a removed row's id back to null before postRemove is dispatched, so the id is read here while it still exists
    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        $owner = $this->owner($entity);
        $id = null === $owner ? null : $entity->getId();
        if (null === $owner || !\is_int($id)) {
            return;
        }

        $this->pending[$entity] = [$owner, $id];
    }

    // Purged once the row is gone and inside the flush's own transaction, so a removal the database refuses keeps its translations
    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!isset($this->pending[$entity])) {
            return;
        }

        [$owner, $id] = $this->pending[$entity];
        unset($this->pending[$entity]);

        // A DQL delete rather than a remove(): a flush is already running, and nothing here needs hydrating
        $this->repository->deleteByOwner($owner, $id);
    }

    // What a row's translations are filed under, null for a row of another kind
    private function owner(object $entity): ?string
    {
        return match (true) {
            $entity instanceof GalleryCategory => GalleryTranslator::OWNER_CATEGORY,
            $entity instanceof GalleryMedia => GalleryTranslator::OWNER_MEDIA,
            $entity instanceof GalleryPrintFormat => GalleryTranslator::OWNER_PRINT_FORMAT,
            default => null,
        };
    }
}
