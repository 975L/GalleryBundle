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
use c975L\GalleryBundle\Listener\GalleryPhotoUserListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

// App\Entity\User (the type this listener actually assigns) belongs to the consuming application,
// not to this standalone bundle checkout - so only the branches reachable without constructing one
// are covered here (no logged-in App\Entity\User available to test the happy path)
class GalleryPhotoUserListenerTest extends TestCase
{
    private function createArgs(object $entity): PrePersistEventArgs
    {
        return new PrePersistEventArgs($entity, $this->createStub(ObjectManager::class));
    }

    public function testPrePersistIgnoresEntitiesThatAreNotGalleryPhoto(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('getUser');

        (new GalleryPhotoUserListener($security))->prePersist($this->createArgs(new \stdClass()));

        $this->addToAssertionCount(1);
    }

    public function testPrePersistLeavesUserNullWhenNobodyIsLoggedIn(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $photo = new GalleryPhoto();

        (new GalleryPhotoUserListener($security))->prePersist($this->createArgs($photo));

        $this->assertNull($photo->getUser());
    }
}
