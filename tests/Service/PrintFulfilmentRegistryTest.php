<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Contract\PrintFulfilmentInterface;
use c975L\GalleryBundle\Service\PrintFulfilmentRegistry;
use PHPUnit\Framework\TestCase;

class PrintFulfilmentRegistryTest extends TestCase
{
    private function createDriver(string $name): PrintFulfilmentInterface
    {
        $driver = $this->createStub(PrintFulfilmentInterface::class);
        $driver->method('getName')->willReturn($name);

        return $driver;
    }

    private function createRegistry(?string $configured, PrintFulfilmentInterface ...$drivers): PrintFulfilmentRegistry
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($configured);

        return new PrintFulfilmentRegistry($drivers, $configService);
    }

    public function testGetHandsBackTheDriverTheSiteNamed(): void
    {
        $prodigi = $this->createDriver('prodigi');

        $this->assertSame($prodigi, $this->createRegistry('prodigi', $this->createDriver('manual'), $prodigi)->get());
    }

    public function testGetAnswersNoneWhenTheSiteNamedNoLab(): void
    {
        $this->assertNull($this->createRegistry(null, $this->createDriver('prodigi'))->get());
        $this->assertNull($this->createRegistry('', $this->createDriver('prodigi'))->get());
    }

    // A name nobody answers is a mistyped configuration, and this is read while settling a payment: the order has to be written for a human rather than the sale lost on an exception
    public function testGetAnswersNoneForANameNoDriverCarries(): void
    {
        $this->assertNull($this->createRegistry('prodgi', $this->createDriver('prodigi'))->get());
    }

    // getByName() keeps throwing, unlike get(): it reads an order already sent, where a name nothing answers is a real anomaly
    public function testGetByNameRefusesANameNoDriverCarries(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createRegistry(null, $this->createDriver('prodigi'))->getByName('prodgi');
    }

    public function testGetNamesListsWhatIsInstalled(): void
    {
        $this->assertSame(['manual', 'prodigi'], $this->createRegistry(null, $this->createDriver('prodigi'), $this->createDriver('manual'))->getNames());
    }
}
