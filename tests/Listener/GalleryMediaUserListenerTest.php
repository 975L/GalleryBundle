<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Listener;

use c975L\ConfigBundle\Contract\UserInterface;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Listener\GalleryMediaUserListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

// The listener assigns whatever implements c975L\ConfigBundle\Contract\UserInterface, not the application's App\Entity\User directly, so a plain stub of that interface covers the "somebody is logged in" branches without the app being there
class GalleryMediaUserListenerTest extends TestCase
{
    private function createPersistArgs(object $entity): PrePersistEventArgs
    {
        return new PrePersistEventArgs($entity, $this->createStub(ObjectManager::class));
    }

    private function createUpdateArgs(object $entity, EntityManagerInterface $entityManager): PreUpdateEventArgs
    {
        $changeSet = [];

        return new PreUpdateEventArgs($entity, $entityManager, $changeSet);
    }

    // Skipped rather than stubbed while the interface ships in a ConfigBundle newer than the released one this checkout pulls - duplicating it here would hide the day the real one changes
    private function createUserStub(): UserInterface
    {
        if (!interface_exists(UserInterface::class)) {
            self::markTestSkipped('c975L\ConfigBundle\Contract\UserInterface not available in the installed c975l/config-bundle');
        }

        return $this->createStub(UserInterface::class);
    }

    public function testPrePersistIgnoresEntitiesThatAreNotGalleryMedia(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('getUser');

        new GalleryMediaUserListener($security)->prePersist($this->createPersistArgs(new \stdClass()));

        $this->addToAssertionCount(1);
    }

    public function testPrePersistLeavesUserNullWhenNobodyIsLoggedIn(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $media = new GalleryMedia();

        new GalleryMediaUserListener($security)->prePersist($this->createPersistArgs($media));

        $this->assertNull($media->getUser());
    }

    public function testPreUpdateIgnoresEntitiesThatAreNotGalleryMedia(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('getUser');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('getUnitOfWork');

        new GalleryMediaUserListener($security)->preUpdate($this->createUpdateArgs(new \stdClass(), $entityManager));

        $this->addToAssertionCount(1);
    }

    // Nobody logged in (CLI import, expired session...): the changeset must not be recomputed for nothing - only a real assignment justifies the extra recompute cost
    public function testPreUpdateDoesNotRecomputeChangeSetWhenNobodyIsLoggedIn(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('getUnitOfWork');

        $media = new GalleryMedia();

        new GalleryMediaUserListener($security)->preUpdate($this->createUpdateArgs($media, $entityManager));

        $this->assertNull($media->getUser());
    }

    // Somebody logged in on a creation: the media simply gets the user, no changeset to recompute since Doctrine has not diffed anything yet
    public function testPrePersistAssignsTheLoggedInUser(): void
    {
        $user = $this->createStub(UserInterface::class);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $media = new GalleryMedia();

        new GalleryMediaUserListener($security)->prePersist($this->createPersistArgs($media));

        $this->assertSame($user, $media->getUser());
    }

    // Somebody logged in on an update: the media's user is overwritten (the listener tracks the last editor, not the uploader) and the changeset is recomputed so Doctrine actually includes "user" in the SQL UPDATE, even when it's the only field that changed
    public function testPreUpdateAssignsTheLoggedInUserAndRecomputesTheChangeSet(): void
    {
        $newUser = $this->createStub(UserInterface::class);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($newUser);

        // A real instance rather than a stub - PHPUnit's mock generator otherwise trips one of ClassMetadata's own @deprecated methods while building the double
        $classMetadata = new ClassMetadata(GalleryMedia::class);
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects($this->once())
            ->method('recomputeSingleEntityChangeSet')
            ->with($classMetadata, $this->isInstanceOf(GalleryMedia::class));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('getClassMetadata')->with(GalleryMedia::class)->willReturn($classMetadata);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        $media = new GalleryMedia()->setUser($this->createStub(UserInterface::class));

        new GalleryMediaUserListener($security)->preUpdate($this->createUpdateArgs($media, $entityManager));

        $this->assertSame($newUser, $media->getUser());
    }
}
