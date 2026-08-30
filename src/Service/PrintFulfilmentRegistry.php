<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Contract\PrintFulfilmentInterface;

// Hands out the lab the site named in "gallery-print-provider". Kept as a lookup rather than a single injected driver so a site can change lab in the back-office, and so an unknown name is answered with the list of what is installed instead of a container that no longer builds
class PrintFulfilmentRegistry
{
    /** @param iterable<PrintFulfilmentInterface> $drivers */
    public function __construct(
        private readonly iterable $drivers,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // The driver the site configured, or null when it named none - a gallery with no lab is the ordinary case of a site that only shows photographs
    // Null for a name nobody answers too, where getByName() below throws: this one is read while settling a payment (see GalleryPrintBasketItemProvider::onBasketPaid), and a mistyped configuration must leave the order written for a human rather than lose a sale that has already been charged
    public function get(): ?PrintFulfilmentInterface
    {
        $name = $this->configService->get('gallery-print-provider');

        if (!\is_string($name) || '' === $name) {
            return null;
        }

        foreach ($this->drivers as $driver) {
            if ($driver->getName() === $name) {
                return $driver;
            }
        }

        return null;
    }

    // The driver an order was sent through, which is not always the one configured today: a site that changes lab still has to read what the previous one says about the orders it is already printing
    public function getByName(string $name): PrintFulfilmentInterface
    {
        foreach ($this->drivers as $driver) {
            if ($driver->getName() === $name) {
                return $driver;
            }
        }

        throw new \InvalidArgumentException(sprintf('No print fulfilment driver named "%s". Installed: %s.', $name, implode(', ', $this->getNames()) ?: 'none'));
    }

    // What the back-office offers as a choice, so the list follows what is installed instead of a list of names written down somewhere
    /** @return list<string> */
    public function getNames(): array
    {
        $names = [];

        foreach ($this->drivers as $driver) {
            $names[] = $driver->getName();
        }

        sort($names);

        return $names;
    }
}
