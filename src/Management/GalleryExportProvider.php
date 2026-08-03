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
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Serializes GalleryCategories (with their gallery and photos, real files bundled in the archive) into the shape ContentExporter/GalleryImportProvider expect - shared by GalleryCategoryCrudController::exportSelection() (a checked subset) and exportAll() below (every GalleryCategory, for the "export sync all" dashboard shortcut, see ConfigBundle's SyncAllExporter)
class GalleryExportProvider implements ExportProviderInterface
{
    public function __construct(
        private readonly GalleryCategoryRepository $galleryCategoryRepository,
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

    // Builds coverPhotoIndex against $exportedPhotos (not the raw photo collection): a photo whose file can't be read is dropped from the export entirely, and the index must point at the array GalleryImportProvider will actually see, not the pre-filter position
    private function exportCategoryData(GalleryCategory $category, array &$files): array
    {
        $gallery = $category->getGallery();
        $coverPhoto = $category->getCoverPhoto();

        $exportedPhotos = [];
        $coverPhotoIndex = null;
        foreach ($category->getPhotos() as $photo) {
            $data = $this->exportPhotoData($photo, $files);
            if (null === $data) {
                continue;
            }
            if ($photo === $coverPhoto) {
                $coverPhotoIndex = \count($exportedPhotos);
            }
            $exportedPhotos[] = $data;
        }

        return [
            'gallerySlug' => $gallery?->getSlug(),
            'galleryTitle' => $gallery?->getTitle(),
            'galleryPosition' => $gallery?->getPosition(),
            'galleryDefault' => $gallery?->isDefault() ?? false,
            'slug' => $category->getSlug(),
            'title' => $category->getTitle(),
            'position' => $category->getPosition(),
            'uncategorized' => $category->isUncategorized(),
            'coverPhotoIndex' => $coverPhotoIndex,
            'photos' => $exportedPhotos,
        ];
    }

    // Registers the photo's physical file for the zip archive (&$files: archive-relative path => disk path), returning the metadata entry with a 'file' reference instead of embedding its bytes - same convention as SiteBundle's PageExportProvider. Returns null (filtered out by the caller) when the file can't be read, rather than exporting a broken reference
    private function exportPhotoData(GalleryPhoto $photo, array &$files): ?array
    {
        $filename = $photo->getFilename();
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
            'alt' => $photo->getAlt(),
            'credits' => $photo->getCredits(),
            'rightsReserved' => $photo->isRightsReserved(),
            'mediaType' => $photo->getMediaType(),
            'externalId' => $photo->getExternalId(),
            'position' => $photo->getPosition(),
            'originalFilename' => basename($filename),
            'file' => $archivePath,
        ];
    }
}
