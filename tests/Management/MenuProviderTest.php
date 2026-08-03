<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\GalleryBundle\Controller\Management\GalleryPhotoCrudController;
use c975L\GalleryBundle\Management\MenuProvider;
use PHPUnit\Framework\TestCase;

class MenuProviderTest extends TestCase
{
    // Shared with ConfigBundle's/SiteBundle's/UiBundle's own MenuProvider so all merge into one section
    public function testGetMenuSectionMatchesTheSharedManagementSection(): void
    {
        $provider = new MenuProvider();

        $this->assertSame(['label' => 'label.management', 'translation_domain' => 'site'], $provider->getMenuSection());
    }

    // Only the photo CRUD is listed - category management is reachable from its toolbar instead (see GalleryPhotoCrudController), so the two screens read as one linked feature
    public function testGetMenusReturnsOnlyThePhotoCrudEntry(): void
    {
        $provider = new MenuProvider();

        $menus = $provider->getMenus();

        $this->assertCount(1, $menus);
        $this->assertSame(GalleryPhotoCrudController::class, $menus['gallery_photo']['controller']);
        $this->assertSame('label.gallery_photos', $menus['gallery_photo']['label']);
        $this->assertSame('gallery', $menus['gallery_photo']['translation_domain']);
    }

    public function testGetLinksReturnsNone(): void
    {
        $provider = new MenuProvider();

        $this->assertSame([], $provider->getLinks());
    }
}
