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
use c975L\GalleryBundle\Controller\Management\GalleryPrintFormatCrudController;
use c975L\GalleryBundle\Controller\Management\GalleryPrintOrderCrudController;
use c975L\GalleryBundle\Management\MenuProvider;
use PHPUnit\Framework\TestCase;

class MenuProviderTest extends TestCase
{
    // Answers the editor key each entry names, the bar its own screen states, and the print sale switch
    private function createProvider(bool $printEnabled = false): MenuProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'site-role-editor' => 'ROLE_EDITOR',
                'gallery-print-enabled' => $printEnabled,
                default => null,
            }
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

    // Four entries: the galleries, where a gallery is composed and its own medias arranged, the whole library read across them all, then the print orders and catalogue
    public function testGetMenusReturnsTheCategoryTheLibraryAndThePrintEntries(): void
    {
        $provider = $this->createProvider();

        $menus = $provider->getMenus();

        $this->assertSame(['gallery', 'gallery_media', 'gallery_print_order', 'gallery_print_format'], array_keys($menus));
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

    // The print screens are set up and tested before the sale opens, so the switch hides neither of them (their indexes say the sale is closed instead)
    public function testThePrintEntriesAreListedWhetherOrNotTheSaleIsOpen(): void
    {
        foreach ([false, true] as $printEnabled) {
            $menus = $this->createProvider($printEnabled)->getMenus();

            $this->assertSame(GalleryPrintOrderCrudController::class, $menus['gallery_print_order']['controller']);
            $this->assertSame(GalleryPrintFormatCrudController::class, $menus['gallery_print_format']['controller']);
            $this->assertSame('ROLE_EDITOR', $menus['gallery_print_order']['role']);
            $this->assertSame('ROLE_EDITOR', $menus['gallery_print_format']['role']);
        }
    }

    public function testGetLinksReturnsNone(): void
    {
        $provider = $this->createProvider();

        $this->assertSame([], $provider->getLinks());
    }
}
