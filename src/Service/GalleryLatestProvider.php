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
    // What a site that never touched the two entries shows: the week just gone, and never more than two hundred medias on one page
    public const int DEFAULT_DAYS = 7;
    public const int DEFAULT_MAX = 200;

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

    // Always - a gallery of the last additions is what any gallery has, where the one of the prints only makes sense on a site that sells them
    public function isAvailable(): bool
    {
        return true;
    }

    // How many days back the gallery reaches, and how many medias it stops at - a value nobody set, or set to nothing, falls back on the constants above rather than emptying the gallery
    public function getDays(): int
    {
        return max(1, (int) ($this->configService->get('gallery-latest-days') ?: self::DEFAULT_DAYS));
    }

    public function getMax(): int
    {
        return max(1, (int) ($this->configService->get('gallery-latest-max') ?: self::DEFAULT_MAX));
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
