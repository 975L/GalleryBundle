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
        #[Autowire(param: 'kernel.project_dir')]
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
            'summarySocialNetwork' => $category->getSummarySocialNetwork(),
            'position' => $category->getPosition(),
            // Same as a media's below: what the site adds to a gallery of its own travels with it
            'data' => $category->getData(),
            'uncategorized' => $category->isUncategorized(),
            // The gallery of the last additions carries its flag too, so a site restoring its content gets it back rather than an empty category nobody remembers what it was for
            'automaticKind' => $category->getAutomaticKind(),
            // The archive is a faithful copy, here as everywhere else in this bundle: a category exported out of the trash comes back to the trash, not onto the site, and a sync mirrors the source rather than publishing what it had taken down
            'isDeleted' => $category->isDeleted(),
            // Same for a masked gallery, exactly as a media's own flag travels below: a gallery an admin took off the site comes back off it
            'hidden' => $category->isHidden(),
            'coverMediaIndex' => $coverMediaIndex,
            // The category's editorial lead-in, carried the same way PageExportProvider carries a Page's, its own medias joining the archive
            'blocks' => $this->blockDataExporter->exportBlocks($category->getBlocks(), $files),
            'medias' => $exportedMedias,
        ];
    }

    // Registers the media's physical files for the zip archive (&$files: archive-relative path => disk path), returning the metadata entry with references instead of embedding their bytes - same convention as SiteBundle's PageExportProvider. Returns null (filtered out by the caller) when the stored file can't be read, rather than exporting a broken reference
    private function exportMediaData(GalleryMedia $media, array &$files): ?array
    {
        $storedFile = $this->registerFile($media->getFilename(), 'public', $files);
        if (null === $storedFile) {
            return null;
        }

        // Resolved before the entry is built: the name only travels alongside the bytes it names, a video whose file has left the disk being exported as a media that no longer holds one
        $videoFile = $this->registerFile($media->getVideoFilename(), 'public', $files);

        return [
            'title' => $media->getTitle(),
            // Exported so a round-trip leaves the public urls where they were, the import honouring it when it is still free (see GalleryMediaSlugger)
            'slug' => $media->getSlug(),
            'description' => $media->getDescription(),
            // What the site adds to a media of its own (see GalleryCustomizationProviderInterface), carried whole - the archive holds the payload without having to know its shape
            'data' => $media->getData(),
            'credits' => $media->getCredits(),
            'rightsReserved' => $media->isRightsReserved(),
            // Same as the category's: a media in the trash travels as one, files included, so nothing is lost and nothing is republished behind the admin's back
            'isDeleted' => $media->isDeleted(),
            // For the same reason, a media kept off the public pages comes back kept off them - and one offered as a print comes back offered
            // Nothing here about the edition: its size means nothing without the rows that were claimed from it (see GalleryPrintCopy), and an archive restoring the number alone would announce an edition nobody could ever buy from
            'hidden' => $media->isHidden(),
            'printable' => $media->isPrintable(),
            // Nothing here about the watermark, and nothing to say: it is not stored on the media (see GalleryMedia::wantsWatermark), only answered when a file is. What is archived is the stored file, which already carries the signature in its pixels, and an import that asked for one again would lay a second logo on top of the first
            // Exported for what reads an archive rather than for what imports one: the type is derived from the url on the way back in (see GalleryImportProvider), never read from here
            'mediaType' => $media->getMediaType(),
            'externalUrl' => $media->getExternalUrl(),
            'position' => $media->getPosition(),
            // The name the file is served under, exported beside its bytes so the import can put it back at the very same url instead of letting Vich name it again - a stored name carries a uniqid, so a synced gallery used to answer at different image urls on every site it was carried to (see GalleryImportProvider::archivedFilename)
            'filename' => $media->getFilename(),
            // Vich no longer stamping the imported media with a date of its own, this is the only thing left to date it by - and what the sitemap reads (see GallerySitemapProvider)
            'updatedAt' => $media->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'file' => $storedFile,
        ] + array_filter([
            // The thumbnail and the high resolution generated beside the stored file (see UiBundle's VichImageResizeListener::processMultiSizeDerivatives), archived rather than left to be recomputed on the way back in: an import re-uploads the stored file, and a high resolution derived from that one comes back at its width instead of its own - half the definition the lightbox was exported with, and one webp re-encoding more at every round-trip
            // Recomputing them from the kept original is no answer either, the signature being stored nowhere (see below): it lives in these files' own pixels, and an original is copied aside before it is ever laid
            'thumbFile' => $this->registerFile($media->getThumbnailFilename(), 'public', $files),
            'highresFile' => $this->registerFile($media->getHighresFilename(), 'public', $files),
            // The untouched upload kept under private/, archived beside the stored file rather than in its place: it is what lets a media be re-processed later without a re-upload, and it would be lost on a round-trip otherwise
            'originalFile' => $this->registerFile($media->getOriginalFilename(), GalleryMedia::ORIGINAL_DIRECTORY, $files),
            // The site's own copy of the video, archived beside the still it is played under - it is the one file of the set nothing could get back from elsewhere, a media framed from a platform still carrying the url that finds it again
            'videoFile' => $videoFile,
            'videoFilename' => null !== $videoFile ? $media->getVideoFilename() : null,
        ]);
    }

    // Registers one physical file for the zip archive and returns the reference the metadata carries - null for a name the media doesn't have, and for a file that has since left the disk, the import reading back the key's presence (see GalleryImportProvider::restoreArchivedFiles) so an archive never points at bytes it doesn't hold
    // The random prefix keeps the same-named files of two medias apart, an archive laying every file of every category in one flat directory
    private function registerFile(?string $filename, string $root, array &$files): ?string
    {
        if (null === $filename) {
            return null;
        }

        $path = $this->projectDir . '/' . $root . '/' . $filename;
        if (!is_file($path)) {
            return null;
        }

        $archivePath = 'files/' . bin2hex(random_bytes(8)) . '_' . basename($filename);
        $files[$archivePath] = $path;

        return $archivePath;
    }
}
