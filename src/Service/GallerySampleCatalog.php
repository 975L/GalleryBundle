<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\UiBundle\Registry\PlaceholderMediaRegistry;

/**
 * The made-up gallery this bundle stands behind, held as plain data and read by its two consumers, down to the
 * photograph each of its medias is shown under.
 *
 * GalleryShowcaseProvider turns it into the arrays a block showcase renders, GalleryDemoFixtureProvider into the
 * rows a demo site is browsed for. One dataset, two readings: enriching it here shows up in both, which is the
 * whole reason it is not written twice.
 *
 * Everything a visitor reads is a key of the "gallery" domain rather than a sentence, so a demo site seeded in
 * Spanish reads as a Spanish gallery.
 */
class GallerySampleCatalog
{
    // Every media of a made-up gallery says the same of who took it, there being no one to credit
    public const string CREDITS_KEY = 'label.gallery_sample_credits';

    public function __construct(
        private readonly PlaceholderMediaRegistry $placeholderMediaRegistry,
    ) {
    }

    /**
     * Three categories of four medias: the showcase reads the first three categories and the first four medias
     * of one of them, so a thinner catalog would leave one of its two kinds half drawn.
     *
     * @return list<array{slug: string, title: string, medias: list<array{slug: string, title: string}>}>
     */
    public function getCategories(): array
    {
        return [
            [
                'slug' => 'paysages',
                'title' => 'label.gallery_sample_category_landscapes',
                'medias' => [
                    $this->media('cretes-au-matin', 'ridges'),
                    $this->media('lac-gele', 'frozen_lake'),
                    $this->media('foret-de-hetres', 'beeches'),
                    $this->media('orage-sur-la-vallee', 'storm'),
                ],
            ],
            [
                'slug' => 'architecture',
                'title' => 'label.gallery_sample_category_architecture',
                'medias' => [
                    $this->media('escalier-de-pierre', 'staircase'),
                    $this->media('verriere', 'glass_roof'),
                    $this->media('facade-ocre', 'facade'),
                    $this->media('passage-couvert', 'arcade'),
                ],
            ],
            [
                'slug' => 'noir-et-blanc',
                'title' => 'label.gallery_sample_category_monochrome',
                'medias' => [
                    $this->media('quai-desert', 'quay'),
                    $this->media('mains-de-potier', 'potter'),
                    $this->media('rue-sous-la-pluie', 'rain'),
                    $this->media('portrait-a-contre-jour', 'backlit'),
                ],
            ],
        ];
    }

    /**
     * The photograph the site declares for this one media, keyed as "gallery/<slug>" (see
     * PlaceholderMediaProviderInterface) - a gallery being the one place where the picture is not an illustration of
     * the row but the row itself, so a rotated placeholder is the stopgap and never the point.
     *
     * Read here by both consumers rather than resolved on each side, so the showcase and the gallery its links lead
     * to show the same picture under the same title.
     *
     * Falls back on the generic pool, rotated over the medias, for a slug the site declares no photograph for.
     *
     * @param list<string> $images
     */
    public function photograph(string $slug, array $images, int $index): string
    {
        $declared = $this->placeholderMediaRegistry->getImagesFor('gallery/' . $slug);

        // One media is one photograph: a second declared file has nothing to be, where a product sheet would leaf through it
        return $declared[0] ?? $images[$index % \count($images)];
    }

    /**
     * @return array{slug: string, title: string}
     */
    private function media(string $slug, string $key): array
    {
        return [
            'slug' => $slug,
            'title' => 'label.gallery_sample_media_' . $key,
        ];
    }
}
