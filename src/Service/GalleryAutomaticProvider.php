<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Contract\AutomaticGalleryInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use Symfony\Contracts\Service\ResetInterface;

// The one place the automatic galleries are handled, for the front-office viewer, the blocks and the back-office screen alike: a category flagged with a kind holds no media of its own, so everything showing it has to be handed its list
// The kinds themselves know nothing of any of this - each answers what it gathers (see AutomaticGalleryInterface), and the plumbing around it is written once here rather than once per kind
class GalleryAutomaticProvider implements ResetInterface
{
    /** @var array<string, AutomaticGalleryInterface> */
    private array $galleries = [];

    /** @param iterable<AutomaticGalleryInterface> $galleries */
    public function __construct(
        iterable $galleries,
        private readonly GalleryCategoryRepository $galleryCategoryRepository,
        private readonly GalleryMediaRepository $galleryMediaRepository,
    ) {
        foreach ($galleries as $gallery) {
            $this->galleries[$gallery->getKind()] = $gallery;
        }
    }

    // The medias a category shows - its own when it is an ordinary gallery, the list of its kind when it is automatic
    // Asked by every screen rendering a gallery, so none of them has to know which of the two it holds
    /** @return list<GalleryMedia> */
    public function getMedias(GalleryCategory $category): array
    {
        $gallery = $this->gallery($category);

        return null === $gallery
            ? $this->galleryMediaRepository->findVisibleByCategory($category)
            : $gallery->getMedias();
    }

    // The neighbours of a media within the automatic gallery it is being browsed from, null when the media is not among them - which is what sends the page back to its own category's navigation (see GalleryController::media)
    // Circular like the category's own (see GalleryMediaRepository::findPreviousAndNext), and falling back to the media itself when the list holds it alone
    /** @return ?array{previous: GalleryMedia, next: GalleryMedia} */
    public function findPreviousAndNext(GalleryMedia $media, GalleryCategory $category): ?array
    {
        $medias = $this->getMedias($category);
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

    // The automatic gallery of one kind, written the first time it is asked for and read back every time after - nobody creates it, it is a gallery the bundle owns rather than an option carried by one of the site's (see GalleryCategoryRepository::findOrCreateAutomatic)
    // Null when the site does not want that gallery: a kind whose feature is turned off leaves no row behind, so a site never grows a gallery it did not ask for
    // Returned even when it sits in the trash, which is where an admin puts it to be rid of it: the callers below leave a trashed one out of what they show, exactly as they leave out any other trashed gallery
    public function ensureCategory(string $kind): ?GalleryCategory
    {
        $gallery = $this->galleries[$kind] ?? null;

        return null !== $gallery && $gallery->isAvailable()
            ? $this->galleryCategoryRepository->findOrCreateAutomatic($kind)
            : null;
    }

    // The same for every kind installed, for the screens that list the galleries rather than render one
    public function ensureCategories(): void
    {
        foreach (array_keys($this->galleries) as $kind) {
            $this->ensureCategory($kind);
        }
    }

    // The listed galleries, the automatic ones among them and each holding the medias it shows - what every screen listing categories hands its own list to (the public index, the categories block, the back-office listing)
    // A row is only ever looked for when the list doesn't already carry it, which is the very first render and no other: it is a normal category from then on, read and ordered with the rest
    /** @param list<GalleryCategory> $categories @return list<GalleryCategory> */
    public function prepare(array $categories): array
    {
        // First thing done, and on the whole list handed over: the automatic galleries below have their own lists, but every other category still has a tile to draw
        $this->handCoverCandidates($categories);

        $added = false;
        foreach ($this->galleries as $kind => $gallery) {
            $category = array_find($categories, static fn (GalleryCategory $listed): bool => $listed->getAutomaticKind() === $kind);

            if (null === $category) {
                $category = $this->ensureCategory($kind);

                // A trashed one is left where the admin put it, and so is the list: findAllOrdered() drops the trash, and adding it back here would put on the site the one gallery someone took off it - and a masked one is dropped for exactly the same reason (see GalleryCategory::$hidden)
                if (null === $category || $category->isDeleted() || $category->isHidden()) {
                    continue;
                }

                $categories[] = $category;
                $added = true;
            }

            $category->setAutomaticMedias($gallery->getMedias());
        }

        if ($added) {
            usort($categories, static fn (GalleryCategory $a, GalleryCategory $b): int => $a->getPosition() <=> $b->getPosition());
        }

        return $categories;
    }

    // Hands the automatic categories the lists they show, the others being left alone - for the callers holding entities they did not read themselves (the back-office listing, whose rows EasyAdmin paginates)
    /** @param iterable<GalleryCategory> $categories */
    public function hydrate(iterable $categories): void
    {
        $listed = [];
        foreach ($categories as $category) {
            $gallery = $this->gallery($category);
            if (null !== $gallery) {
                $category->setAutomaticMedias($gallery->getMedias());
            }
            $listed[] = $category;
        }

        // The rows of that listing show a thumbnail and a media count, both read from the relation - one query per row without this
        $this->handCoverCandidates($listed);
    }

    // The gallery answering for a category's kind, null for an ordinary one and for a kind no provider is installed for - a category flagged by a bundle since removed is then rendered as the ordinary gallery it has become, rather than fataling on a public page
    private function gallery(GalleryCategory $category): ?AutomaticGalleryInterface
    {
        $kind = $category->getAutomaticKind();

        return null === $kind ? null : ($this->galleries[$kind] ?? null);
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

    // The lists only ever describe the request being rendered - dropped between two of them so a worker runtime (FrankenPHP, RoadRunner...) doesn't serve the next one the medias of the previous
    public function reset(): void
    {
        foreach ($this->galleries as $gallery) {
            if ($gallery instanceof ResetInterface) {
                $gallery->reset();
            }
        }
    }
}
