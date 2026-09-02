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

// What the automatic gallery of the last additions holds - the medias of the last days that carry an addition, whatever category they landed in (see GalleryMediaRepository::findLatest)
// Everything a category needs around that list is GalleryAutomaticProvider's, this one answering what it gathers and nothing else
class GalleryLatestProvider implements AutomaticGalleryInterface, ResetInterface
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
        return GalleryCategory::AUTOMATIC_LATEST;
    }

    // Always, as soon as the two entries it is drawn from hold a value - a gallery of the last additions is what any gallery has, where the one of the prints only makes sense on a site that sells them. Emptying either entry closes it, rather than quietly drawing it over one day or one media
    public function isAvailable(): bool
    {
        return $this->getDays() > 0 && $this->getMax() > 0;
    }

    // How many days back the gallery reaches, and how many medias it stops at - each straight from its own entry, which ships with its value: nothing is defaulted here, an entry left empty or set below one closing the gallery through isAvailable()
    public function getDays(): int
    {
        return max(0, (int) $this->configService->get('gallery-latest-days'));
    }

    public function getMax(): int
    {
        return max(0, (int) $this->configService->get('gallery-latest-max'));
    }

    // The medias the gallery shows, most recent first - read once per request, every screen showing them (the index tile, the page itself, a block) asking for the same list
    /** @return list<GalleryMedia> */
    public function getMedias(): array
    {
        return $this->medias ??= $this->galleryMediaRepository->findLatest($this->getDays(), $this->getMax());
    }

    // The same list cut into the days its medias were added on, for the back-office screen alone: what an admin credits or downloads in one go is an upload session, and a heading per day is what tells one from the next
    /** @return list<array{day: \DateTimeImmutable, medias: list<GalleryMedia>}> */
    public function getMediasByDay(): array
    {
        $days = [];
        foreach ($this->getMedias() as $media) {
            $day = $media->getCreatedAt()?->format('Y-m-d') ?? '';
            $days[$day][] = $media;
        }

        $groups = [];
        foreach ($days as $day => $medias) {
            $groups[] = ['day' => new \DateTimeImmutable((string) $day), 'medias' => $medias];
        }

        return $groups;
    }

    // The list only ever describes the request being rendered - dropped between two of them so a worker runtime (FrankenPHP, RoadRunner...) doesn't serve the next one the medias of the previous
    public function reset(): void
    {
        $this->medias = null;
    }
}
