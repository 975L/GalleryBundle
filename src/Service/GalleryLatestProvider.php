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
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use Symfony\Contracts\Service\ResetInterface;

// What the automatic gallery holds - the medias of the last days that carry an addition, whatever category they landed in (see GalleryMediaRepository::findLatest)
// The one place that answers it, for the front-office viewer, the blocks and the back-office screen alike: a category flagged "automatic" holds no media of its own, so everything showing it has to be handed its list
class GalleryLatestProvider implements ResetInterface
{
    // What a site that never touched the two entries shows: the week just gone, and never more than two hundred medias on one page
    public const int DEFAULT_DAYS = 7;
    public const int DEFAULT_MAX = 200;

    /** @var ?list<GalleryMedia> */
    private ?array $medias = null;

    private ?GalleryCategory $category = null;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly GalleryCategoryRepository $galleryCategoryRepository,
        private readonly GalleryMediaRepository $galleryMediaRepository,
    ) {
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

    // The neighbours of a media within the last additions, for a visitor browsing them rather than a gallery - null when the media is not among them, which is what sends the page back to its own category's navigation (see GalleryController::media)
    // Circular like the category's own (see GalleryMediaRepository::findPreviousAndNext), and falling back to the media itself when the list holds it alone
    /** @return ?array{previous: GalleryMedia, next: GalleryMedia} */
    public function findPreviousAndNext(GalleryMedia $media): ?array
    {
        $medias = $this->getMedias();
        $index = array_search($media, $medias, true);

        if (false === $index) {
            return null;
        }

        $count = count($medias);

        return [
            'previous' => $medias[($index - 1 + $count) % $count],
            'next' => $medias[($index + 1) % $count],
        ];
    }

    // The gallery of the last additions itself, written the first time it is asked for and read back every time after - nobody creates it, it is a gallery the bundle owns rather than an option carried by one of the site's (see GalleryCategoryRepository::findOrCreateAutomatic)
    // Returned even when it sits in the trash, which is where an admin puts it to be rid of it: the callers below leave a trashed one out of what they show, exactly as they leave out any other trashed gallery
    public function ensureCategory(): GalleryCategory
    {
        return $this->category ??= $this->galleryCategoryRepository->findOrCreateAutomatic();
    }

    // The listed galleries, the automatic one among them and holding the medias it shows - what every screen listing categories hands its own list to (the public index, the categories block, the back-office listing)
    // The row is only ever looked for when the list doesn't already carry it, which is the very first render and no other: it is a normal category from then on, read and ordered with the rest
    /** @param list<GalleryCategory> $categories @return list<GalleryCategory> */
    public function prepare(array $categories): array
    {
        // First thing done, and on the whole list handed over: the automatic gallery below has its own list, but every other category still has a tile to draw - including on a site whose automatic gallery sits in the trash, which returns early
        $this->handCoverCandidates($categories);

        $automatic = array_find($categories, static fn (GalleryCategory $category): bool => $category->isAutomatic());

        if (null === $automatic) {
            $automatic = $this->ensureCategory();

            // A trashed one is left where the admin put it, and so is the list: findAllOrdered() drops the trash, and adding it back here would put on the site the one gallery someone took off it
            if ($automatic->isDeleted()) {
                return $categories;
            }

            $categories[] = $automatic;
            usort($categories, static fn (GalleryCategory $a, GalleryCategory $b): int => $a->getPosition() <=> $b->getPosition());
        }

        $automatic->setAutomaticMedias($this->getMedias());

        return $categories;
    }

    // Hands the categories the medias their tile is drawn from, in one query for all of them: the relation is lazy, so a page listing the galleries read it category by category, one query each
    // Every listed category is a candidate, cover or not: the tile falls back on the medias when the cover is missing or trashed (see GalleryCategory::getCoverOrRandomMedia), and the media count printed next to it reads that same collection whatever the cover
    // Done here rather than in the repository, which the sitemap and the menu link picker also read: neither of them draws a tile, and neither has to pay for the medias of every gallery of the site
    /** @param list<GalleryCategory> $categories */
    private function handCoverCandidates(array $categories): void
    {
        $candidates = array_values(array_filter(
            $categories,
            static fn (GalleryCategory $category): bool => !$category->isAutomatic()
        ));

        // A single category saves nothing - the lazy relation reads it in the one query this would run anyway, and a block showing one gallery has no reason to read the medias of all the others
        if (count($candidates) < 2) {
            return;
        }

        $medias = $this->galleryMediaRepository->findVisibleByCategories($candidates);
        foreach ($candidates as $category) {
            $category->setLoadedMedias($medias[(int) $category->getId()] ?? []);
        }
    }

    // Hands the automatic category the list it shows, the others being left alone - for the callers holding entities they did not read themselves (the back-office listing, whose rows EasyAdmin paginates)
    /** @param iterable<GalleryCategory> $categories */
    public function hydrate(iterable $categories): void
    {
        $listed = [];
        foreach ($categories as $category) {
            if ($category->isAutomatic()) {
                $category->setAutomaticMedias($this->getMedias());
            }
            $listed[] = $category;
        }

        // The rows of that listing show a thumbnail and a media count, both read from the relation - one query per row without this
        $this->handCoverCandidates($listed);
    }

    // The list only ever describes the request being rendered - dropped between two of them so a worker runtime (FrankenPHP, RoadRunner...) doesn't serve the next one the medias of the previous. The category goes with it, an entity being no more reusable across requests than the list is
    public function reset(): void
    {
        $this->medias = null;
        $this->category = null;
    }
}
