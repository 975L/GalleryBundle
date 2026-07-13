# GalleryBundle

Symfony bundle providing a photo gallery (galleries → categories → photos) managed through EasyAdmin, with batch upload and automatic thumb/medium/highres derivatives.

[![License](https://img.shields.io/github/license/975L/GalleryBundle)](https://github.com/975L/GalleryBundle/blob/master/LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/c975l/gallery-bundle)](https://packagist.org/packages/c975l/gallery-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/c975l/gallery-bundle)](https://packagist.org/packages/c975l/gallery-bundle)

---

## Features

- `Gallery` → `GalleryCategory` → `GalleryPhoto`: a site can host more than one independent gallery, each with its own categories. The `default` gallery's public routes stay short (no `{gallery}` slug segment).
- Batch upload: add several photos at once to a category, with credits/rights-reserved applied to the whole batch and per-photo alt text - reuses the `CollectionType` + `VichImageType` pattern from UiBundle's own Slider block.
- Three derivatives generated automatically per photo (thumbnail square / medium / highres), via UiBundle's `VichImageResizeListener` and the `VichMultiSizeImageInterface` contract - naming and resizing stay centralized in UiBundle, this bundle only declares the target sizes.
- One EasyAdmin menu entry (the photo library); category management is reachable from its toolbar so both screens read as one linked feature.
- A catch-all "Non classé" category is created lazily so every photo always has one, even without picking a real one at upload time.

---

## Requirements

- PHP >= 8.0
- `c975l/ui-bundle` (Vich naming/resizing, EasyAdmin form-theme conventions)
- `c975l/config-bundle` (EasyAdmin dashboard, menu provider)
- Doctrine ORM
- VichUploader Bundle

---

## Installation

```bash
composer require c975l/gallery-bundle
```

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

No extra Stimulus controller registration needed - the batch upload screen reuses EasyAdmin's own collection add/remove widget and Vich file preview (see `gallery_photo_upload.html.twig`).
