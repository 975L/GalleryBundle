<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Model;

// The licences a gallery's photographs are published under: all rights reserved, which is what the law gives an author without a word said, or one of the Creative Commons 4.0 set, whose own deed is the licence a search engine reads
final class GalleryLicense
{
    public const string RESERVED = 'reserved';

    // Creative Commons licence => its deed, the page the licence is published under and the one "license" names in a photograph's structured data
    private const array DEEDS = [
        'cc-by' => 'https://creativecommons.org/licenses/by/4.0/',
        'cc-by-sa' => 'https://creativecommons.org/licenses/by-sa/4.0/',
        'cc-by-nd' => 'https://creativecommons.org/licenses/by-nd/4.0/',
        'cc-by-nc' => 'https://creativecommons.org/licenses/by-nc/4.0/',
        'cc-by-nc-sa' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
        'cc-by-nc-nd' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/',
        'cc0' => 'https://creativecommons.org/publicdomain/zero/1.0/',
    ];

    // Every licence offered, all rights reserved first as it is the default
    /** @return list<string> */
    public static function all(): array
    {
        return [self::RESERVED, ...array_keys(self::DEEDS)];
    }

    // Whether the licence is one the bundle offers
    public static function has(string $license): bool
    {
        return \in_array($license, self::all(), true);
    }

    // The Creative Commons deed, null for all rights reserved, whose terms are the site's own page (see config "gallery-license-url")
    public static function deedUrl(string $license): ?string
    {
        return self::DEEDS[$license] ?? null;
    }

    // The licence's own short name, the one Creative Commons asks a credit to carry, which is never translated: "CC BY-NC 4.0", "CC0 1.0"
    public static function name(string $license): ?string
    {
        $url = self::deedUrl($license);
        if (null === $url) {
            return null;
        }

        return 'cc0' === $license ? 'CC0 1.0' : 'CC ' . strtoupper(substr($license, 3)) . ' 4.0';
    }

    // The icons Creative Commons draws a licence with, in its own order: the "cc" mark, then one per condition - or the public domain mark for CC0 (see public/icons/)
    /** @return list<string> */
    public static function icons(string $license): array
    {
        if (null === self::deedUrl($license)) {
            return [];
        }

        return 'cc0' === $license ? ['cc', 'zero'] : ['cc', ...explode('-', substr($license, 3))];
    }

    // The translation key naming a licence, in the "gallery" domain
    public static function label(string $license): string
    {
        return 'label.gallery_license_' . str_replace('-', '_', $license);
    }
}
