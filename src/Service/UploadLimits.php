<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use Symfony\Component\HttpFoundation\Request;

// What a bulk upload is actually allowed to carry. Three of the four ceilings are php's own, and php enforces them by truncating: past max_file_uploads the extra files are dropped without a word, past post_max_size the request arrives with nothing in it at all. Reading them here is what lets the upload screen state them up front and refuse a batch before sending it, rather than reporting a success over a third of the medias
// The ini values are constructor arguments so a test can state them: left null, each reads the running php's own
class UploadLimits
{
    // This bundle's own ceiling per file, the one the validator enforces (see GalleryMediaBatchUploadType). Well above what a photograph weighs, php's upload_max_filesize being the limit that really bites
    // In bytes rather than as "20M": Symfony's File constraint reads that shorthand as decimal (20,000,000) where php.ini reads it as binary (20,971,520), and the screen would then advertise a megabyte the validator refuses
    public const MAX_FILE_SIZE = 20 * 1024 ** 2;

    // A ceiling of this bundle's own on the count too, php's max_file_uploads being generous on some hosts and silent on what a batch really costs: every media is decoded, resized three times and written back within the one request, so a hundred at a time is what stays under a shared host's time and memory limits. More than that is several uploads, which the category takes just as well
    public const MAX_FILES = 100;

    public function __construct(
        private readonly ?string $maxFileUploads = null,
        private readonly ?string $uploadMaxFilesize = null,
        private readonly ?string $postMaxSize = null,
    ) {
    }

    // How many files one submission may carry: php's max_file_uploads (20 in a default install) or this bundle's own, whichever is smaller
    public function getMaxFiles(): int
    {
        return max(1, min((int) ($this->maxFileUploads ?? \ini_get('max_file_uploads')), self::MAX_FILES));
    }

    // The smaller of php's own per-file ceiling and this bundle's: raising one without the other changes nothing
    public function getMaxFileSize(): int
    {
        return min(
            $this->toBytes((string) ($this->uploadMaxFilesize ?? \ini_get('upload_max_filesize'))),
            self::MAX_FILE_SIZE
        );
    }

    // What the whole batch may weigh together. php's post_max_size caps the request, but a full batch of files at the per-file ceiling caps it just as surely - the effective limit is whichever comes first, which also leaves a finite number to state when post_max_size carries none
    public function getMaxBatchSize(): int
    {
        return min(
            $this->toBytes((string) ($this->postMaxSize ?? \ini_get('post_max_size'))),
            $this->getMaxFiles() * $this->getMaxFileSize()
        );
    }

    // post_max_size exceeded: php drops $_POST and $_FILES wholesale, csrf token included, so the form is never "submitted" and the screen would redisplay itself blank and silent. The browser having sent the bytes, only Content-Length is left to tell this apart from a genuinely empty request - the check in gallery-upload-limits.js is what normally prevents it, this catches the batch that got past it
    public function isTruncatedRequest(Request $request): bool
    {
        return $request->isMethod('POST')
            && [] === $request->request->all()
            && [] === $request->files->all()
            && $request->server->getInt('CONTENT_LENGTH') > 0
        ;
    }

    // The same figure in whole megabytes, which is how a ceiling is stated to the person about to hit it
    public function toMegabytes(int $bytes): int
    {
        return max(1, intdiv($bytes, 1024 ** 2));
    }

    // "20M" as a count of bytes. A php.ini size is written with a K/M/G shorthand, or as a plain byte count; "0" (or an empty setting) means no limit, which is returned as PHP_INT_MAX so a comparison against it never fails
    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value || '0' === $value) {
            return \PHP_INT_MAX;
        }

        $number = (int) $value;
        $multiplier = match (strtoupper(substr($value, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        return $number * $multiplier;
    }
}
