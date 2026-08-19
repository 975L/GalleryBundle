<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Entity\GalleryMedia;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

// Packs the files of a selection of medias into one zip to download - the high resolution derivatives, or the untouched originals kept aside at upload (see UiBundle's VichOriginalKeepableInterface). Nothing else in the bundle hands a file back: the medium size is public, the highres is public but nowhere linked as a file, and the original sits outside public/ altogether, which is exactly why getting a batch of them back used to mean an ssh session
class GalleryMediaArchiver
{
    // What the two buttons of the medias toolbar ask for (see gallery_category_edit.html.twig)
    public const string VARIANT_HIGHRES = 'highres';
    public const string VARIANT_ORIGINAL = 'original';

    public const array VARIANTS = [self::VARIANT_HIGHRES, self::VARIANT_ORIGINAL];

    // Refused rather than truncated past this, and refused before a single byte is written: a browser download is not how tens of gigabytes leave a server, the archive is built in the system's temporary directory first, and an admin who selected a whole gallery of originals by mistake gets told so instead of filling the disk. What a real batch weighs is nowhere near it - a hundred originals off a phone sit around 500 MB
    public const int MAX_TOTAL_BYTES = 1073741824;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * The zip of what the selection actually holds, null when it holds no file at all - a selection of medias whose originals were never kept, typically, which is a message to show rather than an empty archive to download.
     *
     * @param iterable<GalleryMedia> $medias
     *
     * @throws \RuntimeException when the archive cannot be written, which the caller tells apart from the empty selection above
     */
    public function archive(iterable $medias, string $variant, string $name): ?BinaryFileResponse
    {
        $files = $this->files($medias, $variant);
        if ([] === $files) {
            return null;
        }

        // Written straight into the file tempnam() hands back rather than next to it under a .zip name: the browser reads the name off the content disposition below, and a second path would be a second file to remove that deleteFileAfterSend() never sees
        $archivePath = tempnam(sys_get_temp_dir(), 'gallery_medias_');
        if (false === $archivePath) {
            throw new \RuntimeException('The temporary directory refused a file for the archive.');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            @unlink($archivePath);

            throw new \RuntimeException(sprintf('The archive could not be opened for writing at "%s".', $archivePath));
        }

        foreach ($files as $entryName => $diskPath) {
            $zip->addFile($diskPath, $entryName);

            // Stored, never deflated: a webp and a jpeg are already compressed, so the pass buys a percent or two for the whole cpu cost of reading every byte again - on a batch of originals that is the difference between a download and a timeout
            $zip->setCompressionName($entryName, \ZipArchive::CM_STORE);
        }

        // Refused rather than truncated, here too: a disk that filled up mid archive is said so, never passed off as a zip an admin would find short of half its photographs
        if (!$zip->close()) {
            @unlink($archivePath);

            throw new \RuntimeException(sprintf('The archive could not be written at "%s".', $archivePath));
        }

        $response = new BinaryFileResponse($archivePath, 200, ['Content-Type' => 'application/zip']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('%s-%s_%s.zip', $name, $variant, date('Ymd_His')));
        $response->deleteFileAfterSend(true);

        return $response;
    }

    // What the selection weighs, before anything is written - the caller compares it to MAX_TOTAL_BYTES and says so rather than starting an archive it would have to abandon
    /** @param iterable<GalleryMedia> $medias */
    public function weigh(iterable $medias, string $variant): int
    {
        $total = 0;
        foreach ($this->files($medias, $variant) as $diskPath) {
            $total += (int) filesize($diskPath);
        }

        return $total;
    }

    /**
     * The files the selection really has on disk, keyed by the name they take inside the archive - a media whose file is gone, or whose original was never kept, simply contributes nothing.
     *
     * @param iterable<GalleryMedia> $medias
     *
     * @return array<string, string> archive entry name => absolute path
     */
    private function files(iterable $medias, string $variant): array
    {
        $files = [];
        foreach ($medias as $media) {
            $diskPath = $this->diskPath($media, $variant);
            if (null === $diskPath || !is_file($diskPath)) {
                continue;
            }

            $files[$this->entryName($media, $diskPath, $files)] = $diskPath;
        }

        return $files;
    }

    // The highres derivative sits next to the stored file under public/, the kept original outside it (see GalleryMedia::ORIGINAL_DIRECTORY) - the two are the whole reason this service exists, a browser reaching neither of them by url
    private function diskPath(GalleryMedia $media, string $variant): ?string
    {
        if (self::VARIANT_ORIGINAL === $variant) {
            $filename = $media->getOriginalFilename();

            return null === $filename ? null : $this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/' . $filename;
        }

        $filename = $media->getHighresFilename();

        return null === $filename ? null : $this->projectDir . '/public/' . $filename;
    }

    // The media's own slug rather than the stored name, so what lands in the admin's downloads folder reads as the photograph and not as the uniqid the upload gave it. The extension is the file's own, an original keeping the format it was shot in
    /** @param array<string, string> $taken */
    private function entryName(GalleryMedia $media, string $diskPath, array $taken): string
    {
        $extension = pathinfo($diskPath, \PATHINFO_EXTENSION);
        $base = (string) ($media->getSlug() ?? pathinfo($diskPath, \PATHINFO_FILENAME));
        $base = '' === $base ? 'media' : $base;

        // A slug is unique within its category and a selection never spans two, so this only ever answers for a gallery stored before slugs existed - two such medias would otherwise silently become one entry
        $name = $base . '.' . $extension;
        $suffix = 1;
        while (isset($taken[$name])) {
            $name = $base . '-' . ++$suffix . '.' . $extension;
        }

        return $name;
    }
}
