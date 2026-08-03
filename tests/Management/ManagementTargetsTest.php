<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\ConfigBundle\Test\ManagementTargetsTestCase;
use c975L\GalleryBundle\Management\MenuProvider;

// Every CRUD controller and route this bundle's management providers name, checked against what its controllers actually declare - see ConfigBundle's ManagementTargetsTestCase
class ManagementTargetsTest extends ManagementTargetsTestCase
{
    protected function managementProviders(): iterable
    {
        return [new MenuProvider()];
    }

    // This bundle's own controllers on top of ConfigBundle's, whose screens its links point to as well
    protected function controllerDirectories(): array
    {
        return [...parent::controllerDirectories(), __DIR__ . '/../../src/Controller', __DIR__ . '/../../src/Controller/Management'];
    }
}
