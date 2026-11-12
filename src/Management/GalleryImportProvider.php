<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\ArchivePath;
use c975L\ConfigBundle\Management\ImportProviderInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryPrintCopyRepository;
use c975L\GalleryBundle\Service\GalleryMediaLikeCounter;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use c975L\UiBundle\Management\BlockDataImporter;
use c975L\UiBundle\Repository\RatingRepository;
use c975L\UiBundle\Video\VideoPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;

// Imports a "gallery_category" content export (see GalleryCategoryCrudController::exportSelection/ContentExporter) - the export unit is the GalleryCategory with its Medias, which is the granularity the admin actually checks boxes for. Matches the category by slug; Medias have no natural key of their own, so the category's whole media collection is replaced, same principle as PageImportProvider replacing a Page's Blocks
class GalleryImportProvider implements ImportProviderInterface
{
    public const KIND = 'gallery_category';

    // The only extensions a stored or archived media file is written under, a manifest naming anything else (.php, .html, .svg) being ignored
    public const array ALLOWED_EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png', 'gif', 'mp4', 'webm', 'ogv'];

    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GalleryCategoryRepository $categoryRepository,
        private readonly GalleryMediaSlugger $mediaSlugger,
        private readonly BlockDataImporter $blockDataImporter,
        private readonly RatingRepository $ratingRepository,
        private readonly GalleryPrintCopyRepository $copyRepository,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
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

        // Read once per kind, then carried through the loop: import() only flushes at the end, so asking the database inside it would have every item of the same archive see it still empty and keep the kind (see below)
        $automatic = [];
        foreach (GalleryCategory::automaticKinds() as $kind) {
            $automatic[$kind] = $this->categoryRepository->findOneBy(['automaticKind' => $kind]);
        }

        foreach ($items as $item) {
            $category = $this->categoryRepository->findOneBySlug($item['slug']);
            $isNew = null === $category;

            // Refused whole, before anything of it is touched: replacing its medias would turn an edition already announced or sold into an open one, its register pointing at nothing
            if (!$isNew && $this->holdsAnEdition($category)) {
                $this->warn('label.gallery_import_edition_refused', ['%slug%' => htmlspecialchars((string) $item['slug'])]);

                continue;
            }
            $category ??= new GalleryCategory();

            $this->fillCategory($category, $item, $automatic);

            // The key is optional, an archive exported before the category gained a lead-in staying importable - what it describes then is a category without one, same reading as PageImportProvider
            $this->replaceBlocks($category, $item['blocks'] ?? [], $filesDir);

            $droppedMediaIds = [...$droppedMediaIds, ...$this->dropMedias($category)];
            $archived = [...$archived, ...$this->addMedias($category, $item, $filesDir)];

            $this->em->persist($category);
            $isNew ? $created++ : $updated++;
        }

        $this->em->flush();

        // Only once the replaced medias have actually gone, and in one query for the whole import: a flush that fails leaves them in place, likes and all
        $this->ratingRepository->deleteForOwners(GalleryMediaLikeCounter::OWNER_TYPE, $droppedMediaIds);

        if (null !== $filesDir) {
            $this->restoreArchivedFiles($archived, $filesDir);
        }

