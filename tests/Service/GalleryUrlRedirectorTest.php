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
    private function createRedirectRepository(array $byFromPath = [], array $byToUrl = [], array $underPrefix = []): RedirectRepository
    {
        $redirectRepository = $this->createStub(RedirectRepository::class);
        $redirectRepository->method('findOneByFromPath')->willReturnCallback(
            static fn (string $fromPath): ?Redirect => $byFromPath[$fromPath] ?? null
        );
        $redirectRepository->method('findByToUrl')->willReturnCallback(
            static fn (string $toUrl): array => $byToUrl[$toUrl] ?? []
        );
        $redirectRepository->method('findByFromPathPrefix')->willReturnCallback(
            static fn (string $prefix): array => $underPrefix[$prefix] ?? []
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

        new GalleryUrlRedirector($this->createRedirectRepository())
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

        new GalleryUrlRedirector($this->createRedirectRepository())
            ->record($this->createEntityManager($persisted, $removed), '/gallery/voyages', '/gallery/voyages');

        $this->assertSame([], $persisted);
    }

    // A second rename reuses the row the first one left behind rather than adding another one for the same old url
    public function testTheRowOfAnAlreadyRedirectedUrlIsReused(): void
    {
        $existing = new Redirect()->setFromPath('/gallery/voyages')->setToUrl('/gallery/somewhere-else');
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository(['/gallery/voyages' => $existing]))
            ->record($this->createEntityManager($persisted, $removed), '/gallery/voyages', '/gallery/vacances');

        $this->assertSame([$existing], $persisted);
        $this->assertSame('/gallery/vacances', $existing->getToUrl());
    }

    // Deleted and then renamed onto that very url: the row would otherwise carry both a destination and the gone flag, and the 410 would win over the redirect
    public function testTheRowOfADeletedUrlStopsAnsweringGoneWhenItRedirectsAgain(): void
    {
        $existing = new Redirect()->setFromPath('/gallery/voyages')->setGone(true);
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository(['/gallery/voyages' => $existing]))
            ->record($this->createEntityManager($persisted, $removed), '/gallery/voyages', '/gallery/vacances');

        $this->assertSame([$existing], $persisted);
        $this->assertSame('/gallery/vacances', $existing->getToUrl());
        $this->assertFalse($existing->isGone());
    }

    // Renamed and then renamed back, the two rows would otherwise point at each other and the browser would loop between them
    public function testTheRedirectComingBackTheOtherWayIsDropped(): void
    {
        $reverse = new Redirect()->setFromPath('/gallery/vacances')->setToUrl('/gallery/voyages');
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository(['/gallery/vacances' => $reverse]))
            ->record($this->createEntityManager($persisted, $removed), '/gallery/voyages', '/gallery/vacances');

        $this->assertSame([$reverse], $removed);
    }

    // The wildcard written alongside a renamed category: left behind, it would prefix-match every media url under the slug that has just been freed
    public function testTheWildcardComingBackTheOtherWayIsDroppedToo(): void
    {
        $reverse = new Redirect()->setFromPath('/gallery/vacances')->setToUrl('/gallery/voyages');
        $reverseWildcard = new Redirect()->setFromPath('/gallery/vacances/*')->setToUrl('/gallery/voyages');
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository([
            '/gallery/vacances' => $reverse,
            '/gallery/vacances/*' => $reverseWildcard,
        ]))->record($this->createEntityManager($persisted, $removed), '/gallery/voyages', '/gallery/vacances');

        $this->assertSame([$reverse, $reverseWildcard], $removed);
    }

    // A deleted media: the url is declared in the sitemap, so it answers 410 rather than the 404 a crawler retries for months
    public function testADeletedUrlIsRecordedAsGone(): void
    {
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository())
            ->recordGone($this->createEntityManager($persisted, $removed), '/gallery/voyages/mont-blanc');

        $this->assertCount(1, $persisted);
        $this->assertSame('/gallery/voyages/mont-blanc', $persisted[0]->getFromPath());
        $this->assertNull($persisted[0]->getToUrl());
        $this->assertTrue($persisted[0]->isGone());
    }

    // Deleted after a rename, the row that rename left behind is the one reused - and it stops redirecting
    public function testTheRowOfARenamedUrlIsTurnedIntoAGoneOne(): void
    {
        $existing = new Redirect()->setFromPath('/gallery/voyages/mont-blanc')->setToUrl('/gallery/voyages/col-du-galibier');
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository(['/gallery/voyages/mont-blanc' => $existing]))
            ->recordGone($this->createEntityManager($persisted, $removed), '/gallery/voyages/mont-blanc');

        $this->assertSame([$existing], $persisted);
        $this->assertNull($existing->getToUrl());
        $this->assertTrue($existing->isGone());
    }

    // A row still pointing at the deleted url would answer 301 towards a 410 - one hop for nothing, and a chain the health check flags
    public function testTheRowsPointingAtADeletedUrlAnswerGoneThemselves(): void
    {
        $pointing = new Redirect()->setFromPath('/gallery/voyages/mont-blanc')->setToUrl('/gallery/voyages/col-du-galibier');
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository(
            byToUrl: ['/gallery/voyages/col-du-galibier' => [$pointing]],
        ))->recordGone($this->createEntityManager($persisted, $removed), '/gallery/voyages/col-du-galibier');

        $this->assertSame($pointing, $persisted[0]);
        $this->assertNull($pointing->getToUrl());
        $this->assertTrue($pointing->isGone());
    }

    // A deleted category: one wildcard row covers every media url below it, rather than one row per media
    public function testADeletedTreeIsCoveredByItsPathAndASingleWildcardRow(): void
    {
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository())
            ->recordGoneTree($this->createEntityManager($persisted, $removed), '/gallery/voyages');

        $this->assertCount(2, $persisted);
        $this->assertSame(['/gallery/voyages', '/gallery/voyages/*'], array_map(static fn (Redirect $redirect): ?string => $redirect->getFromPath(), $persisted));
        $this->assertTrue($persisted[0]->isGone());
        $this->assertTrue($persisted[1]->isGone());
    }

    // An exact fromPath wins over the wildcard, so the rows earlier renames left below the tree would keep answering 301 instead of letting the 410 apply
    public function testTheRowsLeftUnderADeletedTreeAreDropped(): void
    {
        $stale = new Redirect()->setFromPath('/gallery/voyages/mont-blanc')->setToUrl('/gallery/voyages/col-du-galibier');
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository(
            underPrefix: ['/gallery/voyages/' => [$stale]],
        ))->recordGoneTree($this->createEntityManager($persisted, $removed), '/gallery/voyages');

        $this->assertSame([$stale], $removed);
    }

    // The wildcard row of the tree being deleted sits under that very prefix - dropping it would undo the row just written
    public function testTheWildcardOfTheDeletedTreeIsKept(): void
    {
        $wildcard = new Redirect()->setFromPath('/gallery/voyages/*')->setToUrl('/gallery/vacances');
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository(
            byFromPath: ['/gallery/voyages/*' => $wildcard],
            underPrefix: ['/gallery/voyages/' => [$wildcard]],
        ))->recordGoneTree($this->createEntityManager($persisted, $removed), '/gallery/voyages');

        $this->assertSame([], $removed);
        $this->assertSame($wildcard, $persisted[1]);
        $this->assertTrue($wildcard->isGone());
    }

    // A media moved to another category left a row below the tree pointing at a url that is still live - the wildcard would answer 410 for it
    public function testTheRowsLeavingTheDeletedTreeAreKept(): void
    {
        $moved = new Redirect()->setFromPath('/gallery/voyages/mont-blanc')->setToUrl('/gallery/alpes/mont-blanc');
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository(
            underPrefix: ['/gallery/voyages/' => [$moved]],
        ))->recordGoneTree($this->createEntityManager($persisted, $removed), '/gallery/voyages');

        $this->assertSame([], $removed);
    }

    // A slug freed by a deletion and created again: the 410 has to be lifted, RedirectSubscriber running before the router
    public function testAGoneUrlIsReleasedWhenItIsCreatedAgain(): void
    {
        $gone = new Redirect()->setFromPath('/gallery/voyages')->setGone(true);
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository(['/gallery/voyages' => $gone]))
            ->release($this->createEntityManager($persisted, $removed), '/gallery/voyages');

        $this->assertSame([$gone], $removed);
    }

    // A row redirecting elsewhere is deliberate: creating a page under its old url must not drop the redirect its visitors follow
    public function testAReleasedUrlKeepsARowThatStillRedirects(): void
    {
        $redirect = new Redirect()->setFromPath('/gallery/voyages')->setToUrl('/gallery/vacances');
        $persisted = [];
        $removed = [];

        new GalleryUrlRedirector($this->createRedirectRepository(['/gallery/voyages' => $redirect]))
            ->release($this->createEntityManager($persisted, $removed), '/gallery/voyages');

        $this->assertSame([], $removed);
    }
}
