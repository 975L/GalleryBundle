<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Listener;

use App\Entity\User;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Symfony\Bundle\SecurityBundle\Security;

// Mirrors UiBundle's own BlockUserListener - kept separate rather than shared since UiBundle must
// stay standalone (it can't reference this bundle's GalleryPhoto entity)
#[AsDoctrineListener(event: Events::prePersist)]
class GalleryPhotoUserListener
{
    public function __construct(private Security $security) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof GalleryPhoto) {
            return;
        }

        if (null !== $entity->getUser()) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $entity->setUser($user);
        }
    }
}
