<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Command;

use c975L\GalleryBundle\Command\GalleryAutomaticDedupeCommand;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use c975L\UiBundle\Entity\Block;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GalleryAutomaticDedupeCommandTest extends TestCase
{
    private GalleryCategory $duplicate;
    private GalleryCategory $orphan;

    protected function setUp(): void
    {
        $this->duplicate = new GalleryCategory()->setSlug('latest-2')->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $this->orphan = new GalleryCategory()->setSlug('latest');
    }

    private function createTester(?array $pair, EntityManagerInterface $entityManager, ?GalleryUrlRedirector $urlRedirector = null): CommandTester
    {
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findAutomaticDuplicate')->willReturn($pair);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => '/galleries/' . $parameters['category']);

        return new CommandTester(new GalleryAutomaticDedupeCommand(
            $entityManager,
            $categoryRepository,
            $urlRedirector ?? $this->createStub(GalleryUrlRedirector::class),
            $urlGenerator,
        ));
    }

    // The original keeps its url and takes the flag back, the duplicate is removed and its urls redirected there
    public function testTheDuplicateIsMergedIntoTheLeftover(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($this->duplicate);
        $entityManager->expects($this->once())->method('flush');

        $urlRedirector = $this->createMock(GalleryUrlRedirector::class);
        $urlRedirector->expects($this->exactly(2))->method('record')->willReturnCallback(function (EntityManagerInterface $entityManager, string $fromPath, string $toUrl): void {
            $this->assertContains($fromPath, ['/galleries/latest-2', '/galleries/latest-2/*']);
            $this->assertSame('/galleries/latest', $toUrl);
        });

        $tester = $this->createTester([$this->duplicate, $this->orphan], $entityManager, $urlRedirector);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame(GalleryCategory::AUTOMATIC_LATEST, $this->orphan->getAutomaticKind());
    }

    // A dry run lists the pair and writes nothing
    public function testADryRunWritesNothing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        $tester = $this->createTester([$this->duplicate, $this->orphan], $entityManager);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $this->assertStringContainsString('latest-2 -> latest', $tester->getDisplay());
        $this->assertFalse($this->orphan->isAutomatic());
    }

    // Its heading blocks would go with it, cascade remove: an admin composed them, so they are moved by hand first
    public function testADuplicateCarryingBlocksIsLeftAlone(): void
    {
        $this->duplicate->addBlock(new Block());

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        $this->assertSame(Command::FAILURE, $this->createTester([$this->duplicate, $this->orphan], $entityManager)->execute([]));
    }

    // A site that never doubled anything, or already merged
    public function testNothingToMergeIsASuccess(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $this->assertSame(Command::SUCCESS, $this->createTester(null, $entityManager)->execute([]));
    }
}
