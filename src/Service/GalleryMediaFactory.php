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
use c975L\GalleryBundle\Model\GalleryMediaBatch;
use Symfony\Component\HttpFoundation\File\UploadedFile;

// Turns a batch of uploaded files into the medias of a category, for the two screens that add medias: the upload screen (see GalleryMediaUploadController) and the category creation form, which fills a category as it is created (see GalleryCategoryCrudController)
class GalleryMediaFactory
{
    public function __construct(
        private readonly GalleryMediaSlugger $mediaSlugger,
    ) {
    }

    /**
     * @param iterable<mixed> $files
     *
     * @return GalleryMedia[]
     */
    public function createFromUploads(GalleryCategory $category, iterable $files, ?GalleryMediaBatch $batch = null): array
    {
        $batch ??= new GalleryMediaBatch();
        $position = $this->nextPosition($category);
        $medias = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $index = $position++;

            $media = new GalleryMedia();
            $media->setFile($file);
            $media
                ->setTitle($this->title($batch, $file, $index))
                ->setCredits('' === (string) $batch->credits ? null : $batch->credits)
                ->setRightsReserved($batch->rightsReserved)
                ->setKeepOriginal($batch->keepOriginals)
                ->setWatermark($batch->watermark)
                ->setWatermarkPosition($batch->watermarkPosition)
                ->setPosition($index)
            ;

            // Both ends of the association: a category created with its medias carries them into the cascade that saves them along with it (see GalleryCategory::$medias)
            $category->addMedia($media);

            // Once the media has joined its category, never before: the slug has to be unique among that category's medias, the ones already stored as much as the ones this very batch is adding - and it has to be set now, the flush that follows naming the uploaded file after it (see GalleryMedia::getVichMediaPath)
            $this->mediaSlugger->assign($media, $this->slugBase($batch, $file, $index));

            $medias[] = $media;
        }

        return $medias;
    }

    // A whole batch shares its credits and its title root, never a title of its own: the root is numbered per media, and a batch uploaded without one falls back to each file's name, the only thing telling one media from another at that point
    private function title(GalleryMediaBatch $batch, UploadedFile $file, int $index): string
    {
        $root = trim((string) $batch->titleRoot);

        // Numbered from where the category leaves off rather than from 1, so a second batch continues the series instead of restarting it against the titles the first one left
        return '' !== $root ? $root . ' ' . ($index + 1) : $this->titleFromFilename($file->getClientOriginalName());
    }

    // What the slug is built from, when it must not be the title: a root numbered "1", "2", "3" would pose urls that read as an order, and the order is the one thing a gallery changes
    // Null hands the slugger back to the title, which without a root is built from the filename and already makes a readable url on its own - the hash only ever discriminates medias that would otherwise share one string
    private function slugBase(GalleryMediaBatch $batch, UploadedFile $file, int $index): ?string
    {
        $root = trim((string) $batch->titleRoot);

        return '' !== $root ? $root . '-' . $this->captureHash($file, $index) : null;
    }

    // Six hex characters off the capture date: intrinsic to the photo, so the url survives both a reordering and a retitling, where anything positional would drift away from what it posed. The date itself stays out of the url, when it was shot being nobody's business
    // Hashing is also what makes the fallback seed safe: the name the browser sent is client input, and nothing but hex comes out of here
    // Two shots taken in the same second hash alike - GalleryMediaSlugger then suffixes the second one, exactly as it does for two identical filenames
    private function captureHash(UploadedFile $file, int $index): string
    {
        $seed = $this->captureDate($file) ?? $file->getClientOriginalName() . '-' . $index;

        return substr(sha1($seed), 0, 6);
    }

    // Read off the temporary upload, the only moment it is there to read: the stored file is a webp conversion (see UiBundle's VichImageResizeListener) and carries no EXIF at all
    private function captureDate(UploadedFile $file): ?string
    {
        // ext-exif is a suggestion, not a requirement - without it every media simply falls back to the seed above
        if (!\function_exists('exif_read_data')) {
            return null;
        }

        // Suppressed rather than guarded on the mime type: exif_read_data() warns on anything carrying no EXIF segment, which a png or a webp never does, and a truncated segment is just as much a "no date" as an absent one
        $exif = @exif_read_data($file->getPathname());
        if (!\is_array($exif)) {
            return null;
        }

        $date = $exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? null;

        return \is_string($date) && '' !== $date ? $date : null;
    }

    // Falls back to the original filename when no title root was given - a camera names them IMG_1234, but that at least tells two medias apart until they are retouched
    private function titleFromFilename(string $filename): string
    {
        return ucwords(str_replace(['_', '-'], ' ', pathinfo($filename, \PATHINFO_FILENAME)));
    }

    // Appended after whatever the category already holds, a batch never reordering the medias that were there before it
    private function nextPosition(GalleryCategory $category): int
    {
        $positions = $category->getMedias()
            ->map(static fn (GalleryMedia $media): int => $media->getPosition())
            ->toArray()
        ;

        return [] === $positions ? 0 : max($positions) + 1;
    }
}
