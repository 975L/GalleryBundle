<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\ConfigBundle\Service\SiteLocales;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\UiBundle\Service\ContentTranslator;

// What this gallery says in another language - the words typed on a category, a photograph and a print format, stored beside the row as a page's are (see SiteBundle's PageTranslator), slugs, files, SKUs and credits left out, and nothing read at all on a single-language site
class GalleryTranslator
{
    // The vocabulary this bundle's rows are named with, the way Page and Product name theirs - a plain string, no foreign key ever pointing at it (see UiBundle's Translation)
    public const string OWNER_CATEGORY = 'gallery_category';

    public const string OWNER_MEDIA = 'gallery_media';

    public const string OWNER_PRINT_FORMAT = 'gallery_print_format';

    // The gallery's name, and the line a social network prints under its card
    public const array CATEGORY_FIELDS = ['title', 'summarySocialNetwork'];

    // What is read under a photograph. Its credits are left out: they name a person or an agency
    public const array MEDIA_FIELDS = ['title', 'description'];

    // What a buyer picks between: the format's own name, the paper it is drawn on and what that paper is
    public const array PRINT_FORMAT_FIELDS = ['label', 'paper', 'paperDescription'];

    public function __construct(
        private readonly ContentTranslator $contentTranslator,
        private readonly SiteLocales $siteLocales,
    ) {
    }

    // Whether the site declares more than one language, nothing being translated otherwise
    public function isActive(): bool
    {
        return $this->contentTranslator->isActive();
    }

    // The languages a row may be written in besides the one it was written in
    /** @return list<string> */
    public function getTranslatableLocales(): array
    {
        return $this->contentTranslator->getTranslatableLocales();
    }

    // Lays the language being rendered over each row's own texts for this render only - called by what renders them rather than on postLoad, the back office having to go on showing the text the row was written in
    /** @param iterable<GalleryCategory|GalleryMedia|GalleryPrintFormat> $rows */
    public function apply(iterable $rows, ?string $locale = null): void
    {
        // Tested before the collection is touched: on a single-language site the proxy behind it is never initialised, and a listing costs no query at all here
        if (!$this->contentTranslator->isActive()) {
            return;
        }

        $rows = $rows instanceof \Traversable ? iterator_to_array($rows) : $rows;

        if ([] === $rows) {
            return;
        }

        $this->preload($rows, $locale);

        foreach ($rows as $row) {
            $id = $row->getId();
            if (null === $id) {
                continue;
            }

            // Given nothing to lay over, translate() hands back the translated fields alone - an untranslated one is absent rather than null, which is what makes the getters fall back on the text the row was written in
            $row->setTranslated($this->contentTranslator->translate($this->owner($row), $id, [], $this->fields($row), $locale));
        }
    }

    // Reads ahead a whole set of rows, so a gallery of forty photographs costs one query rather than forty
    /** @param iterable<GalleryCategory|GalleryMedia|GalleryPrintFormat> $rows */
    public function preload(iterable $rows, ?string $locale = null): void
    {
        if (!$this->contentTranslator->isActive()) {
            return;
        }

        // Grouped by kind: each is one query of its own, a category and a photograph being two owner types
        $ids = [];
        foreach ($rows as $row) {
            $id = $row->getId();
            if (null !== $id) {
                $ids[$this->owner($row)][] = $id;
            }
        }

        foreach ($ids as $owner => $ownerIds) {
            $this->contentTranslator->preload($owner, $ownerIds, $locale);
        }
    }

    // The languages this row really says something in, its own included, a language counting once the row's own name is written there - what a "hreflang" group may name, which url answers being GalleryTranslatedLocales' question
    /** @return list<string> */
    public function translatedLocales(GalleryCategory | GalleryMedia | GalleryPrintFormat $row): array
    {
        $id = $row->getId();
        $locales = [$this->siteLocales->getDefaultLocale()];
        if (null === $id) {
            return $locales;
        }

        $name = $this->fields($row)[0];

        foreach ($this->getTranslatableLocales() as $locale) {
            $written = $this->contentTranslator->values($this->owner($row), $id, $locale)[$name] ?? null;
            if (null !== $written && '' !== $written) {
                $locales[] = $locale;
            }
        }

        return $locales;
    }

    // Every language this row has been given, for the screen that writes them
    /** @return array<string, array<string, string|null>> locale => field => value */
    public function all(GalleryCategory | GalleryMedia | GalleryPrintFormat $row): array
    {
        $id = $row->getId();

        return null === $id ? [] : $this->contentTranslator->all($this->owner($row), $id);
    }

    // What a language screen offers for each translatable text: what that language already says, or the source text between brackets where it says nothing yet
    /** @return array<string, string|null> field => value */
    public function promptValues(GalleryCategory | GalleryMedia | GalleryPrintFormat $row, string $locale): array
    {
        $written = $this->all($row)[$locale] ?? [];

        $values = [];
        foreach ($this->fields($row) as $field) {
            $translated = $written[$field] ?? null;
            $values[$field] = null !== $translated && '' !== $translated
                ? $translated
                : ContentTranslator::prompt($row->getUntranslated($field));
        }

        return $values;
    }

    // Hands what a language screen wrote over to be stored on the flush that saves the row, a field still holding the bracketed source counting as nothing written (see ContentTranslator::stage)
    /** @param array<string, string|null> $values field => value */
    public function stage(GalleryCategory | GalleryMedia | GalleryPrintFormat $row, string $locale, array $values): void
    {
        $id = $row->getId();
        if (null === $id) {
            return;
        }

        $staged = [];
        foreach ($this->fields($row) as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }

            $staged[$field] = ContentTranslator::untouched($values[$field], $row->getUntranslated($field)) ? null : $values[$field];
        }

        if ([] !== $staged) {
            $this->contentTranslator->stage($this->owner($row), $id, $locale, $staged);
        }
    }

    // Writes a translation straight away rather than staging it - what a seeder or a bulk pass does, having no form to wait for
    /** @param array<string, string|null> $values field => value */
    public function store(GalleryCategory | GalleryMedia | GalleryPrintFormat $row, string $locale, array $values): void
    {
        $id = $row->getId();
        if (null !== $id) {
            $this->contentTranslator->store($this->owner($row), $id, $locale, $values);
        }
    }

    // What this row's translations are filed under
    public function owner(GalleryCategory | GalleryMedia | GalleryPrintFormat $row): string
    {
        return match (true) {
            $row instanceof GalleryCategory => self::OWNER_CATEGORY,
            $row instanceof GalleryMedia => self::OWNER_MEDIA,
            default => self::OWNER_PRINT_FORMAT,
        };
    }

    // The texts of this row that are translated, its own name first
    /** @return list<string> */
    public function fields(GalleryCategory | GalleryMedia | GalleryPrintFormat $row): array
    {
        return match (true) {
            $row instanceof GalleryCategory => self::CATEGORY_FIELDS,
            $row instanceof GalleryMedia => self::MEDIA_FIELDS,
            default => self::PRINT_FORMAT_FIELDS,
        };
    }
}
