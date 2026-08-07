<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\GalleryBundle\Controller\Management\GalleryCategoryCrudController;
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

    // One entry for the whole feature, opening the categories, which are the site's galleries - the media CRUD edits one media at a time and has nothing to list on its own
    public function testGetMenusReturnsOnlyTheCategoryCrudEntry(): void
    {
        $provider = new MenuProvider();

        $menus = $provider->getMenus();

        $this->assertCount(1, $menus);
        $this->assertSame(GalleryCategoryCrudController::class, $menus['gallery']['controller']);
        $this->assertSame('label.gallery', $menus['gallery']['label']);
        $this->assertSame('gallery', $menus['gallery']['translation_domain']);
    }

    public function testGetLinksReturnsNone(): void
    {
        $provider = new MenuProvider();

        $this->assertSame([], $provider->getLinks());
    }
}
