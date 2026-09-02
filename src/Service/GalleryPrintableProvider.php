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
use c975L\GalleryBundle\Contract\AutomaticGalleryInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use Symfony\Contracts\Service\ResetInterface;

// What the automatic gallery of the prints holds - the photographs flagged as being on sale, whatever category they were filed in (see GalleryMediaRepository::findPrintable)
// A shop needs one page gathering what it sells: a gallery of a thousand photographs of which sixty are for sale gives a visitor no way to see the sixty, and asking him to open them one by one is asking him to leave
class GalleryPrintableProvider implements AutomaticGalleryInterface, ResetInterface
{
    /** @var ?list<GalleryMedia> */
    private ?array $medias = null;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly GalleryMediaRepository $galleryMediaRepository,
    ) {
    }

    public function getKind(): string
    {
        return GalleryCategory::AUTOMATIC_PRINTABLE;
    }

    // The shop's own master switch, and nothing else: a site that never opened the shop must not grow a gallery of prints, and one that closes it keeps the category it already has - trashing that row is an admin's decision, not a consequence of a checkbox (see GalleryAutomaticProvider::ensureCategory)
    public function isAvailable(): bool
    {
        return true === $this->configService->get('gallery-print-enabled') && $this->getMax() > 0;
    }

    // How many photographs the gallery stops at - a ceiling and not a page size, the grid being the same one every gallery is drawn with. Straight from its own entry, which ships with its value: an entry left empty or set below one closes the gallery through isAvailable() instead of being defaulted here
    public function getMax(): int
    {
        return max(0, (int) $this->configService->get('gallery-printable-max'));
    }

    // The photographs the gallery shows - read once per request, every screen showing them (the index tile, the page itself, a block) asking for the same list
    /** @return list<GalleryMedia> */
    public function getMedias(): array
    {
        return $this->medias ??= $this->galleryMediaRepository->findPrintable($this->getMax());
    }

    // The list only ever describes the request being rendered - dropped between two of them so a worker runtime (FrankenPHP, RoadRunner...) doesn't serve the next one the medias of the previous
    public function reset(): void
    {
        $this->medias = null;
    }
}
