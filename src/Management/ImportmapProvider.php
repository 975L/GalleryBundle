<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\ImportmapProviderInterface;

// Same import names as ScriptProvider - that one tells the layout which scripts to load, this one tells c975l:config:check-importmap what importmap.php entries they need
class ImportmapProvider implements ImportmapProviderInterface
{
    public function getAdminImportmapEntries(): array
    {
        return [
            '@c975l/gallery-bundle/controllers-admin.js' => [
                'path' => 'assets/controllers-admin.js',
                'entrypoint' => true,
            ],
        ];
    }

    public function getImportmapEntries(): array
    {
        return [
            '@c975l/gallery-bundle/controllers.js' => [
                'path' => 'assets/controllers.js',
                'entrypoint' => true,
            ],
        ];
    }
}
