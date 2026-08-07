<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\UiBundle\Contract\BundleScriptAdminProviderInterface;
use c975L\UiBundle\Contract\BundleScriptProviderInterface;

// Two entrypoints, each starting its own Stimulus app: the public pages' own controllers, and the one the EasyAdmin upload screen needs (see assets/controllers-admin.js). Both are tagged in services.yaml and need their own importmap.php entry, declared by Management\ImportmapProvider
class ScriptProvider implements BundleScriptProviderInterface, BundleScriptAdminProviderInterface
{
    public function getScripts(): array
    {
        return [
            '@c975l/gallery-bundle/controllers.js',
        ];
    }

    public function getAdminScripts(): array
    {
        return [
            '@c975l/gallery-bundle/controllers-admin.js',
        ];
    }
}
