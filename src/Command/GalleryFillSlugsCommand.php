<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Command;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// One-off, for a gallery filled before medias had a slug: their public url is built on it (see GalleryController::media), so a media left without one is unreachable
// Category by category rather than in one query, the slug only being unique within a category - which is exactly what GalleryMediaSlugger reads off the collection it is handed
#[AsCommand(
    name: 'c975l:gallery:fill-slugs',
    description: 'Give a slug to every gallery media that has none, built from its title'
)]
class GalleryFillSlugsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryMediaSlugger $mediaSlugger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be written without writing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $filled = 0;

        // A dry run assigns too, in memory, and simply never flushes: two untitled medias of the same category are told apart by the very slug the first one was just given, so listing without assigning would announce the same slug twice
        foreach ($this->categoryRepository->findAll() as $category) {
            $filled += $this->fillCategory($io, $category);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf('%d media(s) %s.', $filled, $dryRun ? 'would be given a slug' : 'given a slug'));

        return Command::SUCCESS;
    }

    // Medias that already carry a slug are left alone, and still count as taken - a second run must not renumber what the first one wrote
    private function fillCategory(SymfonyStyle $io, GalleryCategory $category): int
    {
        $filled = 0;

        foreach ($category->getMedias() as $media) {
            if (null !== $media->getSlug()) {
                continue;
            }

            $this->mediaSlugger->assign($media);
            $io->writeln(sprintf('  %s/%s', $category->getSlug(), $media->getSlug()));
            ++$filled;
        }

        return $filled;
    }
}
