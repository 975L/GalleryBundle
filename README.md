# GalleryBundle

Symfony bundle providing photo galleries on the c975L core — galleries, categories and photos, with batch upload, automatic thumb/medium/highres derivatives and a public viewer.

[![License](https://img.shields.io/github/license/975L/GalleryBundle)](https://github.com/975L/GalleryBundle/blob/master/LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/c975l/gallery-bundle)](https://packagist.org/packages/c975l/gallery-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/c975l/gallery-bundle)](https://packagist.org/packages/c975l/gallery-bundle)
[![Codacy Grade](https://app.codacy.com/project/badge/Grade/afc2cddbc52444d99c991da76354d622)](https://app.codacy.com/gh/975L/GalleryBundle/dashboard)

## Why GalleryBundle

![GalleryBundle](.github/images/GalleryBundle.svg)

Add GalleryBundle on top of the shared [UiBundle](https://github.com/975L/UiBundle) + [ConfigBundle](https://github.com/975L/ConfigBundle) foundation to get a photo gallery — no dependency on SiteBundle, ShopBundle or any other satellite bundle, so it drops into any c975L site that needs one. Batch upload and multi-size derivatives reuse UiBundle's own `CollectionType`/`VichImageType` and `VichMultiSizeImageInterface` patterns rather than duplicating them.

---

> **TL;DR** — Photo galleries as `Gallery` → `GalleryCategory` → `GalleryPhoto`, managed from EasyAdmin, with batch upload and automatic thumb/medium/highres derivatives. A site can host several independent galleries; the `default` one keeps short public routes. Depends only on UiBundle + ConfigBundle.

## Contents

- **Setup** — [requirements](#requirements) · [installation](#installation) · [routes](#enable-routes) · [theme](#theme)
- **Using it** — [videos](#videos) · [migrating a legacy flat-file gallery](#migrating-from-a-legacy-finderflat-file-gallery) · [export / import categories](#export--import-categories) · [sitemap](#sitemap)

## Features

- `Gallery` → `GalleryCategory` → `GalleryPhoto`: a site can host more than one independent gallery, each with its own categories. The `default` gallery's public routes stay short (no `{gallery}` slug segment).
- Batch upload: add several photos at once to a category, with credits/rights-reserved applied to the whole batch and per-photo alt text - reuses the `CollectionType` + `VichImageType` pattern from UiBundle's own Slider block.
- Three derivatives generated automatically per photo (thumbnail square / medium / highres), via UiBundle's `VichImageResizeListener` and the `VichMultiSizeImageInterface` contract - naming and resizing stay centralized in UiBundle, this bundle only declares the target sizes.
- One EasyAdmin menu entry (the photo library); category management is reachable from its toolbar so both screens read as one linked feature.
- A catch-all "Non classé" category is created lazily so every photo always has one, even without picking a real one at upload time.
- A public front-office viewer (gallery → category → photo → high-res photo), with circular previous/next navigation whose neighbouring images are preloaded in the background so switching photos never shows a blank image while it loads.
- YouTube and TikTok entries sit in the same categories as the photos: each carries its own uploaded still, so one grid holds both kinds, and opens on a cookie-free embed instead of a high-resolution image (see [videos](#videos)).
- The bundle's own stylesheet and theme file, reading UiBundle's admin-editable colors and fonts, so a gallery looks like the site it is installed on without a line of CSS (see [theme](#theme)).
- A one-off console command to migrate a legacy Finder/flat-file photo gallery into this bundle's entities.
- Sitemap generation (gallery index, categories and photo pages), via ConfigBundle's `SitemapProviderInterface`
- Categories can be exported/imported as a zip (gallery, photos and files bundled in), plugging into ConfigBundle's **Export sync (everything)** dashboard shortcut and **Import content** screen.

---

## Requirements

- PHP >= 8.4
- `c975l/ui-bundle` >= 1.18 (Vich naming/resizing, EasyAdmin form-theme conventions, stylesheet registry, page layout fallback)
- `c975l/config-bundle` >= 6 (EasyAdmin dashboard, menu provider, scaffold, sitemap and health checks)
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

No extra Stimulus controller registration needed for the back-office - the batch upload screen reuses EasyAdmin's own collection add/remove widget and Vich file preview (see `gallery_photo_upload.html.twig`). The front-office photo viewer ships its own Stimulus controller (previous/next image preloading), auto-registered via UiBundle's script registry - nothing to wire up manually there either.

### Enable routes

Add the bundle's public routes to `config/routes.yaml`:

```yaml
c975_l_gallery:
    resource: "@c975LGalleryBundle/src/Controller/"
    type: attribute
    prefix: /
```

This exposes `/photos` (gallery index), `/photos/{category}` (category grid), `/photos/{category}/{id}`
(photo, medium resolution) and `/photos/{category}/{id}/hr` (photo, high resolution) - for the
`default` `Gallery` only (see `GalleryRepository::findOrCreateDefault()`). Templates
`{% extends 'layout.html.twig' %}`, same convention as this ecosystem's other public-facing bundles
(e.g. BookBundle) - override any of them from your app's `templates/bundles/c975LGalleryBundle/`.

### Theme

The bundle ships its compiled stylesheet (`bundles/c975lgallery/css/styles.min.css`, contributed to
UiBundle's stylesheet registry by `Service\StylesheetProvider`) and, like every other c975L bundle, one
theme file of its own — `assets/styles/themes/gallery.css`, copied into the app by
`php bin/console c975l:scaffold:install` and owned by it from then on. Every token ships commented out
at the bundle's default: uncomment a line to take it over, leave it and it keeps following the bundle.

Colors and fonts are deliberately absent from that file. They are admin-editable, in the **theme**
config group, and the gallery reads them through UiBundle's own `--primary` / `--text` / `--white` /
`--font-family-body`: a gallery therefore looks like the site it is installed on with no CSS to write.
What the file does offer is the gallery's own shapes — thumbnail size and grid gap, the measure of the
two photo pages, the passe-partout framing a displayed photo, the overlay arrows, the video badge and
the two embed aspect ratios.

### Videos

A `GalleryPhoto` carries a **type** (`image`, `youtube` or `tiktok`) and, for the two video types, the
**id the platform gives the video** — nothing else, the urls being built from it. Whatever its type, an
entry always carries its own uploaded still: it is what the grids show, so one category holds photos and
videos alike, and nothing is fetched from a third party while a page renders. The type only decides what
opening the entry shows — the still and its high-resolution link, or the player:

- YouTube plays on `youtube-nocookie.com`, TikTok on its own `embed/v2` endpoint. Both are cookie-free
  until the visitor actually presses play, which is what lets them be served without a consent gate.
- `/photos/{category}/{id}/hr` returns a 404 for a video: there is no high resolution to serve, and
  blowing up the still would be worse than not offering the url at all. The high-resolution page's own
  previous/next arrows therefore fall back to a video neighbour's medium view instead of a dead url.
- The batch upload screen picks the type once for the whole batch (a run of stills is of one nature) and
  takes each row's own video id — same shared/per-row split as the credits.
- A half-declared video (a type with no id, or an id left behind by a type switched back to `image`)
  stays an image, so the player never resurrects.

### Migrating from a legacy Finder/flat-file gallery

If a site currently serves its photos from a hand-rolled `Symfony\Finder`-based folder tree (no
database), `c975l:gallery:import-legacy` migrates it into this bundle's entities once, after which the
site should manage photos through the EasyAdmin CRUD instead:

```bash
# One category per top-level subdirectory of the source tree (slug = slugified directory name)
php bin/console c975l:gallery:import-legacy assets/photos --dry-run
php bin/console c975l:gallery:import-legacy assets/photos

# Flat legacy layout (no subdirectories): everything goes to the gallery's "Non classé" category
php bin/console c975l:gallery:import-legacy assets/medias/photos --flat --dry-run
php bin/console c975l:gallery:import-legacy assets/medias/photos --flat

# Same, into a named category (found or created by slug) - what a site importing several flat sources needs
php bin/console c975l:gallery:import-legacy assets/medias/photos --flat --category=Photos
```

Always run with `--dry-run` first. The command feeds each photo's original file (not any legacy
`-small`/`-highres`/`.webp` derivative) through the same Vich upload flow as the CRUD, so fresh
thumbnail/medium/highres derivatives get generated - it never reuses legacy derivative files.

### Export / import categories

Selected categories can be exported as a zip (title/slug/photos, files bundled in) via the category
index's "Export selection" batch action, meant to be re-uploaded on another site/environment through
ConfigBundle's **Import content** dashboard screen (see `GalleryImportProvider`). Ids never need to
match between the two sites: the parent gallery and category are matched by slug on import, a slug being
unique within its gallery (two titles slugifying identically get a numeric suffix). A gallery created by
an import never takes over the `default` flag from the local one, so importing can't move the site's
public gallery under it. `GalleryExportProvider` (the same serialization, every category) also plugs
categories into ConfigBundle's **Export sync (everything)** dashboard shortcut.

### Sitemap

The urls are declared by `GallerySitemapProvider` (ConfigBundle's `SitemapProviderInterface`): the `/photos`
index, one entry per category, and one per photo — a photo has a page of its own, which is what an image
search actually lands on. Only the default gallery is declared, matching what `GalleryController` serves.
Neither `Gallery` nor `GalleryCategory` carries a date of its own, so a category page is dated by its most
recently touched photo. Nothing to register — the provider is picked up automatically.

`public/sitemap-gallery.xml` and the site's `public/sitemap-index.xml` are written by ConfigBundle, which
collects every installed bundle's provider:

```bash
php bin/console c975l:sitemaps:create
```

Those same urls are also **health-checked** for free, ConfigBundle's `DeclaredUrlsHealthCheckPass` registering
one check per declared sitemap with nothing to implement bundle-side: every declared url
gets the content-quality checks (title/description length, missing `<h1>`, Open Graph share tags, images
without `alt`, broken links) under its own `urls-gallery` kind on the Health check dashboard. Worth keeping on
its own, less frequent schedule — a gallery declares one url per photo:

```bash
php bin/console c975l:health-check:run --kind=urls-gallery
```

---

> [!TIP]
> If this project **helps you save development time**:
>
> - [**star** it on GitHub](https://github.com/975L/GalleryBundle) — helps others find it
> - [**open an issue**](https://github.com/975L/GalleryBundle/issues/new) to share how you use it — genuinely useful feedback
>
> And if you'd like to support the work directly, the **Sponsor** button at the top of the GitHub page is there for that. Thank you!
