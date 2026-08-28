<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Moves a media from one gallery to another, everything it carries following it: its files into the directory the arrival gallery is stored under, its page's old url into a redirect, and the ranks of the two galleries closing behind it and opening in front of it
// The single place both ways of moving a media go through - the selection of the category screen (see GalleryCategoryCrudController::moveMedias) and the category field of the media's own edit form (see GalleryMediaCrudController::updateEntity), which used to leave the files behind in the gallery the media had left
// The files are only touched once the flush has gone through, this being a postFlush listener of its own for exactly that: same deferral as GalleryMediaDerivativeCleanupListener's, a flush that fails leaving every file where the rows still point at it
#[AsDoctrineListener(event: Events::postFlush)]
class GalleryMediaMover
{
    /** @var array<string, string> absolute path of a file waiting to be moved => where it goes */
    private array $pendingRenames = [];

    public function __construct(
        private readonly GalleryMediaSlugger $mediaSlugger,
        private readonly GalleryUrlRedirector $urlRedirector,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Moves a whole selection into another gallery - a media already there is simply skipped, as is one the selection holds twice.
     * The title root is optional: left empty the medias keep the titles they had, filled they are renumbered from where the arrival gallery leaves off, exactly as a batch upload numbers its own (see GalleryMediaFactory::title).
     *
     * @param iterable<GalleryMedia> $medias
     *
     * @return int how many actually moved, which is what the flash counts
     */
    public function move(EntityManagerInterface $entityManager, iterable $medias, GalleryCategory $target, ?string $titleRoot = null): int
    {
        $titleRoot = trim((string) $titleRoot);

        // Taken in the order the grid shows them rather than the order the collection loads in, that relation carrying no OrderBy of its own: the ranks below, and the numbered titles above all, must follow what the admin arranged and is looking at (see GalleryCategoryCrudController::saveMediasLayout)
        $medias = \is_array($medias) ? array_values($medias) : iterator_to_array($medias, false);
        usort($medias, static fn (GalleryMedia $first, GalleryMedia $second): int => $first->getPosition() <=> $second->getPosition());

        $position = $target->getNextMediaPosition();
        $sources = [];
        $moved = 0;

        foreach ($medias as $media) {
            $source = $media->getCategory();
            if ($source === $target) {
                continue;
            }

            $slug = $media->getSlug();
            $this->releaseCover($media, $source);

            // Added to the arrival gallery before the slug is asked for: a slug is only unique within its category, and the slugger reads the collection the media now belongs to (see GalleryMediaSlugger::takenSlugs)
            // The gallery it leaves goes on holding it in its own collection, deliberately: that relation is declared orphanRemoval, so taking the media out of it would have the flush delete the very row being moved
            $target->addMedia($media);

            // The slug it had is honoured when the arrival gallery leaves it free, and suffixed when it does not - same answer an import gets for a name it carries
            $this->mediaSlugger->assign($media, $slug);
            $this->recordUrlChange($entityManager, $media, $source, $slug);

            $media->setPosition($position);
            if ('' !== $titleRoot) {
                $media->setTitle($titleRoot . ' ' . ($position + 1));
            }
            ++$position;

            $this->queueFiles($media, $target);

            if ($source instanceof GalleryCategory) {
                $sources[spl_object_id($source)] = $source;
            }
            ++$moved;
        }

        foreach ($sources as $source) {
            $this->renumber($source);
        }

        return $moved;
    }

    // A media its own edit form has already moved, the category field being what changed it: the slug and the redirect are settled there (see GalleryMediaCrudController::updateEntity), so only the files, the cover and the two galleries' ranks are left to follow
    // The rank is left alone when the admin typed one on that very form: they arranged the media themselves, and appending it would silently undo what they asked for
    public function follow(GalleryMedia $media, ?GalleryCategory $source, bool $keepPosition = false): void
    {
        $target = $media->getCategory();
        if (!$target instanceof GalleryCategory || $target === $source) {
            return;
        }

        $this->releaseCover($media, $source);

        if (!$keepPosition) {
            $media->setPosition($target->getNextMediaPosition());
        }

        $this->queueFiles($media, $target);

        if ($source instanceof GalleryCategory) {
            $this->renumber($source);
        }
    }

    // The gallery would go on showing a cover it no longer holds - cleared exactly as a media put in the trash clears it (see GalleryCategoryCrudController::deleteMedias)
    private function releaseCover(GalleryMedia $media, ?GalleryCategory $source): void
    {
        if ($source?->getCoverMedia() === $media) {
            $source->setCoverMedia(null);
        }
    }

    // The media's page moves with it, its gallery's slug being the segment above its own - the old url is left redirecting to the new one, as a retitled media's is
    private function recordUrlChange(EntityManagerInterface $entityManager, GalleryMedia $media, ?GalleryCategory $source, ?string $slug): void
    {
        $moved = $this->mediaUrl($media->getCategory()?->getSlug(), $media->getSlug());

        // A media arriving under a url an earlier permanent deletion left answering 410 would be shadowed by it, ConfigBundle's RedirectSubscriber running before the router (see GalleryCategoryCrudController::restoreMedias, which frees it the same way)
        $this->urlRedirector->release($entityManager, $moved);

        // A media stored before slugs existed has no old url to preserve - it simply starts being reachable under its new one
        if (!$source instanceof GalleryCategory || null === $slug) {
            return;
        }

        $this->urlRedirector->record($entityManager, $this->mediaUrl($source->getSlug(), $slug), $moved);
    }

    // Generated rather than concatenated, the first segment being the configured route prefix (see GalleryRoutePrefix)
    private function mediaUrl(?string $category, ?string $slug): string
    {
        return $this->urlGenerator->generate('gallery_media', ['category' => $category, 'slug' => $slug]);
    }

    // The gap a moved media leaves is closed behind it, the ranks of the gallery it left being renumbered from zero in the order they were in - "moved out" is permanent, where a media put in the trash still holds the rank it had so it can come back to it (see GalleryCategoryCrudController::restoreMedias)
    // Read off what the gallery still holds rather than off its collection alone, which goes on carrying what has just left it for the orphanRemoval reason stated in move()
    private function renumber(GalleryCategory $source): void
    {
        $medias = array_filter(
            $source->getMedias()->toArray(),
            static fn (GalleryMedia $media): bool => !$media->isDeleted() && $media->getCategory() === $source
        );

        usort($medias, static fn (GalleryMedia $first, GalleryMedia $second): int => $first->getPosition() <=> $second->getPosition());

        $position = 0;
        foreach ($medias as $media) {
            $media->setPosition($position++);
        }
    }

    // The four files of a media (stored, thumbnail, high resolution, kept original) and the video it may carry, all of them following it into the gallery it now belongs to
    // Only the directory above them moves: the name itself carries the slug the media had at upload and never changes, which is the decision a rename already stands on (see GalleryMedia::getVichMediaPath)
    private function queueFiles(GalleryMedia $media, GalleryCategory $target): void
    {
        $directory = GalleryMedia::MEDIA_DIRECTORY . '/' . $target->getSlug();

        // A file replaced in the very save that moves the media is left to Vich, which stores it under the gallery the media now belongs to and has the whole old set removed behind it (see GalleryMediaDerivativeCleanupListener) - moving what is about to be deleted would have the two race each other for the same names
        if (!$media->getFile() instanceof File) {
            // Both are read off the stored file's own name (see GalleryMedia::getThumbnailFilename), so they are queued before it changes under them
            foreach ([$media->getThumbnailFilename(), $media->getHighresFilename()] as $filename) {
                $this->queue($filename, 'public/', $directory);
            }

            $media->setFilename($this->queue($media->getFilename(), 'public/', $directory));

            // Set apart from the chain above: unlike every other setter of the entity, this one answers nothing back
            $media->setOriginalFilename($this->queue($media->getOriginalFilename(), GalleryMedia::ORIGINAL_DIRECTORY . '/', $directory));
        }

        // Asked of itself, the media's own video being replaced without its image having been
        if (!$media->getVideoFile() instanceof File) {
            $media->setVideoFilename($this->queue($media->getVideoFilename(), 'public/', $directory));
        }
    }

    // Where a file lands once its media has changed gallery, null for a media carrying none - the move is queued and the new name handed back for the row
    // Only a name under this bundle's own directory is moved at all, exactly as an import only honours those (see GalleryImportProvider::archivedFilename): anything else was written by something that is not this bundle, and is left exactly where it is
    private function queue(?string $filename, string $root, string $directory): ?string
    {
        if (null === $filename || !str_starts_with($filename, GalleryMedia::MEDIA_DIRECTORY . '/')) {
            return $filename;
        }

        $moved = $directory . '/' . basename($filename);
        if ($moved !== $filename) {
            $this->pendingRenames[$this->projectDir . '/' . $root . $filename] = $this->projectDir . '/' . $root . $moved;
        }

        return $moved;
    }

    // Once the rows have actually been written, never before - a rename that fails afterwards leaves a file the health check reports (see GalleryFilesHealthCheckProvider), where a rename made before a failed flush would leave one nothing points at at all
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pendingRenames) {
            return;
        }

        $filesystem = new Filesystem();
        foreach ($this->pendingRenames as $from => $to) {
            // A media whose file is already gone contributes nothing rather than failing the whole move - the same thing the archiver and the cleanup do with one
            if (is_file($from)) {
                $filesystem->mkdir(\dirname($to), 0755);
                $filesystem->rename($from, $to, true);
            }
        }

        // A gallery emptied of its last media leaves its directory behind on both sides, empty and named after a slug no file hangs under any more - removed only once actually empty, exactly as GalleryMediaDerivativeCleanupListener does it
        foreach ($this->emptyDirectories() as $directory) {
            $filesystem->remove($directory);
        }

        $this->pendingRenames = [];
    }

    /** @return string[] */
    private function emptyDirectories(): array
    {
        $directories = array_unique(array_map(dirname(...), array_keys($this->pendingRenames)));

        return array_filter(
            $directories,
            static fn (string $directory): bool => is_dir($directory) && !new \FilesystemIterator($directory)->valid()
        );
    }
}
