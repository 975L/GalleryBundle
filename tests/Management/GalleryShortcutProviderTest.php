<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Controller\Management\GalleryShortcutController;
use c975L\GalleryBundle\Management\GalleryShortcutProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// The dashboard tile putting the lab into rehearsal and back. It only ever exists on a site that sells prints
class GalleryShortcutProviderTest extends TestCase
{
    // A switch toggling the rehearsal of a shop that is closed is a switch with no other side
    public function testASiteThatSellsNoPrintIsOfferedNoTile(): void
    {
        $this->assertSame([], $this->provider(printEnabled: false)->getShortcuts());
    }

    public function testTheTileIsOfferedOnceTheShopIsOpen(): void
    {
        $shortcuts = $this->provider(printEnabled: true)->getShortcuts();

        $this->assertCount(1, $shortcuts);
        $this->assertSame(GalleryShortcutController::TOGGLE_ROUTE_PRINT_TEST_MODE, $shortcuts[0]['route']);
        $this->assertSame(ShortcutProviderInterface::CATEGORY_TOGGLE, $shortcuts[0]['category']);
    }

    // The tile reads the state it toggles, so the dashboard shows the lab as it actually is
    public function testTheTileReportsWhetherTheLabIsInTestMode(): void
    {
        $this->assertTrue($this->provider(printEnabled: true, sandbox: true)->getShortcuts()[0]['active']);
        $this->assertFalse($this->provider(printEnabled: true, sandbox: false)->getShortcuts()[0]['active']);
    }

    // Held at the admin role: a lab put into rehearsal without anybody noticing is a shop taking orders nobody prints
    public function testTheTileIsHeldAtTheAdminRole(): void
    {
        $this->assertSame('ROLE_ADMIN', $this->provider(printEnabled: true)->getShortcuts()[0]['role']);
    }

    private function provider(bool $printEnabled, bool $sandbox = false): GalleryShortcutProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $slug): mixed => match ($slug) {
                'gallery-print-enabled' => $printEnabled,
                'gallery-print-sandbox' => $sandbox,
                'site-role-admin' => 'ROLE_ADMIN',
                default => null,
            },
        );
        $configService->method('getBool')->willReturnCallback(static fn (mixed $value): bool => true === $value);

        return new GalleryShortcutProvider($this->createStub(TranslatorInterface::class), $configService);
    }
}
