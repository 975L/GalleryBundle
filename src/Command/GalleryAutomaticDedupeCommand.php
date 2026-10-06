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
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// One-off, for a site updated before findOrCreateAutomatic() took back the gallery a lost v1.12 flag left behind, which wrote a "latest-2" beside it: the original keeps its url, indexed and linked from menus for longest, and takes the flag back, the duplicate is removed and its url redirected there
#[AsCommand(
    name: 'c975l:gallery:automatic:dedupe',
    description: 'Merge the automatic gallery written beside the one a lost flag left behind'
)]
class GalleryAutomaticDedupeCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryUrlRedirector $urlRedirector,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be merged without writing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pair = $this->categoryRepository->findAutomaticDuplicate(GalleryCategory::AUTOMATIC_LATEST);
        if (null === $pair) {
            $io->success('No duplicate automatic gallery.');

            return Command::SUCCESS;
        }

        [$duplicate, $orphan] = $pair;

        // Its heading blocks would go with it (cascade remove): an admin composed them, so they are moved by hand first rather than lost here
        if (!$duplicate->getBlocks()->isEmpty()) {
            $io->warning(sprintf('"%s" carries blocks: move them to "%s" first, then run this again.', $duplicate->getSlug(), $orphan->getSlug()));

            return Command::FAILURE;
        }

        $io->writeln(sprintf('  %s -> %s', $duplicate->getSlug(), $orphan->getSlug()));
        if ((bool) $input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        // Same pair of rows as a rename (see GalleryCategoryCrudController::redirectSlugChange): the category url, then every media url below it
        $oldUrl = $this->urlGenerator->generate('gallery_category', ['category' => $duplicate->getSlug()]);
        $newUrl = $this->urlGenerator->generate('gallery_category', ['category' => $orphan->getSlug()]);
        $this->urlRedirector->record($this->entityManager, $oldUrl, $newUrl);
        $this->urlRedirector->record($this->entityManager, $oldUrl . '/*', $newUrl);

        $this->entityManager->remove($duplicate);
        $orphan->setAutomaticKind(GalleryCategory::AUTOMATIC_LATEST);
        $this->entityManager->flush();

        $io->success(sprintf('"%s" merged into "%s".', $duplicate->getSlug(), $orphan->getSlug()));

        return Command::SUCCESS;
    }
}