        return ['created' => $created, 'updated' => $updated];
    }

    // Writes the category's own fields, the fallbacks reading the archives that predate each rename
    /**
     * @param array<string, GalleryCategory|null> $automatic the gallery already holding each automatic kind, updated as this import hands one out
     */
    private function fillCategory(GalleryCategory $category, array $item, array &$automatic): void
    {
        $kind = $this->claimAutomaticKind($category, $item, $automatic);

        $category
            ->setSlug($item['slug'])
            ->setTitle($item['title'])
            // "description" is what an archive exported before the rename carries: read as a fallback rather than importing a category stripped of its lead-in. Both optional, an archive predating the field altogether staying importable - and read as "no lead-in", which is what such an archive describes
            ->setSummarySocialNetwork($item['summarySocialNetwork'] ?? $item['description'] ?? null)
            // An archive exported while galleries were arranged by hand carries a "position" - read by nothing now, the index being ordered alphabetically (see GalleryCategoryRepository::findAllOrdered), and dropped rather than stored
            ->setUncategorized($item['uncategorized'] ?? false)
            // What the site added to this gallery of its own, put back whole - an archive predating it, or one from a site declaring no fields, importing a gallery carrying none
            ->setData($item['data'] ?? null)
            ->setAutomaticKind($kind)
            ->setCoverMedia(null);

        $this->fillCategoryPublication($category, $item);
    }

    // Where the gallery stands rather than what it holds, read back for the reason they are exported (see GalleryExportProvider): a round-trip must not put back on the site a gallery an admin had taken off it
    private function fillCategoryPublication(GalleryCategory $category, array $item): void
    {
        $category
            // Optional like the rest, an archive predating the trash importing as a category that is not in it - which is what such an archive describes
            ->setIsDeleted($item['isDeleted'] ?? false)
            ->setHidden($item['hidden'] ?? false)
            // An archive written before galleries carried a licence restores them all rights reserved, which is what they published until then
            ->setLicense($item['license'] ?? null);
    }

    // The automatic kind this category walks away with, or null for an ordinary gallery
    // Only taken when the site holds no gallery of that kind at all, or when this very category is it: the site writes its own (see GalleryCategoryRepository::findOrCreateAutomatic), and a second one would show the same medias under a second url
    // The first item marked wins it, the ones after are imported as normal categories
    /**
     * @param array<string, GalleryCategory|null> $automatic the gallery already holding each automatic kind, updated as this import hands one out
     */
    private function claimAutomaticKind(GalleryCategory $category, array $item, array &$automatic): ?string
    {
        // "automatic" is what an archive exported before the kinds carries, and it named the gallery of the last additions - read as a fallback rather than importing that gallery as an ordinary one
        $kind = $item['automaticKind'] ?? (($item['automatic'] ?? false) ? GalleryCategory::AUTOMATIC_LATEST : null);
        if (!\is_string($kind) || !\in_array($automatic[$kind] ?? null, [null, $category], true)) {
            return null;
        }

        $automatic[$kind] = $category;

        return $kind;
    }

    // Whether a category holds a limited edition or a print already sold, which an import must never replace
    private function holdsAnEdition(GalleryCategory $category): bool
    {
        $ids = [];
        foreach ($category->getMedias() as $media) {
            if (null !== $media->getEditionSize()) {
                return true;
            }
            $ids[] = (int) $media->getId();
        }

        return $this->copyRepository->hasSoldCopyAmong($ids);
    }

    // Tells the admin what an import left aside, through the flash the import screen shows - nothing to tell where no session is open (a command)
    private function warn(string $key, array $parameters): void
    {
        try {
            $session = $this->requestStack->getSession();
        } catch (SessionNotFoundException) {
            return;
        }

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', $this->translator->trans($key, $parameters, 'gallery'));
        }
    }

    // Empties the category of the medias it held, and answers the ids they were stored under
    // Existing Medias have no natural key to match the imported ones against, so the whole collection is replaced - orphanRemoval on GalleryCategory::$medias deletes the orphaned rows on flush
    /**
     * @return list<int>
     */
    private function dropMedias(GalleryCategory $category): array
    {
        $droppedIds = [];

        foreach ($category->getMedias()->toArray() as $existingMedia) {
            if (null !== $existingMedia->getId()) {
                $droppedIds[] = $existingMedia->getId();
            }
            $category->removeMedia($existingMedia);
        }

        return $droppedIds;
    }

    // Fills the category with the medias the archive carries, cover included, and answers those whose files are still to be laid down
    /**
     * @return list<array{0: GalleryMedia, 1: array<string, mixed>}>
     */
    private function addMedias(GalleryCategory $category, array $item, ?string $filesDir): array
    {
        $archived = [];
        $newMedias = [];

        // "photos" is what an archive exported before the rename carries: read as a fallback rather than importing a category emptied of everything it held
        foreach ($item['medias'] ?? $item['photos'] ?? [] as $mediaData) {
            $media = $this->buildMedia($mediaData, $filesDir);
            $this->em->persist($media);
            $category->addMedia($media);

            // Once the media has joined its category, the slug being unique within it - the exported one is honoured when it is still free, and the media's imported file is named after whatever it ends up being (see GalleryMedia::getVichMediaPath)
            $this->mediaSlugger->assign($media, $mediaData['slug'] ?? null);

            // Held back rather than laid down right away: every archived file is named after the stored one, which is only named by the flush in import() (see restoreArchivedFiles)
            if (null !== $filesDir) {
                $archived[] = [$media, $mediaData];
            }

            $newMedias[] = $media;
        }

        $this->assignCover($category, $item, $newMedias);

        return $archived;
    }

    // The cover is designated by rank among the medias just added, an archive naming one it no longer carries leaving the category without
    /**
     * @param list<GalleryMedia> $newMedias
     */
    private function assignCover(GalleryCategory $category, array $item, array $newMedias): void
    {
        // "coverPhotoIndex" is what an archive exported before the rename carries
        $coverIndex = $item['coverMediaIndex'] ?? $item['coverPhotoIndex'] ?? null;
        if (null !== $coverIndex && isset($newMedias[$coverIndex])) {
            $category->setCoverMedia($newMedias[$coverIndex]);
        }
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
    /**
     * @param list<array{0: GalleryMedia, 1: array<string, mixed>}> $archived
     */
    private function restoreArchivedFiles(array $archived, string $filesDir): void
    {
        if ([] === $archived) {
            return;
        }

        foreach ($archived as [$media, $mediaData]) {
            $this->restoreMediaFiles($media, $mediaData, $filesDir);
        }

        $this->em->flush();
    }

    // The five files one media travels with, each put back under the name the stored one carries
    private function restoreMediaFiles(GalleryMedia $media, array $mediaData, string $filesDir): void
    {
        $filename = $media->getFilename();
        if (null === $filename) {
            return;
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
        $this->restoreVideo($media, $mediaData, $filesDir);

        // A media Vich stored carries a plain File on the property by now (see its FileInjector), which is exactly what GalleryMediaDerivativeCleanupListener::preUpdate() reads as "a new file is being uploaded" - left in place, it would take the flush for a file replacement and erase the very files that were just restored
        $media->setFile(null);
    }

    // Laid under the name the stored video carries, the media's exported one or Vich's own - in the latter case over the very file Vich stored from it (see attachFiles)
    private function restoreVideo(GalleryMedia $media, array $mediaData, string $filesDir): void
    {
        $videoPath = $this->restoreFile($filesDir, $mediaData['videoFile'] ?? null, $media->getVideoFilename(), 'public');
        if (null === $videoPath) {
            return;
        }

        $media
            ->setVideoSize(filesize($videoPath) ?: null)
            ->setVideoMimeType(mime_content_type($videoPath) ?: null);
    }

    // Copies one archived file over what the pipeline wrote in its place, and answers where it landed. Nothing is done for a key the archive doesn't carry - an archive exported before the derivatives travelled keeps the recomputed ones, which is the best it can describe
    // Only when the two carry the same extension though: an archive from before the stored files were converted holds a jpeg, where the name it would be restored under says webp - that would serve a file as something it is not, where the recomputed one is merely softer
    private function restoreFile(string $filesDir, ?string $archiveEntry, ?string $targetFilename, string $root): ?string
    {
        // Only a file inside the extracted archive, never a path a manifest walks out of it with (see ArchivePath)
        $archivedPath = ArchivePath::resolve($filesDir, $archiveEntry);
        if (null === $archivedPath || null === $targetFilename || pathinfo($archivedPath, \PATHINFO_EXTENSION) !== pathinfo($targetFilename, \PATHINFO_EXTENSION)) {
            return null;
        }

        $target = $this->projectDir . '/' . $root . '/' . $targetFilename;
        $this->filesystem->copy($archivedPath, $target, true);

        return $target;
    }

    // Named apart from the three above: the original is the one file whose name is not the stored one's, it carries the upload's own extension where the others carry the forced webp one - hence no extension to match, and a name to build rather than to derive
    private function restoreOriginal(string $filesDir, ?string $archiveEntry, GalleryMedia $media, string $filename): void
    {
        $archivedPath = ArchivePath::resolve($filesDir, $archiveEntry);
        $extension = strtolower(pathinfo((string) $archivedPath, \PATHINFO_EXTENSION));
        if (null === $archivedPath || !\in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return;
        }

        // Same naming as UiBundle's listener does its own: the stored file's name, suffixed and carrying the original's extension rather than the forced webp one
        $originalFilename = preg_replace('/\.[^.\/]+$/', '-original.' . $extension, $filename);
        $this->filesystem->copy($archivedPath, $this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/' . $originalFilename, true);
        $media->setOriginalFilename($originalFilename);
    }

    private function buildMedia(array $mediaData, ?string $filesDir): GalleryMedia
    {
        $media = new GalleryMedia();
        $this->fillMediaContent($media, $mediaData);
        $this->fillMediaPublication($media, $mediaData);

        if (null !== $filesDir && isset($mediaData['file'])) {
            $this->attachFiles($media, $mediaData, $filesDir);
        }

        // Last, so it stands whichever way the files were attached: setFile() stamps a media with the moment it was handed a file, which for an import is the moment of the import. What the media is dated by is when it was actually touched, and the sitemap reads it (see GallerySitemapProvider)
        if (isset($mediaData['updatedAt'])) {
            $media->setUpdatedAt(new \DateTimeImmutable($mediaData['updatedAt']));
        }

        return $media;
    }

    // What the media shows, the fallbacks reading the archives that predate each rename
    private function fillMediaContent(GalleryMedia $media, array $mediaData): void
    {
        $media
            // "alt" is what an archive exported before the title/slug rework carries, read as a fallback rather than importing medias with no name at all
            ->setTitle($mediaData['title'] ?? $mediaData['alt'] ?? null)
            ->setDescription($mediaData['description'] ?? null)
            ->setData($mediaData['data'] ?? null)
            ->setExternalUrl($this->externalUrl($mediaData));

        $this->fillMediaCredits($media, $mediaData);
    }

    // Who the picture is owed to, and whether the site claims it
    private function fillMediaCredits(GalleryMedia $media, array $mediaData): void
    {
        $media
            ->setCredits($mediaData['credits'] ?? null)
            ->setRightsReserved($mediaData['rightsReserved'] ?? false);
    }

    // The type is derived from the url rather than imported alongside it (see GalleryMedia::setExternalUrl), so an archive can never carry the two out of step
    // "externalId" is what an archive exported before the url rework carries: an id next to a platform name, rebuilt into the url that platform gives it - an archive from a platform nobody declares anymore has nothing to rebuild, and imports as the image it already was
    private function externalUrl(array $mediaData): ?string
    {
        return $mediaData['externalUrl'] ?? $this->legacyEmbedUrl($mediaData);
    }

    // Where the media stands rather than what it holds, all four read back for the reason they are exported (see GalleryExportProvider::exportMediaData): a round-trip must not republish what an admin had taken off the public pages, nor put on sale what he had taken off sale
    private function fillMediaPublication(GalleryMedia $media, array $mediaData): void
    {
        $media
            // Optional like the category's, an archive predating the trash importing as a media that is not in it
            ->setIsDeleted($mediaData['isDeleted'] ?? false)
            ->setHidden($mediaData['hidden'] ?? false)
            ->setPrintable($mediaData['printable'] ?? false)
            ->setPosition($mediaData['position'] ?? 0);
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
        // Both files are left on disk once stored, never moved: the still is copied back over what the pipeline made of it (see restoreArchivedFiles), and ContentImportController removes the whole extraction directory in a finally
        $file = $this->archivedEntry($filesDir, $mediaData['file']);
        if (null === $file) {
            return;
        }

        $media->setFile(new ReplacingFile($file, true, false, false));

        $videoFile = $this->archivedEntry($filesDir, $mediaData['videoFile'] ?? null);
        if (null !== $videoFile) {
            $media->setVideoFile(new ReplacingFile($videoFile, true, false, false));
        }
    }

    // The real path of a file the manifest names inside the extracted archive, or null for one outside it (see ArchivePath) or carrying an extension a media is never stored under
    private function archivedEntry(string $filesDir, mixed $entry): ?string
    {
        $path = ArchivePath::resolve($filesDir, $entry);

        return null !== $path && \in_array(strtolower(pathinfo($path, \PATHINFO_EXTENSION)), self::ALLOWED_EXTENSIONS, true) ? $path : null;
    }

    // The name an archive says a file was served under, or null for anything this bundle would refuse to write - an extension a media is never stored under included, a ".php" or ".svg" being served from the site's own origin. What comes out of an archive is a path an admin uploaded, so it is only honoured under this bundle's own media directory, and only as a plain relative name: a "../" or an absolute path would have an import lay files anywhere the process can write, and a null byte would have PHP stop reading the name where C does
    // Null falls the caller back on Vich naming the file itself, which is also what an archive exported before the names travelled gets
    private function archivedFilename(?string $filename): ?string
    {
        if (null === $filename || !str_starts_with($filename, GalleryMedia::MEDIA_DIRECTORY . '/') || !\in_array(strtolower(pathinfo($filename, \PATHINFO_EXTENSION)), self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        return !str_contains($filename, "\0") && !\in_array('..', explode('/', $filename), true) ? $filename : null;
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
