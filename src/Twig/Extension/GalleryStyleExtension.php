<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Twig\Extension;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// What the viewer's pages hand to the layout's "bodyClass" block: the gallery's own class, the ready-made style the "gallery-style" config names, and the passe-partout the "gallery-frame" one picks
// Each list lives here as much as in config/configs.json, and deliberately so: the config decides what an admin is offered, this decides what reaches the markup - a value stored before a style was renamed, or one arriving from an import, would otherwise write a class the stylesheet paints nothing for
class GalleryStyleExtension extends AbstractExtension
{
    // Each value has its own block in sass/_gallery.scss, which GalleryStyleTest keeps in step with these two lists
    public const STYLES = ['light', 'dark'];
    public const FRAMES = ['none', 'thin', 'wide'];

    private const PAGE_CLASS = 'gallery-page';
    private const STYLE_SLUG = 'gallery-style';
    private const FRAME_SLUG = 'gallery-frame';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gallery_body_class', [$this, 'getBodyClass']),
        ];
    }

    // No style, no frame, an unknown value or a config not loaded yet: the page keeps the gallery's own class alone, painted in the site's colors and framed at the token's own default, which is what a gallery did before either config existed
    public function getBodyClass(): string
    {
        $classes = [self::PAGE_CLASS];

        // The frame's own values ("none", "thin", "wide") are named after what they are and not after the gallery, hence the prefix - a class reading "gallery-page--none" would say nothing about what is none
        foreach ([self::STYLE_SLUG => ['', self::STYLES], self::FRAME_SLUG => ['frame-', self::FRAMES]] as $slug => [$prefix, $allowed]) {
            $value = trim((string) $this->configService->get($slug));

            if (\in_array($value, $allowed, true)) {
                $classes[] = self::PAGE_CLASS . '--' . $prefix . $value;
            }
        }

        return implode(' ', $classes);
    }
}
