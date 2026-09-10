<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Controller\Management\GalleryCategoryCrudController;
use c975L\GalleryBundle\Controller\Management\GalleryMediaCrudController;
use c975L\GalleryBundle\Management\MenuProvider;
use PHPUnit\Framework\TestCase;

class MenuProviderTest extends TestCase
{
    // Answers the editor key each entry names, the bar its own screen states
    private function createProvider(): MenuProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null
        );

        return new MenuProvider($configService);
    }

    // A gallery is content like any other: without the key the entry takes the admin default and goes missing from an editor's sidebar, with the tour step that walks to it (see MenuProviderInterface::getMenus())
    public function testTheEntryNamesTheEditorBarItsOwnScreenStates(): void
    {
        $this->assertSame('ROLE_EDITOR', $this->createProvider()->getMenus()['gallery']['role']);
    }

    // Its own header rather than the shared "management" one: MenuBuilder groups sections on "domain.label", so the gallery's screens gather under their own caption instead of stretching the section every core bundle already fills
    public function testGetMenuSectionIsTheGallerysOwn(): void
    {
        $provider = $this->createProvider();

        $this->assertSame(['label' => 'label.gallery', 'translation_domain' => 'gallery', 'icon' => 'fas fa-camera'], $provider->getMenuSection());
    }

    // Two entries: the galleries, where a gallery is composed and its own medias arranged, and the whole library read across them all
    public function testGetMenusReturnsTheCategoryAndTheLibraryEntries(): void
    {
        $provider = $this->createProvider();

        $menus = $provider->getMenus();

        $this->assertCount(2, $menus);
        $this->assertSame(GalleryCategoryCrudController::class, $menus['gallery']['controller']);
        $this->assertSame('label.gallery_categories', $menus['gallery']['label']);
        $this->assertSame('gallery', $menus['gallery']['translation_domain']);
        $this->assertSame(GalleryMediaCrudController::class, $menus['gallery_media']['controller']);
        $this->assertSame('label.gallery_medias', $menus['gallery_media']['label']);
        $this->assertSame('gallery', $menus['gallery_media']['translation_domain']);
    }

    // The contact sheet is content like the galleries are, and its own screen states the same bar (see GalleryMediaCrudController::roleNeeded)
    public function testTheLibraryEntryNamesTheEditorBarToo(): void
    {
        $this->assertSame('ROLE_EDITOR', $this->createProvider()->getMenus()['gallery_media']['role']);
    }

    // Without it the entry's onboarding step shows its label and nothing else - the key is the categories screen's own opening text, not one written for the tour
    public function testTheEntryDescribesItselfForTheOnboardingTour(): void
    {
        $provider = $this->createProvider();

        $this->assertSame('label.info_gallery_category', $provider->getMenus()['gallery']['description']);
    }

    public function testGetLinksReturnsNone(): void
    {
        $provider = $this->createProvider();

        $this->assertSame([], $provider->getLinks());
    }
}
