<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller;

use c975L\GalleryBundle\Exception\PrintFulfilmentException;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;
use c975L\GalleryBundle\Service\GalleryPrintOrderTracker;
use c975L\GalleryBundle\Service\PrintFulfilmentRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where a lab says an order has moved on.
 *
 * The payload is only ever read as a name: which order this is about. What that order's state now is comes from asking
 * the lab itself, because a print lab does not sign its callbacks and this url is public - anybody could post that
 * every order has shipped. The worst a forged call can do here is have this site make one request to its own lab.
 *
 * Outside the gallery's configurable prefix, for the reason PrintFileController is: renaming "gallery" to "photos" in
 * the dashboard must not silence the labs already posting here.
 */
class PrintCallbackController extends AbstractController
{
    public function __construct(
        private readonly PrintFulfilmentRegistry $fulfilmentRegistry,
        private readonly GalleryPrintOrderRepository $orderRepository,
        private readonly GalleryPrintOrderTracker $tracker,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/gallery-print-callback/{provider}', name: 'gallery_print_callback', methods: ['POST'])]
    public function callback(Request $request, string $provider): Response
    {
        try {
            $driver = $this->fulfilmentRegistry->getByName($provider);
        } catch (\InvalidArgumentException) {
            return new Response('Unknown provider', Response::HTTP_NOT_FOUND);
        }

        try {
            $read = $driver->readCallback((array) json_decode((string) $request->getContent(), true));
        } catch (PrintFulfilmentException $exception) {
            $this->logger->warning('Print callback refused', ['provider' => $provider, 'error' => $exception->getMessage()]);

            return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
        }

        // Labs post more than shipment notices, and the ones this driver does not act on are somebody else's business rather than an error to be replayed
        if (null === $read) {
            return new Response('Callback ignored', Response::HTTP_OK);
        }

        $order = $this->orderRepository->findByReference($provider, $read['reference']);

        if (null === $order) {
            $this->logger->warning('Print callback names an unknown order', ['provider' => $provider, 'reference' => $read['reference']]);

            return new Response('Unknown order', Response::HTTP_OK);
        }

        // The state is asked of the lab rather than believed: what arrived is an unsigned post to a public url, and the answer to a question this site asked is not
        try {
            $this->tracker->apply($order, $driver->getState($read['reference']));
        } catch (PrintFulfilmentException $exception) {
            $this->logger->error('Print callback could not be confirmed with the lab', ['provider' => $provider, 'reference' => $read['reference'], 'error' => $exception->getMessage()]);

            // The lab is told to post again, the nightly synchronisation catching the order in the meantime either way
            return new Response('Callback failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response('Callback received', Response::HTTP_OK);
    }
}
