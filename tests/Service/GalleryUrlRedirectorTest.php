<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\ConfigBundle\Entity\Redirect;
use c975L\ConfigBundle\Repository\RedirectRepository;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class GalleryUrlRedirectorTest extends TestCase
{
    private function createRedirectRepository(array $byFromPath = []): RedirectRepository
    {
        $redirectRepository = $this->createStub(RedirectRepository::class);
        $redirectRepository->method('findOneByFromPath')->willReturnCallback(
            static fn (string $fromPath): ?Redirect => $byFromPath[$fromPath] ?? null
        );

        return $redirectRepository;
    }

    private function createEntityManager(array &$persisted, array &$removed): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        return $entityManager;
    }

    public function testAMovedUrlIsRecordedAsAPermanentRedirect(): void
    {
        $persisted = [];
        $removed = [];

        (new GalleryUrlRedirector($this->createRedirectRepository()))
            ->record($this->createEntityManager($persisted, $removed), '/gallery/voyages', '/gallery/vacances');

        $this->assertCount(1, $persisted);
        $this->assertSame('/gallery/voyages', $persisted[0]->getFromPath());
        $this->assertSame('/gallery/vacances', $persisted[0]->getToUrl());
        $this->assertTrue($persisted[0]->isPermanent());
    }

    // Nothing moved, nothing to redirect - a save that leaves the url alone must not write a row pointing at itself
    public function testNothingIsRecordedWhenTheUrlIsUnchanged(): void
    {
        $persisted = [];
        $removed = [];

        (new GalleryUrlRedirector($this->createRedirectRepository()))
            ->record($this->createEntityManager($persisted, $removed), '/gallery/voyages', '/gallery/voyages');

        $this->assertSame([], $persisted);
    }

    // A second rename reuses the row the first one left behind rather than adding another one for the same old url
    public function testTheRowOfAnAlreadyRedirectedUrlIsReused(): void
    {
        $existing = (new Redirect())->setFromPath('/gallery/voyages')->setToUrl('/gallery/somewhere-else');
        $persisted = [];
        $removed = [];

        (new GalleryUrlRedirector($this->createRedirectRepository(['/gallery/voyages' => $existing])))
            ->record($this->createEntityManager($persisted, $removed), '/gallery/voyages', '/gallery/vacances');

        $this->assertSame([$existing], $persisted);
        $this->assertSame('/gallery/vacances', $existing->getToUrl());
    }

    // Renamed and then renamed back, the two rows would otherwise point at each other and the browser would loop between them
    public function testTheRedirectComingBackTheOtherWayIsDropped(): void
    {
        $reverse = (new Redirect())->setFromPath('/gallery/vacances')->setToUrl('/gallery/voyages');
        $persisted = [];
        $removed = [];

        (new GalleryUrlRedirector($this->createRedirectRepository(['/gallery/vacances' => $reverse])))
            ->record($this->createEntityManager($persisted, $removed), '/gallery/voyages', '/gallery/vacances');

        $this->assertSame([$reverse], $removed);
    }

    // The wildcard written alongside a renamed category: left behind, it would prefix-match every media url under the slug that has just been freed
    public function testTheWildcardComingBackTheOtherWayIsDroppedToo(): void
    {
        $reverse = (new Redirect())->setFromPath('/gallery/vacances')->setToUrl('/gallery/voyages');
        $reverseWildcard = (new Redirect())->setFromPath('/gallery/vacances/*')->setToUrl('/gallery/voyages');
        $persisted = [];
        $removed = [];

        (new GalleryUrlRedirector($this->createRedirectRepository([
            '/gallery/vacances' => $reverse,
            '/gallery/vacances/*' => $reverseWildcard,
        ])))->record($this->createEntityManager($persisted, $removed), '/gallery/voyages', '/gallery/vacances');

        $this->assertSame([$reverse, $reverseWildcard], $removed);
    }
}
