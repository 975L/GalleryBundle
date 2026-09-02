<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service\Fulfilment;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Service\Fulfilment\ProdigiEnvironment;
use PHPUnit\Framework\TestCase;

// Which Prodigi is called and with which secret - two accounts, two keys, and one switch between them
class ProdigiEnvironmentTest extends TestCase
{
    public function testTheSandboxIsCalledWithTheTestKey(): void
    {
        $environment = $this->environment(sandbox: true);

        $this->assertTrue($environment->isSandbox());
        $this->assertSame('https://api.sandbox.prodigi.com/v4.0', $environment->getEndpoint());
        $this->assertSame('a-test-key', $environment->getApiKey());
    }

    public function testProductionIsCalledWithTheLiveKey(): void
    {
        $environment = $this->environment(sandbox: false);

        $this->assertFalse($environment->isSandbox());
        $this->assertSame('https://api.prodigi.com/v4.0', $environment->getEndpoint());
        $this->assertSame('a-live-key', $environment->getApiKey());
    }

    // The one mistake this exists to stop: the two accounts have separate credentials, so the other one's key would be answered with a 401 nobody could read
    public function testTheOtherAccountsKeyIsNeverHandedOver(): void
    {
        $this->assertNull($this->environment(sandbox: true, testKey: null)->getApiKey());
        $this->assertNull($this->environment(sandbox: false, liveKey: null)->getApiKey());
    }

    // An entry filled with nothing at all is an entry nobody filled
    public function testAnEmptyKeyIsNoKey(): void
    {
        $this->assertNull($this->environment(sandbox: true, testKey: '')->getApiKey());
    }

    private function environment(bool $sandbox, ?string $testKey = 'a-test-key', ?string $liveKey = 'a-live-key'): ProdigiEnvironment
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['gallery-print-sandbox', $sandbox],
            ['gallery-print-api-key-test', $testKey],
            ['gallery-print-api-key', $liveKey],
        ]);

        return new ProdigiEnvironment($configService);
    }
}
