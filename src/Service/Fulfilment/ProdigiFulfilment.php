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
use c975L\GalleryBundle\Contract\PrintFulfilmentInterface;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Exception\PrintFulfilmentException;
use c975L\GalleryBundle\Service\GalleryPrintFileUrlGenerator;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Prodigi, printing giclée in the EU and shipping white-label.
 *
 * Everything about this lab lives here and nowhere else: the shape of its payloads, the names of its stages, the header
 * it authenticates on. What the bundle hands over is an order already priced and already numbered, and what it gets
 * back is a reference and a state - a different lab means a different class, not a different checkout.
 */
class ProdigiFulfilment implements PrintFulfilmentInterface
{
    private const string ENDPOINT = 'https://api.prodigi.com/v4.0';

    // Orders placed here are acknowledged and never printed, and no card is charged. What a site develops against, and what "gallery-print-sandbox" leaves it on until the day it opens
    private const string SANDBOX_ENDPOINT = 'https://api.sandbox.prodigi.com/v4.0';

    // How the lab's own stages map onto the states an order goes through here. Anything it reports that is not in this list leaves the order where it was rather than moving it somewhere invented
    private const array STAGES = [
        'InProgress' => GalleryPrintOrder::STATE_PRODUCING,
        'Complete' => GalleryPrintOrder::STATE_SHIPPED,
        'Cancelled' => GalleryPrintOrder::STATE_CANCELLED,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigServiceInterface $configService,
        private readonly GalleryPrintFileUrlGenerator $urlGenerator,
    ) {
    }

    public function getName(): string
    {
        return 'prodigi';
    }

    public function createOrder(GalleryPrintOrder $order): string
    {
        $basket = $order->getBasket();

        if (null === $basket) {
            throw new PrintFulfilmentException('The order carries no basket, so there is no address to ship it to.');
        }

        $items = [];

        foreach ($order->getCopies() as $copy) {
            $url = $this->urlGenerator->generate($copy);

            // A copy whose file cannot be reached would be printed as nothing at all, so the whole order is refused rather than shipped incomplete
            if (null === $url) {
                throw new PrintFulfilmentException(sprintf('Copy #%d has no print file to send.', (int) $copy->getId()));
            }

            // The lab's reference frozen onto the copy at the sale, not the catalogue key beside it: the two are different strings, and only this one names a product a printer can make
            $items[] = [
                'sku' => $copy->getSku(),
                'copies' => 1,
                // The file was composed at exactly the size ordered, so the lab has nothing left to decide about the frame - anything but this would recrop what was sold
                'sizing' => 'fillPrintArea',
                'assets' => [['printArea' => 'default', 'url' => $url]],
            ];
        }

        if ([] === $items) {
            throw new PrintFulfilmentException('The order carries no copy to print.');
        }

        $response = $this->request('POST', '/Orders', [
            'shippingMethod' => 'Standard',
            'recipient' => [
                'name' => $basket->getName(),
                'email' => $basket->getEmail(),
                'address' => [
                    'line1' => $basket->getAddress(),
                    'postalOrZipCode' => $basket->getZip(),
                    'townOrCity' => $basket->getCity(),
                    'countryCode' => $basket->getCountry(),
                ],
            ],
            'items' => $items,
        ]);

        $reference = $response['order']['id'] ?? null;

        if (!\is_string($reference) || '' === $reference) {
            throw new PrintFulfilmentException(sprintf('The lab accepted nothing it named: %s', json_encode($response)));
        }

        return $reference;
    }

    public function getState(string $reference): string
    {
        $response = $this->request('GET', '/Orders/' . rawurlencode($reference));
        $stage = $response['order']['status']['stage'] ?? null;

        if (!\is_string($stage) || !isset(self::STAGES[$stage])) {
            throw new PrintFulfilmentException(sprintf('The lab reports a stage this driver does not know: %s', var_export($stage, true)));
        }

        return self::STAGES[$stage];
    }

    public function readCallback(array $payload): ?array
    {
        $reference = $payload['data']['order']['id'] ?? null;
        $stage = $payload['data']['order']['status']['stage'] ?? null;

        // Labs post more than shipment notices - a payload this driver does not act on is not an error, it is somebody else's business
        if (!\is_string($reference) || !\is_string($stage) || !isset(self::STAGES[$stage])) {
            return null;
        }

        return ['reference' => $reference, 'state' => self::STAGES[$stage]];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $apiKey = $this->configService->get('gallery-print-api-key');

        if (!\is_string($apiKey) || '' === $apiKey) {
            throw new PrintFulfilmentException('No api key is configured for this lab.');
        }

        $options = ['headers' => ['X-API-Key' => $apiKey]];

        if (null !== $body) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $this->getEndpoint() . $path, $options);

            // Read inside the try on purpose: with the http client, the status code is only checked when the body is asked for, and a 4xx from the lab has to come back as our own exception rather than as its
            return $response->toArray();
        } catch (ExceptionInterface $exception) {
            throw new PrintFulfilmentException(sprintf('The lab could not be reached or refused the request: %s', $exception->getMessage()), 0, $exception);
        }
    }

    // Sandbox until a site says otherwise, so a shop that has not thought about it yet tests instead of printing
    private function getEndpoint(): string
    {
        return false === $this->configService->get('gallery-print-sandbox') ? self::ENDPOINT : self::SANDBOX_ENDPOINT;
    }
}
