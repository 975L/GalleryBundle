<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The url a lab fetches a print from.
 *
 * Signed and expiring rather than secret. A lab pulls the file from its own servers, so it has to be a plain public url
 * - what keeps the untouched original from being downloadable by anyone is that the link only works for a few days and
 * only with the signature this site put on it.
 */
class GalleryPrintFileUrlGenerator
{
    // Long enough for a lab that queues an order over a weekend and retries it on Monday, short enough that a link found in an old log is worthless
    public const LIFETIME = '+7 days';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UriSigner $uriSigner,
    ) {
    }

    public function generate(GalleryPrintCopy $copy): ?string
    {
        $id = $copy->getId();

        if (null === $id) {
            return null;
        }

        $url = $this->urlGenerator->generate('gallery_print_file', ['copy' => $id], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url, new \DateTimeImmutable(self::LIFETIME));
    }
}
