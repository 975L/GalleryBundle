<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Command;

use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;

// One-off import of a legacy directory-tree gallery; each original goes through the normal Vich flow so fresh derivatives are generated
#[AsCommand(
    name: 'c975l:gallery:import-legacy',
    description: 'Import a legacy Finder/flat-file photo gallery into Gallery/GalleryCategory/GalleryPhoto rows'
)]
class GalleryLegacyImportCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GalleryRepository $galleryRepository,
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly SluggerInterface $slugger,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'source-dir',
                InputArgument::REQUIRED,
                'Legacy photos directory, relative to the project dir (e.g. assets/photos)'
            )
            ->addOption('gallery-slug', null, InputOption::VALUE_REQUIRED, 'Slug of the Gallery to import into', 'main')
            ->addOption(
                'flat',
                null,
                InputOption::VALUE_NONE,
                'Source has no subdirectories - every photo goes into the gallery\'s single "Non classé" category'
            )
            ->addOption(
                'category',
                null,
                InputOption::VALUE_REQUIRED,
                'Slug of the category a flat source lands in, found or created, instead of "Non classé"'
            )
            ->addOption('credits', null, InputOption::VALUE_OPTIONAL, 'Credits applied to every imported photo')
            ->addOption('rights-reserved', null, InputOption::VALUE_NONE, 'Mark every imported photo as rights-reserved')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate without persisting anything')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sourceDir = $this->projectDir . '/' . ltrim($input->getArgument('source-dir'), '/');
        $gallerySlug = $input->getOption('gallery-slug');
        $flat = $input->getOption('flat');
        $credits = $input->getOption('credits');
        $rightsReserved = $input->getOption('rights-reserved');
        $dryRun = $input->getOption('dry-run');

        if (!is_dir($sourceDir)) {
            $io->error("Directory not found: {$sourceDir}");

            return Command::FAILURE;
        }

        $gallery = $this->resolveGallery($gallerySlug, $dryRun);

        $io->title(sprintf('Importing legacy gallery into "%s" (slug: %s)', $gallery->getTitle(), $gallerySlug));
        $io->text([
            "Source dir : {$sourceDir}",
            'Layout     : ' . ($flat ? 'flat (single category)' : 'one category per subdirectory'),
            $dryRun ? '<comment>DRY-RUN — nothing will be persisted</comment>' : '<info>LIVE — changes will be flushed</info>',
        ]);
        $io->newLine();

        $totalCreated = 0;
        $totalSkipped = 0;

        if ($flat) {
            $categorySlug = $input->getOption('category');
            $category = null !== $categorySlug
                ? $this->resolveCategory($gallery, strtolower($this->slugger->slug($categorySlug)->toString()), $dryRun)
                : $this->resolveUncategorized($gallery, $dryRun);
            [$created, $skipped] = $this->importCategory($category, $sourceDir, $credits, $rightsReserved, $dryRun, $io);
            $totalCreated += $created;
            $totalSkipped += $skipped;
        } else {
            $categoryDirs = (new Finder())->directories()->in($sourceDir)->depth(0)->sortByName();

            foreach ($categoryDirs as $categoryDir) {
                $slug = strtolower($this->slugger->slug($categoryDir->getRelativePathname())->toString());
                $category = $this->resolveCategory($gallery, $slug, $dryRun);
                [$created, $skipped] = $this->importCategory($category, $categoryDir->getPathname(), $credits, $rightsReserved, $dryRun, $io);
                $totalCreated += $created;
                $totalSkipped += $skipped;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%d photos %s. %d skipped.',
            $totalCreated,
            $dryRun ? 'would be created' : 'created and flushed',
            $totalSkipped
        ));

        return Command::SUCCESS;
    }

    private function resolveGallery(string $slug, bool $dryRun): Gallery
    {
        $gallery = $this->galleryRepository->findOneBySlug($slug);
        if (null !== $gallery) {
            return $gallery;
        }

        $gallery = (new Gallery())
            ->setSlug($slug)
            ->setTitle($this->humanize($slug))
            ->setDefault(null === $this->galleryRepository->findDefault())
        ;

        if (!$dryRun) {
            $this->em->persist($gallery);
            $this->em->flush();
        }

        return $gallery;
    }

    private function resolveCategory(Gallery $gallery, string $slug, bool $dryRun): GalleryCategory
    {
        $category = $this->categoryRepository->findOneBySlug($gallery, $slug);
        if (null !== $category) {
            return $category;
        }

        $category = (new GalleryCategory())
            ->setGallery($gallery)
            ->setSlug($slug)
            ->setTitle($this->humanize($slug))
        ;

        if (!$dryRun) {
            $this->em->persist($category);
            $this->em->flush();
        }

        return $category;
    }

    // findOrCreateUncategorized() always persists+flushes unconditionally (it's meant to be safe to call from request-time code) - a dry-run must not persist anything, so it's only actually called when live; otherwise a transient stand-in is built for display purposes only
    private function resolveUncategorized(Gallery $gallery, bool $dryRun): GalleryCategory
    {
        if (!$dryRun) {
            return $this->categoryRepository->findOrCreateUncategorized($gallery);
        }

        return $this->categoryRepository->findOneBy(['gallery' => $gallery, 'uncategorized' => true])
            ?? (new GalleryCategory())->setGallery($gallery)->setSlug('non-classe')->setUncategorized(true)
                ->setTitle($this->translator->trans('label.gallery_uncategorized', [], 'gallery'));
    }

    // Skips a category that already has photos, so a re-run after fixing an earlier error doesn't duplicate everything
    private function importCategory(
        GalleryCategory $category,
        string $dir,
        ?string $credits,
        bool $rightsReserved,
        bool $dryRun,
        SymfonyStyle $io,
    ): array {
        if ($category->getPhotos()->count() > 0) {
            $io->writeln(sprintf('  <comment>[skip]</comment> %s (already has photos)', $category->getSlug()));

            return [0, 1];
        }

        $files = (new Finder())->files()->in($dir)->name('/\.jpe?g$/i')->depth(0)->sortByName();
        if (!$files->hasResults()) {
            $io->writeln(sprintf('  <comment>[skip]</comment> %s (no .jpg files found)', $category->getSlug()));

            return [0, 1];
        }

        $created = 0;
        $position = 0;
        foreach ($files as $file) {
            $photo = new GalleryPhoto();
            $photo->setFile(new ReplacingFile($file->getPathname(), true, false, false));
            $photo
                ->setAlt($this->humanize($file->getFilenameWithoutExtension()))
                ->setCredits($credits)
                ->setRightsReserved($rightsReserved)
                ->setPosition($position++)
            ;
            $category->addPhoto($photo);

            if (!$dryRun) {
                $this->em->persist($photo);
            }

            ++$created;
        }

        $io->writeln(sprintf(
            '  <info>[+]</info> %s: %d photos%s',
            $category->getSlug(),
            $created,
            $dryRun ? ' <comment>(dry-run)</comment>' : ''
        ));

        return [$created, 0];
    }

    private function humanize(string $slug): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $slug));
    }
}
