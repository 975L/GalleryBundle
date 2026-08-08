<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\BackupPath;
use c975L\ConfigBundle\Management\BackupPathProviderInterface;
use c975L\GalleryBundle\Entity\GalleryMedia;

// Where a gallery's uploads land, which is the only content of this bundle a git clone and a database dump cannot bring back - ConfigBundle backs up nothing it wasn't declared, so a bundle staying silent here is a gallery backed up nowhere
class GalleryBackupPathProvider implements BackupPathProviderInterface
{
    // The category folder and the file name follow (see GalleryMedia::getVichMediaPath), so the two roots below cover the medium served, its thumb and highres siblings, the self-hosted videos and the kept originals alike
    private const MEDIA_DIRECTORY = 'medias/gallery';

    public function getBackupPaths(): array
    {
        return [
            // Mirrored rather than archived: a gallery grows without bound, bzip2 gains about nothing on a webp, and a photo needs a copy rather than a history
            new BackupPath('public/' . self::MEDIA_DIRECTORY, BackupPath::MODE_MIRROR),
            // The untouched uploads kept outside the document root, which is what lets a media be re-processed later without a re-upload
            new BackupPath(GalleryMedia::ORIGINAL_DIRECTORY . '/' . self::MEDIA_DIRECTORY, BackupPath::MODE_MIRROR),
        ];
    }
}
