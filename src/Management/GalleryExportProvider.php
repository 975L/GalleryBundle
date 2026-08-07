<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\ExportProviderInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\UiBundle\Management\BlockDataExporter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Serializes GalleryCategories (with their medias, real files bundled in the archive) into the shape ContentExporter/GalleryImportProvider expect - shared by GalleryCategoryCrudController::exportSelection() (a checked subset) and exportAll() below (every GalleryCategory, for the "export sync all" dashboard shortcut, see ConfigBundle's SyncAllExporter)
class GalleryExportProvider implements ExportProviderInterface
{
    public function __construct(
        private readonly GalleryCategoryRepository $galleryCategoryRepository,
        private readonly BlockDataExporter $blockDataExporter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getKind(): string
    {
        return GalleryImportProvider::KIND;
    }

    public function exportAll(): array
    {
        return $this->serialize($this->galleryCategoryRepository->findAll());
    }

    // @param iterable<GalleryCategory> $categories
    public function serialize(iterable $categories): array
    {
        $files = [];
        $items = [];
        foreach ($categories as $category) {
            $items[] = $this->exportCategoryData($category, $files);
        }

        return ['items' => $items, 'files' => $files];
    }

    // Builds coverMediaIndex against $exportedMedias (not the raw media collection): a media whose file can't be read is dropped from the export entirely, and the index must point at the array GalleryImportProvider will actually see, not the pre-filter position
    private function exportCategoryData(GalleryCategory $category, array &$files): array
    {
        $coverMedia = $category->getCoverMedia();

        $exportedMedias = [];
        $coverMediaIndex = null;
        foreach ($category->getMedias() as $media) {
            $data = $this->exportMediaData($media, $files);
            if (null === $data) {
                continue;
            }
            if ($media === $coverMedia) {
                $coverMediaIndex = \count($exportedMedias);
            }
            $exportedMedias[] = $data;
        }

        return [
            'slug' => $category->getSlug(),
            'title' => $category->getTitle(),
            'position' => $category->getPosition(),
            'uncategorized' => $category->isUncategorized(),
            'coverMediaIndex' => $coverMediaIndex,
            // The category's editorial lead-in, carried the same way PageExportProvider carries a Page's, its own medias joining the archive
            'blocks' => $this->blockDataExporter->exportBlocks($category->getBlocks(), $files),
            'medias' => $exportedMedias,
        ];
    }

    // Registers the media's physical file for the zip archive (&$files: archive-relative path => disk path), returning the metadata entry with a 'file' reference instead of embedding its bytes - same convention as SiteBundle's PageExportProvider. Returns null (filtered out by the caller) when the file can't be read, rather than exporting a broken reference
    private function exportMediaData(GalleryMedia $media, array &$files): ?array
    {
        $filename = $media->getFilename();
        if (null === $filename) {
            return null;
        }

        $path = $this->projectDir . '/public/' . $filename;
        if (!is_file($path)) {
            return null;
        }

        $archivePath = 'files/' . bin2hex(random_bytes(8)) . '_' . basename($filename);
        $files[$archivePath] = $path;

        return [
            'title' => $media->getTitle(),
            // Exported so a round-trip leaves the public urls where they were, the import honouring it when it is still free (see GalleryMediaSlugger)
            'slug' => $media->getSlug(),
            'credits' => $media->getCredits(),
            'rightsReserved' => $media->isRightsReserved(),
            // Deliberately not exported: the watermark flags (see GalleryMedia::wantsWatermark). What is archived here is the stored file, which already carries the signature in its pixels, and the import re-uploads it through the very pipeline that stamps - carrying the flag over would lay a second logo on top of the first. An imported media therefore comes back signed but flagged as unsigned, the archive holding no unsigned copy the flag could be honoured against
            'mediaType' => $media->getMediaType(),
            'externalId' => $media->getExternalId(),
            'position' => $media->getPosition(),
            'file' => $archivePath,
        ] + $this->exportOriginal($media, $files);
    }

    // The untouched upload kept under private/, archived beside the stored file rather than in its place: it is what lets a media be re-processed later without a re-upload, and it would be lost on a round-trip otherwise. Nothing is added for a media that never kept one, or whose original has since disappeared from disk - what the import reads back is the 'originalFile' key's presence (see GalleryImportProvider::restoreOriginals)
    private function exportOriginal(GalleryMedia $media, array &$files): array
    {
        $originalFilename = $media->getOriginalFilename();
        if (null === $originalFilename) {
            return [];
        }

        $path = $this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/' . $originalFilename;
        if (!is_file($path)) {
            return [];
        }

        $archivePath = 'files/' . bin2hex(random_bytes(8)) . '_' . basename($originalFilename);
        $files[$archivePath] = $path;

        return ['originalFile' => $archivePath];
    }
}
