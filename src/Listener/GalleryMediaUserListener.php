<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Listener;

use c975L\ConfigBundle\Contract\UserInterface;
use c975L\GalleryBundle\Entity\GalleryMedia;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

// Mirrors UiBundle's own BlockUserListener - kept separate rather than shared since UiBundle must stay standalone (it can't reference this bundle's GalleryMedia entity). Tracks who last touched the media, not who originally uploaded it - $user is overwritten on every save (see preUpdate below)
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class GalleryMediaUserListener
{
    public function __construct(private readonly Security $security)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->assignUser($args->getObject());
    }

    // Doctrine already computed the update changeset by the time preUpdate fires, so a plain setter call here would be silently dropped from the SQL UPDATE - recomputeSingleEntityChangeSet() forces Doctrine to pick up the just-assigned user even when it's the only field that changed
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->assignUser($entity)) {
            return;
        }

        $entityManager = $args->getObjectManager();
        $entityManager->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $entityManager->getClassMetadata($entity::class),
            $entity
        );
    }

    // Assigns the currently logged-in user to the entity, if any - returns whether it did
    private function assignUser(object $entity): bool
    {
        if (!$entity instanceof GalleryMedia) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof UserInterface) {
            return false;
        }

        $entity->setUser($user);

        return true;
    }
}
