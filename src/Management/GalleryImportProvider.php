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
use c975L\UiBundle\Repository\RatingRepository;
use c975L\UiBundle\Video\VideoPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;

// Imports a "gallery_category" content export (see GalleryCategoryCrudController::exportSelection/ContentExporter) - the export unit is the GalleryCategory with its Medias, which is the granularity the admin actually checks boxes for. Matches the category by slug; Medias have no natural key of their own, so the category's whole media collection is replaced, same principle as PageImportProvider replacing a Page's Blocks
class GalleryImportProvider implements ImportProviderInterface
{
    public const KIND = 'gallery_category';

    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryMediaSlugger $mediaSlugger,
        private readonly BlockDataImporter $blockDataImporter,
        private readonly RatingRepository $ratingRepository,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
        $this->filesystem = new Filesystem();
    }

    public function supportsImport(string $kind): bool
    {
        return self::KIND === $kind;
    }

    public function import(array $items, ?string $filesDir = null): array
    {
        $created = 0;
        $updated = 0;
        $archived = [];

        // The ids of the medias this import replaces, read while they still have one: their likes hang off "gallery_media" + id rather than off a relation (see c975L\UiBundle\Entity\Rating), so the orphanRemoval below takes the rows and leaves the likes
        $droppedMediaIds = [];

        // Read once, then carried through the loop: import() only flushes at the end, so asking the database inside it would have every item of the same archive see it still empty and keep the flag (see below)
        $automatic = $this->categoryRepository->findOneBy(['automatic' => true]);

        foreach ($items as $item) {
            $category = $this->categoryRepository->findOneBySlug($item['slug']);
            $isNew = null === $category;
            $category ??= new GalleryCategory();

            // Only taken when the site holds no gallery of the last additions at all, or when this very category is it: the site writes its own (see GalleryCategoryRepository::findOrCreateAutomatic), and a second one would show the same medias under a second url
            // The first item marked wins it, the ones after are imported as normal categories
            $takesAutomatic = ($item['automatic'] ?? false) && \in_array($automatic, [null, $category], true);
            if ($takesAutomatic) {
                $automatic = $category;
            }

            $category
                ->setSlug($item['slug'])
                ->setTitle($item['title'])
                // "description" is what an archive exported before the rename carries: read as a fallback rather than importing a category stripped of its lead-in. Both optional, an archive predating the field altogether staying importable - and read as "no lead-in", which is what such an archive describes
                ->setSummarySocialNetwork($item['summarySocialNetwork'] ?? $item['description'] ?? null)
                ->setPosition($item['position'] ?? 0)
                ->setUncategorized($item['uncategorized'] ?? false)
                // What the site added to this gallery of its own, put back whole - an archive predating it, or one from a site declaring no fields, importing a gallery carrying none
                ->setData($item['data'] ?? null)
                ->setAutomatic($takesAutomatic)
                // Optional like the rest, an archive predating the trash importing as a category that is not in it - which is what such an archive describes
                ->setIsDeleted($item['isDeleted'] ?? false)
                ->setCoverMedia(null);

            // The key is optional, an archive exported before the category gained a lead-in staying importable - what it describes then is a category without one, same reading as PageImportProvider
            $this->replaceBlocks($category, $item['blocks'] ?? [], $filesDir);

            // Existing Medias have no natural key to match the imported ones against, so the whole collection is replaced - orphanRemoval on GalleryCategory::$medias deletes the orphaned rows on flush
            foreach ($category->getMedias()->toArray() as $existingMedia) {
                if (null !== $existingMedia->getId()) {
                    $droppedMediaIds[] = $existingMedia->getId();
                }
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

                // Held back rather than laid down right away: every archived file is named after the stored one, which is only named by the flush below (see restoreArchivedFiles)
                if (null !== $filesDir) {
                    $archived[] = [$media, $mediaData];
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

        // Only once the replaced medias have actually gone, and in one query for the whole import: a flush that fails leaves them in place, likes and all
        $this->ratingRepository->deleteForOwners('gallery_media', $droppedMediaIds);

        if (null !== $filesDir) {
            $this->restoreArchivedFiles($archived, $filesDir);
        }

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

    // Puts every archived file back exactly as it was exported, each under the name the stored one carries - which is why it can only run after the first flush, that name being the media's own when the archive carried it, and Vich's when it didn't
    // For a media that kept its name nothing has written anything yet, and these copies are the whole storage. For one Vich named, what the upload pipeline recomputed is overwritten rather than kept: it derived the thumbnail and the high resolution from the re-uploaded stored file, so the largest of the three came back at that file's own width, and it re-encoded the webp it was handed once more (see UiBundle's VichImageResizeListener)
    // The kept original is deliberately not left to that listener's own keepOriginal() either: what an import re-uploads is the already-processed file, so it would keep a webp copy of that instead of the untouched upload the archive actually carries
    // @param list<array{0: GalleryMedia, 1: array}> $archived
    private function restoreArchivedFiles(array $archived, string $filesDir): void
    {
        if ([] === $archived) {
            return;
        }

        foreach ($archived as [$media, $mediaData]) {
            $filename = $media->getFilename();
            if (null === $filename) {
                continue;
            }

            $storedPath = $this->restoreFile($filesDir, $mediaData['file'] ?? null, $filename, 'public');
            if (null !== $storedPath) {
                // The two columns describe the file actually served: nothing has written them when the media kept its exported name, Vich never having seen a file, and the pipeline had set them from its own re-encoding otherwise
                $media
                    ->setSize(filesize($storedPath) ?: null)
                    ->setMimeType(mime_content_type($storedPath) ?: null);
            }

            $this->restoreFile($filesDir, $mediaData['thumbFile'] ?? null, $media->getThumbnailFilename(), 'public');
            $this->restoreFile($filesDir, $mediaData['highresFile'] ?? null, $media->getHighresFilename(), 'public');
            $this->restoreOriginal($filesDir, $mediaData['originalFile'] ?? null, $media, $filename);

            // Only ever restored for a media that kept its exported name: the other way round Vich has stored the video itself, under a name of its own, and removed the archived copy on the way (see buildMedia)
            $videoPath = $this->restoreFile($filesDir, $mediaData['videoFile'] ?? null, $media->getVideoFilename(), 'public');
            if (null !== $videoPath) {
                $media
                    ->setVideoSize(filesize($videoPath) ?: null)
                    ->setVideoMimeType(mime_content_type($videoPath) ?: null);
            }

            // A media Vich stored carries a plain File on the property by now (see its FileInjector), which is exactly what GalleryMediaDerivativeCleanupListener::preUpdate() reads as "a new file is being uploaded" - left in place, it would take the flush below for a file replacement and erase the very files that were just restored
            $media->setFile(null);
        }

        $this->em->flush();
    }

    // Copies one archived file over what the pipeline wrote in its place, and answers where it landed. Nothing is done for a key the archive doesn't carry - an archive exported before the derivatives travelled keeps the recomputed ones, which is the best it can describe
    // Only when the two carry the same extension though: an archive from before the stored files were converted holds a jpeg, where the name it would be restored under says webp - that would serve a file as something it is not, where the recomputed one is merely softer
    private function restoreFile(string $filesDir, ?string $archiveEntry, ?string $targetFilename, string $root): ?string
    {
        if (null === $archiveEntry || null === $targetFilename) {
            return null;
        }

        $archivedPath = $filesDir . '/' . $archiveEntry;
        if (!is_file($archivedPath) || pathinfo($archivedPath, \PATHINFO_EXTENSION) !== pathinfo($targetFilename, \PATHINFO_EXTENSION)) {
            return null;
        }

        $target = $this->projectDir . '/' . $root . '/' . $targetFilename;
        $this->filesystem->copy($archivedPath, $target, true);

        return $target;
    }

    // Named apart from the three above: the original is the one file whose name is not the stored one's, it carries the upload's own extension where the others carry the forced webp one - hence no extension to match, and a name to build rather than to derive
    private function restoreOriginal(string $filesDir, ?string $archiveEntry, GalleryMedia $media, string $filename): void
    {
        if (null === $archiveEntry || !is_file($filesDir . '/' . $archiveEntry)) {
            return;
        }

        // Same naming as UiBundle's listener does its own: the stored file's name, suffixed and carrying the original's extension rather than the forced webp one
        $originalFilename = preg_replace('/\.[^.\/]+$/', '-original.' . pathinfo($archiveEntry, \PATHINFO_EXTENSION), $filename);
        $this->filesystem->copy($filesDir . '/' . $archiveEntry, $this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/' . $originalFilename, true);
        $media->setOriginalFilename($originalFilename);
    }

    private function buildMedia(array $mediaData, ?string $filesDir): GalleryMedia
    {
        // "alt" is what an archive exported before the title/slug rework carries, read as a fallback rather than importing medias with no name at all
        $media = new GalleryMedia()
            ->setTitle($mediaData['title'] ?? $mediaData['alt'] ?? null)
            ->setDescription($mediaData['description'] ?? null)
            ->setData($mediaData['data'] ?? null)
            ->setCredits($mediaData['credits'] ?? null)
            ->setRightsReserved($mediaData['rightsReserved'] ?? false)
            // The type is derived from the url rather than imported alongside it (see GalleryMedia::setExternalUrl), so an archive can never carry the two out of step
            // "externalId" is what an archive exported before the url rework carries: an id next to a platform name, rebuilt into the url that platform gives it - an archive from a platform nobody declares anymore has nothing to rebuild, and imports as the image it already was
            ->setExternalUrl($mediaData['externalUrl'] ?? $this->legacyEmbedUrl($mediaData))
            // Optional like the category's, an archive predating the trash importing as a media that is not in it
            ->setIsDeleted($mediaData['isDeleted'] ?? false)
            ->setPosition($mediaData['position'] ?? 0);

        if (null !== $filesDir && isset($mediaData['file'])) {
            $this->attachFiles($media, $mediaData, $filesDir);
        }

        // Last, so it stands whichever way the files were attached: setFile() stamps a media with the moment it was handed a file, which for an import is the moment of the import. What the media is dated by is when it was actually touched, and the sitemap reads it (see GallerySitemapProvider)
        if (isset($mediaData['updatedAt'])) {
            $media->setUpdatedAt(new \DateTimeImmutable($mediaData['updatedAt']));
        }

        return $media;
    }

    // The two ways of putting a media's files back, decided by whether the archive says what they were called
    private function attachFiles(GalleryMedia $media, array $mediaData, string $filesDir): void
    {
        // Named: the files are laid straight back under those names (see restoreArchivedFiles) and Vich never sees one at all, which is what keeps a gallery answering at the same image urls on every site it is synced to - and what spares an import the resizing of every photo it carries
        $filename = $this->archivedFilename($mediaData['filename'] ?? null);
        if (null !== $filename) {
            $media->setFilename($filename);

            // The type follows from the name, exactly as it would from the one Vich gave it (see GalleryMedia::setVideoFilename)
            if (isset($mediaData['videoFile'])) {
                $media->setVideoFilename($this->archivedFilename($mediaData['videoFilename'] ?? null));
            }

            return;
        }

        // Unnamed: Vich stores and names the files anew, on the same ReplacingFile technique as PageImportProvider/FontImportProvider - see PageCrudController::cloneMedia() for why a plain File won't do
        // The still is left on disk once stored, where the video is removed: the very same file is copied back over what the pipeline made of it (see restoreArchivedFiles), so it has to outlive the flush. Nothing is leaked by it, ContentImportController removing the whole extraction directory in a finally
        $media->setFile(new ReplacingFile($filesDir . '/' . $mediaData['file'], true, false, false));

        if (isset($mediaData['videoFile'])) {
            $media->setVideoFile(new ReplacingFile($filesDir . '/' . $mediaData['videoFile'], true, true, true));
        }
    }

    // The name an archive says a file was served under, or null for anything this bundle would refuse to write. What comes out of an archive is a path an admin uploaded, so it is only honoured under this bundle's own media directory, and only as a plain relative name: a "../" or an absolute path would have an import lay files anywhere the process can write, and a null byte would have PHP stop reading the name where C does
    // Null falls the caller back on Vich naming the file itself, which is also what an archive exported before the names travelled gets
    private function archivedFilename(?string $filename): ?string
    {
        if (null === $filename || !str_starts_with($filename, GalleryMedia::MEDIA_DIRECTORY . '/')) {
            return null;
        }

        return !str_contains($filename, "\0") && !in_array('..', explode('/', $filename), true) ? $filename : null;
    }

    // Rebuilds the url of an archive that predates it, from the platform name and the id it stored side by side - null for anything else, which imports as the image every entry already carries
    private function legacyEmbedUrl(array $mediaData): ?string
    {
        $externalId = $mediaData['externalId'] ?? null;
        if (null === $externalId || '' === $externalId) {
            return null;
        }

        return VideoPlatform::tryFrom($mediaData['mediaType'] ?? '')?->embedUrl($externalId);
    }
}
