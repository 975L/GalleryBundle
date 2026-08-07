<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Command;

use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Service\GalleryThumbnailRebuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// One-off, for a gallery filled while thumbnails were cropped square: their files are the only ones the change to UiBundle's VichImageResizeListener leaves behind, an upload generating its own the new way
// Nothing is read from the database and nothing is written to it - only the "-thumb.webp" files are, from the highres derivative each media already carries
#[AsCommand(
    name: 'c975l:gallery:rebuild-thumbnails',
    description: 'Rebuild every gallery thumbnail so it holds the whole photo instead of a square crop'
)]
class GalleryRebuildThumbnailsCommand extends Command
{
    public function __construct(
        private readonly GalleryMediaRepository $mediaRepository,
        private readonly GalleryThumbnailRebuilder $thumbnailRebuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the medias that would be rebuilt without writing a file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $rebuilt = 0;
        $skipped = [];

        foreach ($this->mediaRepository->findAll() as $media) {
            if ($dryRun) {
                $io->writeln(sprintf('  %s', (string) $media->getThumbnailFilename()));
                ++$rebuilt;

                continue;
            }

            if ($this->thumbnailRebuilder->rebuild($media)) {
                ++$rebuilt;

                continue;
            }

            // Named rather than counted: a media whose files went missing is a gallery to look at, not a number to report
            $skipped[] = sprintf('%s/%s', (string) $media->getCategory()?->getSlug(), (string) $media->getSlug());
        }

        if ([] !== $skipped) {
            $io->warning(sprintf("No file left to rebuild the thumbnail from:\n- %s", implode("\n- ", $skipped)));
        }

        $io->success(sprintf('%d thumbnail(s) %s.', $rebuilt, $dryRun ? 'would be rebuilt' : 'rebuilt'));

        return Command::SUCCESS;
    }
}
