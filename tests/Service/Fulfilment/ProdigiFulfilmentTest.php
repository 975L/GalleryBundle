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
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Exception\PrintFulfilmentException;
use c975L\GalleryBundle\Model\PrintCopySnapshot;
use c975L\GalleryBundle\Service\Fulfilment\ProdigiEnvironment;
use c975L\GalleryBundle\Service\Fulfilment\ProdigiFulfilment;
use c975L\GalleryBundle\Service\GalleryPrintFileUrlGenerator;
use c975L\PaymentBundle\Entity\Basket;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Everything about this lab lives in one class: what it is handed is an order already priced and numbered, and what it gives back is a reference
class ProdigiFulfilmentTest extends TestCase
{
    private const string CALLBACK_URL = 'https://example.org/gallery-print-callback/prodigi';

    /** @var list<array<string, mixed>> */
    private array $payloads = [];

    public function testTheOrderIsPlacedAndTheLabsReferenceHandedBack(): void
    {
        $reference = $this->fulfilment()->createOrder($this->order());

        $this->assertSame('pro-8891', $reference);
        $this->assertSame('SKU-30x40', $this->payloads[0]['items'][0]['sku']);
        $this->assertSame('https://example.org/gallery-print-file/1?signature=x', $this->payloads[0]['items'][0]['assets'][0]['url']);
    }

    // Told per order rather than left to the lab's dashboard, a site being developed in the sandbox and opened in production without anybody going to set an address there
    public function testTheLabIsToldWhereToPostItsCallbacks(): void
    {
        $this->fulfilment()->createOrder($this->order());

        $this->assertSame(self::CALLBACK_URL, $this->payloads[0]['callbackUrl']);
    }

    // Sent again after a double click or a lost answer, an order carries the same key, and the lab answers with the order it already holds
    public function testAnOrderSentTwiceCarriesTheSameKeyAndGetsTheExistingOrderBack(): void
    {
        $order = $this->order();
        $fulfilment = $this->fulfilment(['outcome' => 'AlreadyExists', 'order' => ['id' => 'pro-8891']]);

        $this->assertSame('pro-8891', $fulfilment->createOrder($order));
        $fulfilment->createOrder($order);

        $this->assertSame($this->payloads[0]['idempotencyKey'], $this->payloads[1]['idempotencyKey']);
    }

    // The lab keeps its keys for good and per account: neither another order nor another site on that account may meet one already used
    public function testTheKeyDiffersFromOneOrderAndOneSiteToTheNext(): void
    {
        $order = $this->order();
        $this->fulfilment()->createOrder($order);
        $this->fulfilment(callbackUrl: 'https://another.example.org/gallery-print-callback/prodigi')->createOrder($order);

        $other = $this->order();
        new \ReflectionProperty(GalleryPrintOrder::class, 'id')->setValue($other, 5);
        new \ReflectionProperty(GalleryPrintOrder::class, 'createdAt')->setValue($other, $order->getCreatedAt());
        $this->fulfilment()->createOrder($other);

        $this->assertCount(3, array_unique(array_column($this->payloads, 'idempotencyKey')));
    }

    // A copy printed as nothing at all is worse than an order refused: the whole thing comes back to the admin instead
    public function testAnOrderWhoseFileCannotBeReachedIsRefused(): void
    {
        $this->expectException(PrintFulfilmentException::class);

        $this->fulfilment(fileUrl: null)->createOrder($this->order());
    }

    public function testAnOrderCarryingNoBasketHasNowhereToShipAndIsRefused(): void
    {
        $this->expectException(PrintFulfilmentException::class);

        $this->fulfilment()->createOrder(new GalleryPrintOrder());
    }

    public function testAStageThisDriverKnowsIsMappedOntoOneOfOurStates(): void
    {
        $fulfilment = $this->fulfilment(['order' => ['status' => ['stage' => 'Complete']]]);

        $this->assertSame(GalleryPrintOrder::STATE_SHIPPED, $fulfilment->getState('pro-8891'));
    }

    public function testAStageThisDriverDoesNotKnowIsRefusedRatherThanInvented(): void
    {
        $this->expectException(PrintFulfilmentException::class);

        $this->fulfilment(['order' => ['status' => ['stage' => 'Whatever']]])->getState('pro-8891');
    }

    // Labs post more than shipment notices, and the ones this driver ignores are somebody else's business
    public function testACallbackThisDriverDoesNotActOnIsReadAsNothing(): void
    {
        $this->assertNull($this->fulfilment()->readCallback(['data' => ['order' => ['id' => 'pro-8891']]]));
    }

    public function testACallbackIsReadAsTheOrderItNamesAndTheStageItReports(): void
    {
        $read = $this->fulfilment()->readCallback(['data' => ['order' => ['id' => 'pro-8891', 'status' => ['stage' => 'InProgress']]]]);

        $this->assertSame(['reference' => 'pro-8891', 'state' => GalleryPrintOrder::STATE_PRODUCING], $read);
    }

    private function order(): GalleryPrintOrder
    {
        $basket = new Basket()
            ->setName('Camille Roy')
            ->setEmail('camille@example.org')
            ->setAddress('12 rue des Alpes')
            ->setZip('74000')
            ->setCity('Annecy')
            ->setCountry('FR')
        ;

        $copy = new GalleryPrintCopy()->applySnapshot(
            new PrintCopySnapshot('30x40', '30 x 40 cm', 'SKU-30x40', 9000, 'Col du Galibier', null, '975L')
        );

        return new GalleryPrintOrder()->setBasket($basket)->setProvider('prodigi')->addCopy($copy);
    }

    private function fulfilment(?array $response = null, ?string $fileUrl = 'https://example.org/gallery-print-file/1?signature=x', string $callbackUrl = self::CALLBACK_URL): ProdigiFulfilment
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->payloads[] = json_decode($options['body'] ?? '{}', true) ?? [];

            return new MockResponse(json_encode($response ?? ['order' => ['id' => 'pro-8891']]), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('an-api-key');

        $environment = new ProdigiEnvironment($configService);

        $fileUrlGenerator = $this->createStub(GalleryPrintFileUrlGenerator::class);
        $fileUrlGenerator->method('generate')->willReturn($fileUrl);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn($callbackUrl);

        return new ProdigiFulfilment($httpClient, $environment, $fileUrlGenerator, $urlGenerator);
    }
}
