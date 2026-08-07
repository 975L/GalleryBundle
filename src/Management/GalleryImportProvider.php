<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\ImportProviderInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use c975L\UiBundle\Management\BlockDataImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;

// Imports a "gallery_category" content export (see GalleryCategoryCrudController::exportSelection/ContentExporter) - the export unit is the GalleryCategory with its Medias, which is the granularity the admin actually checks boxes for. Matches the category by slug; Medias have no natural key of their own, so the category's whole media collection is replaced, same principle as PageImportProvider replacing a Page's Blocks
class GalleryImportProvider implements ImportProviderInterface
{
    public const KIND = 'gallery_category';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryMediaSlugger $mediaSlugger,
        private readonly BlockDataImporter $blockDataImporter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function supportsImport(string $kind): bool
    {
        return self::KIND === $kind;
    }

    public function import(array $items, ?string $filesDir = null): array
    {
        $created = 0;
        $updated = 0;
        $originals = [];

        foreach ($items as $item) {
            $category = $this->categoryRepository->findOneBySlug($item['slug']);
            $isNew = null === $category;
            $category ??= new GalleryCategory();

            $category
                ->setSlug($item['slug'])
                ->setTitle($item['title'])
                ->setPosition($item['position'] ?? 0)
                ->setUncategorized($item['uncategorized'] ?? false)
                ->setCoverMedia(null);

            // The key is optional, an archive exported before the category gained a lead-in staying importable - what it describes then is a category without one, same reading as PageImportProvider
            $this->replaceBlocks($category, $item['blocks'] ?? [], $filesDir);

            // Existing Medias have no natural key to match the imported ones against, so the whole collection is replaced - orphanRemoval on GalleryCategory::$medias deletes the orphaned rows on flush
            foreach ($category->getMedias()->toArray() as $existingMedia) {
                $category->removeMedia($existingMedia);
            }

            // "photos"/"coverPhotoIndex" are what an archive exported before the rename carries: read as a fallback rather than importing a category emptied of everything it held
            $newMedias = [];
            foreach ($item['medias'] ?? $item['photos'] ?? [] as $mediaData) {
                $media = $this->buildMedia($mediaData, $filesDir);
                $this->em->persist($media);
                $category->addMedia($media);

                // Once the media has joined its category, the slug being unique within it - the exported one is honoured when it is still free, and the media's imported file is named after whatever it ends up being (see GalleryMedia::getVichMediaPath)
                $this->mediaSlugger->assign($media, $mediaData['slug'] ?? null);

                // Held back rather than restored right away: the file it is named after is only named by the flush below (see restoreOriginals)
                if (null !== $filesDir && isset($mediaData['originalFile'])) {
                    $originals[] = [$media, $filesDir . '/' . $mediaData['originalFile']];
                }

                $newMedias[] = $media;
            }

            $coverIndex = $item['coverMediaIndex'] ?? $item['coverPhotoIndex'] ?? null;
            if (null !== $coverIndex && isset($newMedias[$coverIndex])) {
                $category->setCoverMedia($newMedias[$coverIndex]);
            }

            $this->em->persist($category);
            $isNew ? $created++ : $updated++;
        }

        $this->em->flush();

        $this->restoreOriginals($originals);

        return ['created' => $created, 'updated' => $updated];
    }

    // Existing Blocks have no natural key to match the imported ones against, so the whole collection is replaced - BlockRemovalListener removes the orphaned rows (and their Medias) on flush, same as PageImportProvider
    private function replaceBlocks(GalleryCategory $category, array $blocksData, ?string $filesDir): void
    {
        foreach ($category->getBlocks()->toArray() as $existingBlock) {
            $category->removeBlock($existingBlock);
        }

        foreach ($this->blockDataImporter->buildBlocks($blocksData, $filesDir) as $block) {
            $category->addBlock($block);
        }
    }

    // Puts the archived originals back under private/, after the flush that had Vich store and name the files they are named after. Deliberately not left to UiBundle's VichImageResizeListener::keepOriginal(): what an import re-uploads is the already-processed file, so the listener would keep a webp copy of that instead of the untouched upload the archive actually carries
    // @param list<array{0: GalleryMedia, 1: string}> $originals
    private function restoreOriginals(array $originals): void
    {
        if ([] === $originals) {
            return;
        }

        $filesystem = new Filesystem();
        foreach ($originals as [$media, $archivedPath]) {
            $filename = $media->getFilename();
            if (null === $filename || !is_file($archivedPath)) {
                continue;
            }

            // Same naming as the listener's own: the stored file's name, suffixed and carrying the original's extension rather than the forced webp one
            $originalFilename = preg_replace('/\.[^.\/]+$/', '-original.' . pathinfo($archivedPath, \PATHINFO_EXTENSION), $filename);
            $filesystem->copy($archivedPath, $this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/' . $originalFilename, true);
            $media->setOriginalFilename($originalFilename);

            // Vich has stored the file by now and left a plain File on the property (see its FileInjector), which is exactly what GalleryMediaDerivativeCleanupListener::preUpdate() reads as "a new file is being uploaded" - left in place, it would take the flush below for a file replacement and erase the very derivatives, and the original, that were just written
            $media->setFile(null);
        }

        $this->em->flush();
    }

    // Same ReplacingFile technique as PageImportProvider/FontImportProvider - see PageCrudController::cloneMedia() for why a plain File won't do
    private function buildMedia(array $mediaData, ?string $filesDir): GalleryMedia
    {
        // "alt" is what an archive exported before the title/slug rework carries, read as a fallback rather than importing medias with no name at all
        $media = (new GalleryMedia())
            ->setTitle($mediaData['title'] ?? $mediaData['alt'] ?? null)
            ->setCredits($mediaData['credits'] ?? null)
            ->setRightsReserved($mediaData['rightsReserved'] ?? false)
            ->setMediaType($mediaData['mediaType'] ?? null)
            ->setExternalId($mediaData['externalId'] ?? null)
            ->setPosition($mediaData['position'] ?? 0);

        if (null !== $filesDir && isset($mediaData['file'])) {
            $media->setFile(new ReplacingFile($filesDir . '/' . $mediaData['file'], true, true, true));
        }

        return $media;
    }
}
