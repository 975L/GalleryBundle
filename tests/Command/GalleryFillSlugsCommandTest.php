<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Command;

use c975L\GalleryBundle\Command\GalleryFillSlugsCommand;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\String\Slugger\AsciiSlugger;

class GalleryFillSlugsCommandTest extends TestCase
{
    private function createTester(array $categories, ?EntityManagerInterface $entityManager = null): CommandTester
    {
        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findAll')->willReturn($categories);

        return new CommandTester(new GalleryFillSlugsCommand(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $categoryRepository,
            new GalleryMediaSlugger(new AsciiSlugger()),
        ));
    }

    private function createCategory(string $slug, GalleryMedia ...$medias): GalleryCategory
    {
        $category = new GalleryCategory()->setSlug($slug);
        foreach ($medias as $media) {
            $category->addMedia($media);
        }

        return $category;
    }

    // A gallery filled before medias had a slug: their public url is built on it, so one left without is unreachable
    public function testEveryMediaWithoutASlugGetsOneFromItsTitle(): void
    {
        $media = new GalleryMedia()->setTitle('Col du Galibier');
        $tester = $this->createTester([$this->createCategory('voyages', $media)]);

        $tester->execute([]);

        $this->assertSame('col-du-galibier', $media->getSlug());
        $this->assertStringContainsString('1 media(s) given a slug', $tester->getDisplay());
    }

    // A second run must not renumber what the first one wrote
    public function testAMediaThatAlreadyHasASlugIsLeftAlone(): void
    {
        $media = new GalleryMedia()->setTitle('Mont Blanc')->setSlug('sommet');
        $tester = $this->createTester([$this->createCategory('voyages', $media)]);

        $tester->execute([]);

        $this->assertSame('sommet', $media->getSlug());
        $this->assertStringContainsString('0 media(s) given a slug', $tester->getDisplay());
    }

    // The slug is only unique within its category, and a media already carrying one still counts as taken
    public function testACollisionWithinTheCategoryIsSuffixed(): void
    {
        $stored = new GalleryMedia()->setTitle('Mont Blanc')->setSlug('mont-blanc');
        $first = new GalleryMedia()->setTitle('Mont Blanc');
        $second = new GalleryMedia()->setTitle('Mont Blanc');
        $tester = $this->createTester([$this->createCategory('voyages', $stored, $first, $second)]);

        $tester->execute([]);

        $this->assertSame(['mont-blanc-2', 'mont-blanc-3'], [$first->getSlug(), $second->getSlug()]);
    }

    public function testTheSameTitleInAnotherCategoryIsNotACollision(): void
    {
        $first = new GalleryMedia()->setTitle('Mont Blanc');
        $second = new GalleryMedia()->setTitle('Mont Blanc');
        $tester = $this->createTester([
            $this->createCategory('voyages', $first),
            $this->createCategory('portraits', $second),
        ]);

        $tester->execute([]);

        $this->assertSame(['mont-blanc', 'mont-blanc'], [$first->getSlug(), $second->getSlug()]);
    }

    // Nothing is written, and the listing still tells two same-titled medias apart
    public function testADryRunWritesNothing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $tester = $this->createTester(
            [$this->createCategory('voyages', new GalleryMedia()->setTitle('Mont Blanc'), new GalleryMedia()->setTitle('Mont Blanc'))],
            $entityManager,
        );

        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('voyages/mont-blanc', $display);
        $this->assertStringContainsString('voyages/mont-blanc-2', $display);
        $this->assertStringContainsString('2 media(s) would be given a slug', $display);
    }
}
