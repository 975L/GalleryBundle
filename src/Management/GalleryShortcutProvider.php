<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Controller\Management\GalleryShortcutController;
use Symfony\Contracts\Translation\TranslatorInterface;

// Grouped with the other test switches, next to PaymentBundle's and ShopBundle's: an admin who put the payments into rehearsal is about to look for the lab's own rehearsal in the same row, and the two being apart is how a site ends up charging for real while printing nothing
class GalleryShortcutProvider implements ShortcutProviderInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getShortcuts(): array
    {
        // Nothing to offer a site that does not sell prints: a tile toggling the rehearsal of a shop that is closed is a switch with no other side
        if (true !== $this->configService->get('gallery-print-enabled')) {
            return [];
        }

        $enabled = $this->configService->getBool($this->configService->get('gallery-print-sandbox'));

        return [
            [
                'label' => $this->translator->trans(
                    $enabled ? 'label.print_test_mode_disable' : 'label.print_test_mode_enable',
                    [],
                    'gallery',
                ),
                'icon' => 'fas fa-vial',
                'route' => GalleryShortcutController::TOGGLE_ROUTE_PRINT_TEST_MODE,
                'active' => $enabled,
                'role' => $this->configService->get('site-role-admin'),
                'category' => ShortcutProviderInterface::CATEGORY_TOGGLE,
            ],
        ];
    }
}
