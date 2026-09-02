<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service\Fulfilment;

use c975L\ConfigBundle\Service\ConfigServiceInterface;

/**
 * Which Prodigi a call goes to, and with which secret - the sandbox or the real thing.
 *
 * The two are separate accounts with separate credentials, so a site cannot switch between them by flipping one
 * setting: the sandbox key answers 401 in production and the production key answers 401 in the sandbox. Both are held
 * at once, the way PaymentBundle holds "stripe-secret" beside "stripe-secret-test", and the switch picks one - which
 * means a shop can go and come back without ever pasting a secret again.
 *
 * Read by the driver and by the catalogue, so the endpoint and the key are decided in one place instead of two.
 */
class ProdigiEnvironment
{
    private const string ENDPOINT = 'https://api.prodigi.com/v4.0';

    private const string SANDBOX_ENDPOINT = 'https://api.sandbox.prodigi.com/v4.0';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // Whether calls go to the sandbox, which is a whole account and not a mode: orders placed there are acknowledged and never printed
    public function isSandbox(): bool
    {
        return true === $this->configService->get('gallery-print-sandbox');
    }

    public function getEndpoint(): string
    {
        return $this->isSandbox() ? self::SANDBOX_ENDPOINT : self::ENDPOINT;
    }

    // The secret of the account being called, or null when that one was never filled - a site with a production key and the sandbox switch on has no key at all here, which is the honest answer and not the other account's secret
    public function getApiKey(): ?string
    {
        $key = $this->configService->get($this->isSandbox() ? 'gallery-print-api-key-test' : 'gallery-print-api-key');

        return \is_string($key) && '' !== $key ? $key : null;
    }
}
