# GalleryBundle

Symfony bundle providing photo galleries on the c975L core — categories and medias (photos and videos from any platform UiBundle declares), with batch upload, automatic thumb/medium/highres derivatives and a public viewer.

[![License](https://img.shields.io/github/license/975L/GalleryBundle)](https://github.com/975L/GalleryBundle/blob/master/LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/c975l/gallery-bundle)](https://packagist.org/packages/c975l/gallery-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/c975l/gallery-bundle)](https://packagist.org/packages/c975l/gallery-bundle)
[![Codacy Grade](https://app.codacy.com/project/badge/Grade/afc2cddbc52444d99c991da76354d622)](https://app.codacy.com/gh/975L/GalleryBundle/dashboard)

## Why GalleryBundle

![GalleryBundle](.github/images/GalleryBundle.svg)

Add GalleryBundle on top of [c975L/CoreBundle](https://github.com/975L/CoreBundle) (ConfigBundle + UiBundle, one package) and get a photo gallery — no dependency on [SiteBundle](https://github.com/975L/SiteBundle), [ShopBundle](https://github.com/975L/ShopBundle) or any other satellite bundle, so it drops into any c975L site that needs one. Multi-size derivatives reuse UiBundle's own `VichMultiSizeImageInterface` pattern rather than duplicating it.

See it in action at [bundles.975l.com/pages/gallery-bundle](https://bundles.975l.com/pages/gallery-bundle), and browse every block kind live in the [block gallery](https://bundles.975l.com/pages/block-gallery).

---

> **TL;DR** — Photo galleries as `GalleryCategory` → `GalleryMedia`, managed from EasyAdmin, with bulk upload and automatic thumb/medium/highres derivatives. The category is the top-level unit: a site's galleries are its categories. Depends only on CoreBundle.

## Contents

- **Setup** — [requirements](#requirements) · [installation](#installation) · [configuration](#load-the-configuration) · [routes](#enable-routes) · [assets](#install-assets) · [theme](#install-the-theme)
- **Using it** — [public routes](#public-routes) · [linking from a menu](#linking-a-gallery-from-a-menu) · [renaming a category](#renaming-a-category) · [deleting a gallery](#deleting-a-gallery) · [uploading a batch](#uploading-a-batch) · [renaming a media](#renaming-a-media) · [browsing and the lightbox](#browsing-and-the-lightbox) · [editing from the public pages](#editing-from-the-public-pages) · [blocks](#blocks-defined-by-this-bundle) · [category summary](#a-categorys-summary) · [category headings](#composing-a-categorys-heading) · [theme tokens](#theme) · [videos](#videos) · [deleting a selection](#deleting-a-selection-of-medias) · [credits / rights on a selection](#applying-credits-or-rights-to-a-selection) · [export / import categories](#export--import-categories) · [sitemap and health check](#sitemap-and-health-check) · [backup](#backup) · [what's new](#whats-new) · [guided projects](#guided-projects)
- **Operating** — [bringing an existing gallery in](#bringing-an-existing-gallery-in) · [upload ceilings](#upload-ceilings)

## Features

- `GalleryCategory` → `GalleryMedia`: the category is the top-level unit, a site's galleries being its categories - no container above them.
- Bulk upload: pick every file at once from the category they belong to, with a title root, credits and rights-reserved applied to the whole batch, retouched one media at a time afterwards. The same batch is offered on the category creation form, so a category is created with its medias in one go. Optionally, the untouched originals are kept outside the document root (see [uploading a batch](#uploading-a-batch)).
- Three derivatives generated automatically per uploaded image (thumbnail / medium / highres), all three holding the whole photo, via UiBundle's `VichImageResizeListener` and the `VichMultiSizeImageInterface` contract - naming and resizing stay centralized in UiBundle, this bundle only declares the target sizes and how its grids frame them (see [Thumbnail framing](#thumbnail-framing)).
- One EasyAdmin menu entry ("Gallery", opening the categories, with their media count); a category's medias are listed under its own edit form, each thumbnail opening the media it stands for, and medias are added from the category itself.
- Each media in that list carries a checkbox, so a selection of them is deleted in one go instead of one edit screen at a time (see [deleting a selection](#deleting-a-selection-of-medias)), or given the same credits and rights at once (see [credits / rights on a selection](#applying-credits-or-rights-to-a-selection)).
- A catch-all "Non classé" category is created lazily so an imported media always has one, even without a real one to attach it to.
- A public front-office viewer (index → category → media), browsed entirely in the stored (medium) resolution, with circular previous/next navigation whose neighbouring images are preloaded in the background so switching medias never shows a blank image while it loads. The high resolution opens in a lightbox over the image, fetched only when the visitor asks for it (see [browsing and the lightbox](#browsing-and-the-lightbox)).
- Two block kinds contributed to UiBundle, so a gallery can be shown on any page composed in the back office instead of only under its own routes (see [blocks](#blocks-defined-by-this-bundle)).
- A category owns UiBundle blocks of its own, giving it an editorial heading above its grid (see [category headings](#composing-a-categorys-heading)).
- A category carries a rich-text summary, printed above its grid and reused as the page's social/search metas (see [summary](#a-categorys-summary)).
- Videos sit in the same categories as the photos: an entry becomes one by carrying the url of the page it is watched on, or a video file of the site's own, and each carries its own uploaded still, so one grid holds both kinds. YouTube, TikTok, Vimeo and Dailymotion are recognized, any other player being framed as pasted (see [videos](#videos)).
- The bundle's own stylesheet and theme file, reading UiBundle's admin-editable colors and fonts, so a gallery looks like the site it is installed on without a line of CSS (see [theme](#theme)).
- Sitemap generation (gallery index, categories and media pages), via ConfigBundle's `SitemapProviderInterface`
- The gallery index and each category offered as a SiteBundle menu target, so a navbar links straight to one of the site's galleries (see [linking a gallery from a menu](#linking-a-gallery-from-a-menu))
- Categories can be exported/imported as a zip (heading blocks, medias and files bundled in), plugging into ConfigBundle's **Export sync (everything)** dashboard shortcut and **Import content** screen.
- The two upload roots declared to the backup, via ConfigBundle's `BackupPathProviderInterface`, mirrored offsite rather than tarred (see [backup](#backup))
- Three replayable guided projects contributed to the dashboard, via ConfigBundle's `GuidedProjectProviderInterface`, walking a gallery's creation, its medias' arrangement and a media's own screen (see [guided projects](#guided-projects))

---

## Requirements

- PHP >= 8.4
- Symfony ^8.0
- [c975L/CoreBundle](https://github.com/975L/CoreBundle) — ConfigBundle and UiBundle ship as the single `c975l/core-bundle` package, so requiring this bundle pulls both (Vich naming/resizing, EasyAdmin form-theme conventions, stylesheet registry, page layout fallback, menu provider, scaffold, sitemap and health checks)
- Doctrine ORM
- EasyAdmin
- VichUploader Bundle
- `symfony/expression-language`, which the public routes' condition is evaluated with (see [public routes](#public-routes)) — pulled in by Composer

`GalleryMedia::$user` is typed against `c975L\ConfigBundle\Contract\UserInterface`: your `App\Entity\User` must implement it. The scaffolded `User` already does; an older one adds the `implements` itself, with no migration and no configuration change.

---

## Installation

### Download

```bash
composer require c975l/gallery-bundle
```

### Run migrations

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

### Load the configuration

```bash
php bin/console c975l:config:load-all
```

Seeds the bundle's `config/configs.json` into ConfigBundle's **Configuration** screen, under a **Gallery**
group of its own. It holds the gallery's [url prefix](#public-routes), editable from there like every
other setting of this ecosystem — nothing of this bundle is configured in the app's yaml.

### Enable routes

Add the bundle's public routes to `config/routes.yaml`:

```yaml
c975_l_gallery:
    resource: "@c975LGalleryBundle/src/Controller/"
    type: attribute
    prefix: /
```

Templates `{% extends 'layout.html.twig' %}`, same convention as this ecosystem's other public-facing
bundles (e.g. BookBundle) - override any of them from your app's
`templates/bundles/c975LGalleryBundle/`.

### Install assets

```bash
php bin/console assets:install --symlink
```

Nothing to register by hand, front or back. The bundle ships two Stimulus entrypoints, each starting its
own app: `controllers.js` for the public pages (previous/next preloading, the high-resolution lightbox,
the right click/drag blocking) and `controllers-admin.js` for the back office (the upload screen's batch
check, see [upload ceilings](#upload-ceilings)). Both are auto-registered through UiBundle's script
registry, and their `importmap.php` entries are written by `ImportmapProvider` the first time you
`composer update` after installing the bundle — `php bin/console c975l:config:check-importmap` reports
any that is missing.

### Install the theme

```bash
php bin/console c975l:scaffold:install
```

Copies `assets/styles/themes/gallery.css` into the app, where it is owned from then on (see
[theme](#theme)).

---

## Usage

### Public routes

| Route | URL | Description |
| --- | --- | --- |
| `gallery_index` | `/gallery` | Gallery index, one thumbnail per category |
| `gallery_category` | `/gallery/{category}` | Category grid, photos and videos alike |
| `gallery_media` | `/gallery/{category}/{slug}` | Media: photo in medium resolution, or video embed |

The first segment is the **Gallery url prefix** setting (`gallery-route-prefix`, group **Gallery** in
**Configuration**), so a site serves these routes in its own language — `galerie`, `fotos` — renamed from
the dashboard, with no yaml and **no cache to clear**: the change applies on the very next request.

A route path is compiled into the router's cache, so the prefix can't *be* the path: the three routes are
declared as `/{gallery_prefix}/…`, carrying it as a route parameter instead, and each of the three
routes carries a condition asking `Routing\GalleryRoutePrefix` whether the segment it was handed is the
configured one. Any other value simply doesn't match, and the router carries on with the rest of the
site's routes — without that check, `/{gallery_prefix}/{category}` would swallow every two-segment url of
the site. Generating a url is the mirror image: `Listener\GalleryRoutePrefixListener` puts the configured
prefix in the router's request context, which is where the generator takes a route parameter it wasn't
given from, so `path('gallery_category', {category: ...})` keeps taking the category alone.

Leading and trailing slashes are ignored, and an empty value falls back to `gallery` rather than mounting
the category route at the site root — as does a prefix not configured at all, before
`c975l:config:load-all` has run. `GallerySitemapProvider` reads the same service, so the declared urls
always match what the router serves; it does so directly rather than through the generator, the sitemap
being written from the command line where no request has filled the context. Route *names* never change,
whatever the prefix.

**Renaming the prefix breaks the previous urls**, which then 404. If they were indexed, declare a redirect
— ConfigBundle's **Redirections** screen takes one.

### Linking a gallery from a menu

`Management\LinkableRouteProvider` offers these routes to SiteBundle's menus (**Menus** in the dashboard,
navbar / footer / email header / email footer): the **target** select of a menu item lists the gallery
index alongside the site's pages, and **one entry per category** — the categories being the site's
galleries, an item usually points straight at one of them.

A category is listed there as **Galerie - Paysages**, so the galleries are found at a glance among every
page of the site and sit together once the list is sorted; the rendered navbar item reads **Paysages**,
the category's own title, the prefix being of no use in a bar.

A category entry is keyed on the category's **id**, so renaming the category, changing its slug or
renaming the route prefix leaves no menu item behind: only the target is stored, the url being generated
at each render and the label read from the category's own title. Deleting a category simply drops its
items from the rendered menu, as it does for any target that no longer resolves.

The item's own **label** field overrides that title, for a category whose name is too long to sit in a
navbar.

### Renaming a category

A category's slug is what `/{prefix}/{category}` is built from, so renaming a category moves its public
url. The title field asks for confirmation before it takes a single keystroke (UiBundle's `title-confirm`
controller, over EasyAdmin's own confirmation modal), and the slug is then rebuilt from the new title —
EasyAdmin's `SlugField` stops following its target field as soon as the slug holds a value, so on an edit
form nothing would resync it otherwise. Editing the slug by hand stays possible through the padlock, and a
slug already taken is refused by the form rather than silently suffixed.

Either way, the old url is not left to 404: `GalleryCategoryCrudController::updateEntity()` writes a
permanent redirect to the new one, in ConfigBundle's **Redirections**, reusing the row a previous rename
left behind rather than piling them up. Renaming a category back to what it was drops the redirect that
would otherwise point the other way, so the two never loop. This mirrors what SiteBundle does for a page.

The category's slug is also the segment above each of its medias, so a rename moves their urls too: a
second, wildcarded row (`/{prefix}/{old-slug}/*`, ConfigBundle's own convention) sends them to the renamed
category rather than leaving each media to 404.

### Deleting a gallery

A category is deleted from its own edit screen as well as from the listing's row button, EasyAdmin's own
confirmation modal standing in the way either way. **It takes everything under it along**: its medias —
their three derivatives and any kept original with them, `Listener\GalleryMediaDerivativeCleanupListener`
removing the files — and its heading blocks. The directory the category grouped its files under goes too,
in `public/` and in `private/`, once it is actually empty.

The catch-all **"Non classé"** category shows no delete button anywhere: it is what a media uploaded
without a real category falls back to, so it has to survive (`GalleryCategory::$uncategorized`, a flag
rather than a slug, so translating or editing its title changes nothing).

**The urls left behind answer 410 Gone**, not 404: every category page and every media page is declared
in the sitemap (`Sitemap\GallerySitemapProvider`), and a 410 is what drops a url from an index, where a
404 is retried for months. `Service\GalleryUrlRedirector` writes them in ConfigBundle's **Redirections**:
one row for the deleted url, plus a single wildcarded one (`/{prefix}/{slug}/*`) covering every media of
a deleted category rather than a row per media. The rows that redirected to that url answer the same 410
directly, so nothing points at a page that is gone. Deleting a single media leaves one row, from its own
screen as from the selection button under its category.

Creating a category or uploading a media under a slug a deletion had freed lifts its 410 by itself
(`GalleryUrlRedirector::release()`) — the redirect is resolved before the router, so the page would
otherwise exist while its url kept saying it doesn't. A row that redirects somewhere is never touched.

### Uploading a batch

Medias are only ever added in bulk, from the category they belong to — the upload screen
(`GalleryMediaBatchUploadType`) and the category creation form, which fills a category as it is created,
offer the same fields and go through the same `GalleryMediaFactory`. Four of them apply to the whole batch:

| Field | What it does |
| --- | --- |
| **Title root** | Titles every media `{root} 1`, `{root} 2`… numbered from where the category leaves off, so a second batch continues the series. Left empty, each title falls back to its own filename (`IMG_1234` → `Img 1234`). |
| **Credits** | The same credits line on every media of the batch. |
| **Rights reserved** | The same rights state on every media of the batch. |
| **Keep the originals** | Copies each untouched upload aside — see below. |

**The title root does not seed the slug.** A number reads as an order, and the order is the one thing a
gallery changes: reorder the medias and `cailloux-couleur-3` sits fifth. The slug takes six hex characters
instead, hashed from the photo's EXIF capture date (`DateTimeOriginal`) — intrinsic to the photo, so the
url survives a reordering and a retitling alike:

```text
Cailloux couleur 3   →   /photos/mineraux/cailloux-couleur-a1b2c3
```

The date itself never appears — when a photo was shot is nobody's business, only its stability is wanted.
Without EXIF (a scan, a screenshot, a stripped file, or no `ext-exif` installed) the seed falls back to the
filename and the rank in the batch; the hash is what makes that safe, nothing but hex reaching the url
whatever the browser sent. Two shots taken in the same second hash alike and the second is suffixed `-2`,
exactly as two identical filenames are.

#### Keeping the originals

`GalleryMedia` is a `VichOriginalKeepableInterface` (UiBundle). With the box checked, the uploaded file is
copied to `private/medias/gallery/{category}/{media}-{uniqid}-original.{ext}` — the same base name and the
same directory structure as the derivatives, one root over — *before* UiBundle's `VichImageResizeListener`
overwrites it in place with its own downscaled webp. That is the only moment the upload still exists as it
was sent.

The extension is the only part not derived from internal values, so it is decided on the **mime type read
off the file's own bytes**, against an allow-list (`jpg`, `png`, `gif`, `webp`, `tif`). A type off that
list is not kept at all rather than copied under an extension guessed from the name the browser sent —
which is client input that would otherwise land on disk as a path. The four files of a media therefore read
as one set, the original being the only one that is not a webp:

```text
public/medias/gallery/mineraux/cailloux-a1b2c3.webp           ← the medium served
public/medias/gallery/mineraux/cailloux-a1b2c3-thumb.webp
public/medias/gallery/mineraux/cailloux-a1b2c3-highres.webp
private/medias/gallery/mineraux/cailloux-a1b2c3-original.jpg  ← the untouched upload
```

`private/` is outside the document root, so nothing serves them: they are kept so a media can be
re-processed later (a new target width, a new format) without a re-upload. `GalleryMedia::$originalFilename`
records the path and doubles as the answer to "does this media have an original" — a media whose file is
replaced later goes on keeping one, the box only ever being answered at upload time. Deleting a media
removes it along with the derivatives (`GalleryMediaDerivativeCleanupListener`).

**They weigh what a camera writes.** A few thousand photos is tens of gigabytes, mirrored offsite rather
than archived (see [backup](#backup)) — on a media-heavy site, leaving the box unchecked keeps the
originals off the server entirely.

#### Watermarking the batch

The batch's other box stamps the **site's signature** into the photos — asked for at upload time or not at
all, the signature being burnt into the pixels of every size generated, not laid over them at display
time. It costs nothing at render, and it survives a right-click save, which is the point.

The signature itself is not this bundle's: UiBundle stamps it (`Service\ImageWatermarker`), from **two
images uploaded in Site graphics** — one for light corners, one for dark. The corner about to be covered is
sampled and the readable one of the two is picked, per photo; a site that uploaded only one gets that one
everywhere. **No signature uploaded, nothing stamped**, box checked or not.

Three settings drive it, in **Configuration**, group **General**:

| Setting | Slug | Default | What it does |
| --- | --- | --- | --- |
| Watermark - Corner | `ui-watermark-position` | `bottom-right` | `top-left`, `top-right`, `bottom-right` or `bottom-left` — anything else falls back to the bottom right |
| Watermark - Width (%) | `ui-watermark-width` | `13.75` | The signature's width, as a percentage of the photo's own (13.75 gives a 330px signature on a 2400px photo) |
| Watermark - Margin (%) | `ui-watermark-margin` | `0.42` | Its distance to the edges, same percentage — `0` lays it flush against them |

The batch's **Watermark corner** field overrides the first of the three for that batch alone, and is left
empty by default, which takes the site's corner. It is there for the gallery whose photos all leave the
same corner busy. The two others are site-wide only: the signature is measured on the source photo, so a
media's whole set carries one signature at one size.

**Nothing about the watermark is stored on the media.** The question belongs to the file being uploaded,
not to the media holding it, so a media's edit screen asks it again — unchecked by default — and only
answers for a **new file sent from that screen**. A file already stored carries the signature it was given,
and stamping it again would lay a second one over the first.

### Renaming a media

A media's **title** is its name and its `alt` text. It is **not** what its slug is built from: the two are
posed together when the media is created and go their own way afterwards. A media left without a title takes
its **category's** as its `alt` — on its thumbnail in the grid as in the lightbox — a photo being announced
by the gallery it belongs to rather than by its url.

That split is the point. A title uploaded in bulk is a placeholder — `Cailloux couleur 3`, or whatever the
camera called the file — and it is retouched precisely *because* it was one. When the url followed the
title, every such correction moved a public url and cost a redirect, which made naming a batch right the
first time a problem it never had to be. Now retitling moves nothing: the medias worth describing are
described afterwards, one by one, for free.

**The slug is posed once and never recomputed.** What moves it is an admin editing the slug field itself —
which sits behind EasyAdmin's own padlock, like a category's and a page's, asks for confirmation before it
unlocks, and writes a permanent redirect through `GalleryMediaCrudController::updateEntity()`. Moving a media to another category moves its url just as
much, the category's slug being the segment above it, and is redirected the same way. What is typed there
is still normalized (`Col du Galibier !` is stored `col-du-galibier`) and still has to be free within the
category — a collision is suffixed (`-2`, `-3`) rather than refused, unlike a category's slug, which is the
natural key an import matches on. **Emptying the field** is how a slug is asked to be rebuilt from the
title, which is the one remaining way to regenerate one.

**The stored file keeps the name it was given on upload.** It is named after the slug the media had then
(`medias/gallery/{category}/{media}-{uniqid}.webp`, see `GalleryMedia::getVichMediaPath()` and UiBundle's
`UiMediaNamer`), so a file and the page pointing at it read the same — but a later rename does not move it.
Renaming would mean moving three files (medium, thumbnail, high resolution) and costing the old urls their
place in an image index, for a signal the `alt` text already carries. Re-uploading the file names it after
the current slug.

### Browsing and the lightbox

A visitor browses one resolution only, the stored (medium) file: the index, the grids and the media page
all serve it, and the previous/next arrows move from one to the next without ever loading a heavier file.
The high resolution has no page of its own — it opens in a lightbox over the image, and is only fetched
the first time the visitor asks for it, so a run through a category costs what its medium files cost.

The **breadcrumb** opening every page says how much each level holds — the number of categories beside the
gallery's own label, the number of medias beside a category's title — so a visitor reads the size of what
they are stepping into before stepping in. The index counts the list it has already read, a category and a
media page count without listing. A caller passing no count gets the bare label rather than a `(0)`.

The previous/next arrows are **revealed by the pointer**, sitting on the photo, which is what the page is
for: they fade in when the pointer enters the media, and a keyboard focus reveals them just as well. On a
touch screen, where a first tap would be spent making them appear, they simply stay on.

The lightbox is a native `<dialog>` (`assets/js/gallery-lightbox.js`): its backdrop, its escape key and
its focus trap are the browser's own, no library involved. It closes on a click anywhere inside it as
well, which is why it carries no close button: a cross in the corner would only cover a part of the very
image it was opened to show. What opens it is a real link pointing at the high-resolution file, which the
controller intercepts: without javascript the file is still reachable, and the zoom is keyboard-operable
for free.

The right click and the drag are blocked on the grids and on the media page
(`assets/js/gallery-media-protect.js`), with the touch long-press neutralized in CSS. **This is a
deterrent, not a protection**: the file sits in the browser cache and its url is one developer-tools
panel away. What actually protects a photographer's work here is the medium/high resolution split above —
what is *served*, not what is forbidden. An app that would rather not block anything overrides the two
grid components and `gallery/media.html.twig`, dropping the `data-controller` and `data-action`
attributes; nothing else reads them.

### Editing from the public pages

Signed in with the **Site editor role** (`site-role-editor`, ConfigBundle), an **Edit** button appears
when the pointer or the keyboard focus reaches what it edits, and opens the back office in a new tab —
the same hover button UiBundle draws over an editable block, controller and stylesheet included
(`blockEditOverlay`), so nothing new is loaded on a page that already carries UiBundle's assets.

| Page | Hovering | Opens |
| --- | --- | --- |
| `/{prefix}/{category}` | the grid | the category's edit screen — the gallery itself: its heading, its medias, their order and its cover |
| `/{prefix}/{category}/{slug}` | the media | that media's own form |

A category page carries **one** button for the whole gallery, not one per thumbnail: a media is edited
from its own page, one click further. The heading blocks above the grid keep the button UiBundle already
draws for each of them.

The urls are generated, never written out: `Twig\Extension\GalleryEditUrlExtension` exposes
`gallery_category_edit_url(category)` and `gallery_media_edit_url(media)`, which ask EasyAdmin where the
CRUDs are mounted; the media one carries the category along, so saving, deleting or cancelling comes back
to the category the media belongs to. The role is checked in `gallery/category.html.twig` and
`gallery/media.html.twig`, where it costs no query, and a visitor is served the exact same pages without
the attributes.

**The whole gallery back-office sits behind that same role** — the categories, a media's form, the upload
screen and the batch actions. A site wanting it reserved to its administrators sets the setting to
`ROLE_ADMIN`.

The trip back is a **View on site** action, on the categories list and on a category's own screen, opening
the gallery in a new tab — the same action a page carries in SiteBundle. There is no preview twin to it: a
category has nothing to publish, it is online the moment it exists.

### Thumbnail framing

**The thumbnail file always holds the whole photo**, `GalleryMedia::THUMBNAIL_SIZE` (600px) capping its
longest side — it is only square for a square photo. What the two grids, the categories' and the medias',
do with it inside their square tiles is the **Thumbnails showing the whole photo** setting
(`gallery-thumbnail-whole`, group **Gallery** in **Configuration**):

| Setting | Rendering |
| --- | --- |
| off (default) | `object-fit: cover` — the tile is filled, the edges of a photo that is not square are cut off the display |
| on | `object-fit: contain` — the whole photo fits in the tile, with bands around it |

Nothing is served differently and nothing is regenerated: the switch adds one class, so it applies on the
very next request and is reversible at any time. The square itself never moves (`--gallery-thumb-size`),
and the bands take `--gallery-thumb-background`, transparent by default so the page's own background shows.

600px rather than the tile's own measure because the cropped display only keeps the shortest side of the
file — 400px on a 3:2 photo, which still fills a 150px tile on a 2x screen.

A gallery filled before this — its thumbnails cropped square on disk — is brought over with:

```bash
php bin/console c975l:gallery:rebuild-thumbnails
```

It rewrites every `-thumb.webp` from the highres derivative each media already carries (falling back on the
stored file when a gallery was imported without them), touches neither the database nor any other file, and
names the medias it found nothing to rebuild from. `--dry-run` lists what it would write.

### Blocks defined by this bundle

On top of the generic block system provided by [UiBundle](https://github.com/975L/CoreBundle), GalleryBundle registers the following blocks (see `config/services.yaml`), so a gallery can be placed on any page composed in the back office - a home page's "our latest photos" section, say - instead of only living under its own routes:

| Kind | Category | Description |
| --- | --- | --- |
| `gallery_categories` | `label.category_gallery` | Every category, one thumbnail each, as on `/gallery`. Takes an optional maximum. |
| `gallery_medias` | `label.category_gallery` | One category's photos and videos, as on `/gallery/{category}`. Takes the category, an optional maximum, whether to draw them at random, and whether to show a link to the full category. |

Both are `cacheable: false`: they resolve their content live through `gallery_block_*()` (`Twig\Extension\GalleryBlockExtension`), so a block never goes stale against the media library - what a Block stores is *what* to show (a category slug, a maximum), never the medias themselves. The slug is stored rather than the id, this bundle's natural key everywhere else, so a block survives an export/import to another site the same way a category does; a block pointing at a category deleted or renamed since renders nothing at all rather than an empty grid.

Being uncached is also what makes the random draw worth having: with "draw them at random" ticked, the maximum keeps that many medias out of the whole category, drawn again at every render - so a "our latest photos" section placed on a home page shows a different selection at each visit.

### A category's summary

`GalleryCategory::$summarySocialNetwork` is the category's own lead-in: rich text typed in its EasyAdmin
form (UiBundle's Trix editor, so **Donovan**'s rephrase button sits under it like under any other rich-text
field of the ecosystem), printed above the grid by `gallery/category.html.twig` and, stripped of its
markup, reused as the page's `description` / `og:description` metas — named after SiteBundle's
`Page::$summarySocialNetwork` and ConfigBundle's `UrlMetadata::$summarySocialNetwork`, which hold the
same text in the same role, so a site meets one name for it rather than one per bundle.

One field for both on purpose: what introduces a gallery to a reader is what introduces it to a search
engine, and an admin made to type the same sentence twice would leave one of the two stale. The metas
themselves are written by the layout, from the `summarySocialNetwork` Twig variable the template sets
(`og:description` truncated to 150 characters) — the Trix markup is reduced there, by the `plain_text`
filter both layouts apply, so the summary is handed over as typed.

It travels with its category through the export/import, an archive predating it importing as a category
without one, and one exported before the rename read under its old `description` key.

The two pages next to it fill the same variable their own way: a **media** composes it from the site name,
its category, its own title and its credits — a photo carries no summary of its own, and these four are
what situates one. The **index** of the gallery has no entity behind it at all, so it takes the summary an
admin wrote for its path in ConfigBundle's `UrlMetadata`, without a line of code here.

It is centered by default, under a short rule parting it from the breadcrumb — aligned with the breadcrumb
above it and the grid below, which is how a category page reads, and sized for the one to three lines a
gallery is actually introduced in. A site describing its categories in several paragraphs sets
`--gallery-category-description-text-align: left` (centered running text stops reading well past a few
lines), and one wanting no rule sets `--gallery-category-description-rule-height: 0` — see
[theme](#theme) for the whole `--gallery-category-description-*` set.

### Composing a category's heading

`GalleryCategory` implements UiBundle's `HasBlocksInterface`, so a category carries its own blocks, rendered above its grid by `gallery/category.html.twig`:

```twig
<twig:c975LUi:Blocks:Blocks blocks="{{ category.blocks }}"/>
```

They are edited in the category's own EasyAdmin form, with the full block picker (`hero`, `text_section`, `image`, `slider`…) - which is how a category introduces its medias ("Reportage Nordkapp, août 2025") without a template of its own. A category with no block renders exactly as before. `Management\GalleryBlockOwnerResolver` lets a saved block be dragged from one owner to another, nothing to register.

### Theme

The bundle ships its compiled stylesheet (`bundles/c975lgallery/css/styles.min.css`, contributed to
UiBundle's stylesheet registry by `Service\StylesheetProvider`) and, like every other c975L bundle, one
theme file of its own — `assets/styles/themes/gallery.css`, copied into the app by
`php bin/console c975l:scaffold:install` and owned by it from then on. Every token ships commented out
at the bundle's default: uncomment a line to take it over, leave it and it keeps following the bundle.

Fonts are deliberately absent from that file, and the site's own colors too: they are admin-editable, in
the **theme** config group, and the gallery reads them through UiBundle's own `--text` / `--white` /
`--black` / `--background` / `--font-family-body`, so a gallery looks like the site it is installed on
with no CSS to write. What the file offers is the gallery's own shapes — thumbnail size and grid gap, the
measure of the media page, the width of the passe-partout, the arrows, the lightbox, the video badge, the
category description and one aspect ratio per declared platform (plus the default an undeclared one is
framed in, and the width a portrait player is capped at).

A photo shows against a **ground of its own**, darker than the rest of a site usually wants to be. That is
what the **gallery-style** config (kind `choice`) is for: `light` (near-white page) or `dark` (near-black
page). Left empty — the shipped default — the gallery takes the site's own colors, exactly as before. The
three pages of the viewer (index, category, media) fill the `bodyClass` block SiteBundle's layout offers,
and the class they hand it is written by `gallery_body_class()` (`Twig\Extension\GalleryStyleExtension`),
which drops a value no block paints rather than putting it in the markup. An app whose layout offers no
`bodyClass` block simply renders the gallery on the site's background.

What a style retunes is **UiBundle's own palette** — `--background`, `--text`, `--black`, `--white`,
`--primary`, `--link-color` — and not a `--gallery-` namespace of its own, which is the one thing that
makes it reach what this bundle does not itself style. SiteBundle writes `color: var(--text)` through a
`*` rule, so a color declared on the body never reaches the `h1`, a composed block or anything else the
page holds, a real declaration always beating an inherited value: that is what left a gallery's title in
the site's color, unreadable, on the gallery's own ground. Retuning the tokens instead leaves every rule
of every bundle where it is and has it resolve to the gallery's values — the page's background, the canvas
beside it, the titles, the links, the cards, all of it, navbar and footer included, the rest of the site
keeping its own. `dark` retunes two more of SiteBundle's on top of that, both being roles `--primary`
alone does not settle: `--title-color`, the headings otherwise reading a deep brand color at barely more
than 1:1 on a near-black page, and `--footer-background` / `--footer-text`, a band of brand color cutting
across that same page — with `--footer-link-hover-background` dropped to `transparent`, a wash meant to
lift a colored band reading as a lit rectangle on one this dark, and `--navbar-site-name-color` taken to
the titles' own ink so the header reads as one. None of them is set in `light`, where against a near-white
ground they read exactly as they do on the site. `--primary` itself is left alone throughout — it is a
**surface** elsewhere (the primary button, a `primary` flat) whose white label needs it dark, so repainting
it would take the buttons with it. The blocks are declared on `:root:has(body.gallery-page--…)` rather than on the body,
the canvas beside the page being painted on `html`, above it; their specificity puts them above
SiteBundle's own `:root[data-theme="dark"]`, so a gallery asked for `light` stays light on a site fixed to
dark.

The **gallery-frame** config (kind `choice`) picks the passe-partout a displayed media is framed with —
`none`, `thin` (the shipped default) or `wide`. Its color is not part of the choice, being the theme's own
**ink**: white on a dark gallery, black on a light one, so it inverts along with the style — a mount the
color of the ground it is laid on being a mount nobody sees. It stays admin-editable on its own.

The same mount frames the **high resolution in the lightbox**, off those same two tokens: a print stays a
print, opened over the page as laid on it, and the choice made in the back office carries to both without
a token of its own. The image is `border-box` there, so the mount is taken off the dialog's measure rather
than added to it — added, it would run past a `max-height` the dialog clips at. Worth knowing on a `light`
gallery, whose ink is near-black against a lightbox backdrop that is near-black too: the mount is there,
but barely read. A design wanting it seen on both grounds gives the lightbox a color of its own, the
`--gallery-media-frame-color` token being overridable under the `.gallery-lightbox__image` selector.

Hovering a thumbnail bounces it, with UiBundle's own `bounceHorizontal` — reused rather than redefined,
its `animations.min.css` being served on every page. `--gallery-thumb-hover-animation` holds the whole
shorthand: set it to another of UiBundle's keyframes, or to `none` to leave the grid still. A visitor
asking for reduced motion gets no bounce whatever the token says.

The gallery's own **colors** are admin-editable too, nine entries in this bundle's own **gallery** config
group, so a design is retuned from the back office rather than from a file: passe-partout, arrows (color,
hover color, background), lightbox backdrop, breadcrumb, credits, and video badge (background, color).
What makes them CSS values is their `theme-color-gallery-*` slug, not the
group they show in: UiBundle's `ThemeVariablesCssListener` compiles every `theme-` slug it finds into
`--c975l-color-gallery-*`, which each token reads with the bundle's own default as its fallback — left
empty, nothing changes. Five of them are **loaded with that fallback as their own value**, so the back
office states the color rather than showing an empty field an admin has to guess at; emptying one paints
the very same color, the fallback being what it was read from. The four others are left empty on purpose:
their fallback is not a fixed color but an expression — the theme's own ink for the passe-partout, the
arrows' color for the hover, a mix of the site's text and background for the breadcrumb and the credits —
so they follow a light or a dark gallery, which a value written in would freeze. `ThemeColorDefaultTest`
keeps the two lists in step. Those laid **over a media** rather than on the page — the arrows, the lightbox,
the video badge — default to literal black and white and not to `var(--black)` / `var(--white)`: those two
are the site's ink and paper, which a dark site swaps, where a chevron on a photo wants the same white on
the same dark button whatever the page around it is. The cascade is unchanged — bundle stylesheet, then
the admin's values, then the app's `themes/gallery.css`, so uncommenting a color there takes it back from
the back office.

### Videos

A `GalleryMedia` becomes a video by carrying **the url of the page the video is watched on** — the one an
admin copies out of their browser's address bar, nothing to extract by hand. Whatever it carries, an
entry always has its own uploaded still: it is what the grids show, so one category holds photos and
videos alike, and nothing is fetched from a third party while a page renders. The url only decides what
opening the entry shows — the still and its lightbox, or the player.

**Which platforms** is UiBundle's question, not this bundle's: `c975L\UiBundle\Video\VideoPlatform` is
where one is declared, and declaring it there is all it takes for a gallery to hold it. YouTube, TikTok,
Vimeo and Dailymotion ship declared. What gets stored is always that platform's own **privacy-first embed
url**, resolved once when the media is saved: `youtube-nocookie.com` for YouTube, `dnt=1` for Vimeo — so
nothing downstream has to remember to ask for it, and a stored url is never the tracking one.

A url belonging to **no declared platform** is not refused: it is stored exactly as pasted, typed `embed`,
and framed in the default 16/9 shape. A PeerTube instance of one's own, a player from a platform this
ecosystem never heard of — the admin vouched for the url, and a gallery is not the place to argue. What
is deliberately absent is a "paste your embed code" field: third-party HTML in the database is an XSS and
a CSP hole, where an url is a value nothing executes.

- The **type is derived** from the url (`image`, a platform's name, or `embed`), never set beside it, so
  the two can't be left contradicting each other. Emptying the url turns the media back into a still.
- A video carries no lightbox at all: there is no high resolution to open, and blowing up the still would
  be worse than not offering it. Its page shows the player, the breadcrumb naming it as a video.
- The bulk upload screen only ever creates images: an entry becomes a video by editing it afterwards and
  giving it an url.

**A video of the site's own.** Next to the url, a media takes an **uploaded video file** (mp4, webm or
ogg), played by the browser itself with the still the entry already carries as its poster — no third
party, nothing to consent to, no CSP origin to allow, and a video that outlives whatever a platform
decides. What it costs is the storage and the bandwidth, which is why it stands next to the embeds
rather than replacing them.

A media carrying both plays **its own copy**: the file that outlives the platform is the one to play, and
the url stays there to fall back on if the file is ever removed. The ceiling is php's own
`upload_max_filesize`, not this bundle's 20 MiB one — that ceiling exists to keep a batch of photographs
from taking a shared host down, and would refuse any video worth uploading.

**Consent.** A player is a third-party frame whatever the platform, so it renders through UiBundle's own
`<twig:c975LUi:Video:Iframe>` — the iframe is created client-side, and only once the visitor has accepted
the site's cookie banner. On a site carrying no banner the player renders straight away, that component
never blocking content on a site that doesn't ask. There is no per-gallery opt-out: one policy for every
embed the ecosystem serves.

**Content-Security-Policy** is still the site's own to set, but no longer its own to keep in step —
UiBundle exposes every declared platform's origin as a parameter:

```yaml
# config/packages/nelmio_security.yaml
nelmio_security:
    csp:
        enforce:
            frame-src: ['self', '%c975l_ui.video.embed_origins%']
            # The level 1 fallback, for browsers that don't know frame-src
            child-src: ['self', '%c975l_ui.video.embed_origins%']
```

A `Permissions-Policy` header restricting `fullscreen` has to name those origins as well, or the player's
fullscreen button does nothing. A directive missing is what an empty frame in production and none in
development means. A platform declared under `embed` is the one case the parameter can't cover — its
origin is whatever the admin pasted, and has to be added by hand.

### Deleting a selection of medias

Under a category's edit form, each media carries a checkbox and the list a toolbar holding the
**Add medias** button, a "Select all" box and a **Delete selection** button, disabled until something is
checked (a category with no media yet shows the **Add medias** button on its own, and says so). The
**Add medias** button sits there rather than in the edit toolbar above, where EasyAdmin's own "Add a
block" action was the one under the hand of an admin meaning to add a media. The deletion is confirmed through
EasyAdmin's own modal (the one its delete actions open) and posted to
`GalleryCategoryCrudController::deleteMedias()`, which only ever touches the medias of the category the
url carries, whatever ids reach it. The files go with the rows, derivatives included
(`GalleryMediaDerivativeCleanupListener`), and a category whose cover was among them loses that cover.

### Applying credits or rights to a selection

The same toolbar carries a credits box with an **Apply credits** button, and a "Rights reserved" checkbox
with an **Apply rights reserved** one — both disabled until something is checked, like the deletion. Each
writes its own field on every checked media, so setting the credits never touches the rights and the other
way round, and both post to `GalleryCategoryCrudController::editMedias()`, which only applies the field the
button pressed names (a submit button posts its own name/value alone, the other button's control travelling
with it unread). The value is applied as the toolbar shows it: an empty credits box clears the credits, an
unchecked box takes the rights back off — which is the only way to blank either on a whole selection.

### Ordering the medias and picking a cover

In that same grid a tile is dragged to move it among the others, and carries a **Cover** radio — a
**Random cover** one sitting in the toolbar above. Both save themselves the moment they are used
(`gallery-media-sort.js` posting to `GalleryCategoryCrudController::saveMediasLayout()`, csrf token in the
`X-CSRF-Token` header, as UiBundle's own block move does): there is no button, the grid not being part of
the edit form above (an html form never nests in another), and nothing on the screen could have told an
admin that its **Save** button ignores the grid. A call that fails says so and reloads the screen, which
then shows what was actually saved.

The positions are renumbered from 0 following the order posted, so a gap left by a deleted media closes on
its own — a media's own edit screen still shows its position as a number, and an upload adds its files after
the last one. A category with no cover picked is represented by one of its medias drawn at random on each
render, which is the fallback the public index and the admin's thumbnail column have always used and what
the **Random cover** radio goes back to.

The drag itself is UiBundle's gesture layer (`addSortGesture()`, from its `pointer-sort.js`), the same one
its `ea-sortable` uses for a blocks collection: Pointer Events, so a finger and a stylus reorder as a mouse
does. Only where a dragged tile lands is computed here — a wrapping grid of thumbnails has nothing in
common with the vertical list of rows a blocks collection is.

Each tile carries a **move** handle, and that handle is the grab point at the finger: arming the whole tile
for touch takes `touch-action: none` over it, and a screenful of thumbnails would leave nowhere to scroll
the page from. With a mouse the whole tile is grabbable, its own clicks surviving — the thumbnail still
opens the media, the two boxes still tick, only a real drag gesture hijacking them.

That import needs its importmap entry in the consuming app, which `c975l:config:check-importmap` reports:

```php
'@c975l/ui-bundle/pointer-sort.js' => ['path' => './vendor/c975l/core-bundle/UiBundle/assets/js/pointer-sort.js'],
```

### Export / import categories

Selected categories can be exported as a zip (title/slug/blocks/medias, files bundled in) via the category
index's "Export selection" batch action, meant to be re-uploaded on another site/environment through
ConfigBundle's **Import content** dashboard screen (see `GalleryImportProvider`). Ids never need to
match between the two sites: a category is matched by slug on import, the slug being unique (a second
category taking a slug already used is refused by the form). `GalleryExportProvider` (the same serialization, every
category) also plugs categories into ConfigBundle's **Export sync (everything)** dashboard shortcut.

A category's [heading blocks](#composing-a-categorys-heading) travel with it, their own medias joining the
archive, and are replaced wholesale on import — the same way `PageImportProvider` replaces a page's. An
archive exported before categories gained a heading imports as a category without one.

Every file a media holds travels: the stored one, its thumbnail and high resolution siblings, its
self-hosted video, and the [original it kept](#uploading-a-batch) — put back under `private/` on import, so
an imported gallery can still be re-processed without a re-upload. **They travel with their names**, and are
laid straight back under them: the upload pipeline is skipped entirely, so an imported gallery is the same
gallery down to the bytes, and answers at the very same image urls on every site it is synced to. That
matters for urls that are shared and cached — and it also means importing a category of three hundred photos
copies files instead of resizing three hundred images.

A name coming out of an archive is only honoured under `public/medias/gallery/` (and `private/` for the
original), as a plain relative name: anything climbing out of it is refused and the file named by Vich
instead, as an archive exported before the names travelled is. Such an archive also has its thumbnail and
high resolution recomputed from the stored file — which is why they travel now: the high resolution came
back at the stored file's own width, and each round-trip re-encoded the webp once more.

Nothing travels about the [watermark](#watermarking-the-batch), there being nothing stored to travel: the
archived files already carry the signature in their pixels, and the import asks for none, which would lay
a second one over the first. That is also why the derivatives are archived rather than rebuilt from the
kept original, which is copied aside before any signature is laid.

### Sitemap and health check

The urls are declared by `GallerySitemapProvider` (ConfigBundle's `SitemapProviderInterface`): the `/gallery`
index, one entry per category, and one per media — a media has a page of its own, which is what an image
search actually lands on. `GalleryCategory` carries no date of its own, so a category page is dated by its
most recently touched media. Nothing to register — the provider is picked up automatically.

The index and the categories also carry a `title`, which the sitemap ignores and which ConfigBundle's
`SeoFilesWriter` builds the site's `public/llms.txt` from. The medias deliberately carry none, and an
untitled url is skipped there: a gallery declares one url per media, and listing them all would turn
llms.txt into a Markdown sitemap.

`public/sitemap-gallery.xml` and the site's `public/sitemap-index.xml` are written by ConfigBundle, which
collects every installed bundle's provider:

```bash
php bin/console c975l:sitemaps:create
```

Those same urls are also **health-checked** for free, ConfigBundle's `DeclaredUrlsHealthCheckPass` registering
one check per declared sitemap with nothing to implement bundle-side: every declared url
gets the content-quality checks (title/description length, missing `<h1>`, Open Graph share tags, images
without `alt`, broken links) under its own `urls-gallery` kind on the Health check dashboard. Worth keeping on
its own, less frequent schedule — a gallery declares one url per media:

```bash
php bin/console c975l:health-check:run --kind=urls-gallery
```

### Backup

ConfigBundle backs up nothing it wasn't declared, so `GalleryBackupPathProvider` names this bundle's two
upload roots — the only content of a gallery that neither a git clone nor a database dump brings back.
Nothing to register, the provider is picked up automatically:

| Path | Mode |
| --- | --- |
| `public/medias/gallery` | `mirror` |
| `private/medias/gallery` | `mirror` |

`mirror` rather than `archive`: they are copied as-is by `c975l:config:backup:offsite`, never tarred and
never dated — a photo needs a copy, not a version history, and bzip2 gains about nothing on a webp. The
derivatives, the self-hosted videos and the kept originals all live under those two roots, so nothing else
is declared. A site with no gallery yet declares two folders that aren't on disk, which are skipped
without an error.

```bash
php bin/console c975l:config:backup:offsite    # mirrors the declared folders, this bundle's two included
```

### What's new

`config/whatsnew.json` holds this bundle's own news, `WhatsNewProvider` (ConfigBundle's
`WhatsNewProviderInterface`) handing it over. ConfigBundle merges every installed bundle's entries by date
and shows the latest of them on the dashboard, the whole history being a click away. Nothing to register —
the provider is picked up automatically.

One row per date, in reverse chronological order, each description translated in the three locales the
bundle covers; the visitor's own locale applies, English being the fallback:

```json
[
    {
        "date": "2026-08-08",
        "description": [
            {
                "en": "A category can carry a description…",
                "fr": "Une catégorie peut porter une description…",
                "es": "Una categoría puede llevar una descripción…"
            }
        ]
    }
]
```

Written for the site's owner rather than for a developer: what changed on the screens and on the public
pages, not which class carries it — the ChangeLog is where the code's history lives.

### Guided projects

`GalleryGuidedProjectProvider` (ConfigBundle's `GuidedProjectProviderInterface`) contributes three replayable
exercises to the dashboard's "Guided projects" panel: **creating a gallery** with its first photographs in
one go — the creation form carries the whole batch, which is the only screen doing both —, **arranging a
gallery's medias** on its own edit screen, where the order, the cover and the batch edits all save as they
go, and **filling in a media's own screen**, where a caption is written and a video attached. Nothing to
register — the provider is picked up automatically.

Only the opening step of each carries an `url`, all three sending the user to the categories, the single
sidebar entry of the whole feature. From there the panel walks that screen, highlighting the button or the
field they are meant to use next — one they click themselves, which brings the panel back on that very step:

| Pointed at | What it is |
| --- | --- |
| `.action-new`, `.action-edit`, `.action-saveAndReturn` | EasyAdmin builds an `action-<name>` class from the action's own name — `saveAndReturn`, not `save` |
| `#GalleryCategory_title`, `#GalleryCategory_titleRoot`, `#GalleryMedia_title`, `#GalleryMedia_credits`, `#GalleryMedia_externalUrl` | plain form fields, pointed at through their rendered id |
| `#GalleryCategory_files` | the batch upload of the creation form |
| `[data-gallery-upload-medias]`, `[data-gallery-cover-radio]`, `[data-gallery-media-sort-handle]`, `[data-gallery-media-selection-target="toggle"]` | markers carried by this bundle's own templates, the elements having no id of their own |
| `.management-media-grid__item` | a thumbnail of the medias grid, opening the media it stands for |

An app overriding `templates/management/gallery_category_edit.html.twig` keeps those `data-` attributes, or
the steps resting on them point at nothing — they are read as selectors, not as behaviour.

All three are gated by `site-role-editor`, the same ConfigBundle entry the gallery's management screens sit
behind: an admin without it is never offered a parcours ending on an access-denied page. Their `order` (140,
150, 160) continues the ecosystem's sequence, after ConfigBundle (10-40), SiteBundle (50-80), UiBundle
(90-110) and SocialBundle (120-130). Nothing is derived from the site's own data, so a project is worth
following on a site already full of galleries, and worth replaying once done (see ConfigBundle's README,
"Contributing guided projects from other bundles").

---

## Bringing an existing gallery in

A site arriving with its photos in a folder tree — served by a hand-rolled `Symfony\Finder` listing, by
another gallery bundle, by anything — brings them in through the back office, one category at a time:

1. create the category — **Gallery** in the menu, then **Add**,
2. on that category's own row, click **Add media** and select the whole folder at once in the file picker —
   the field takes as many files as you give it, credits and rights-reserved applying to the batch and
   retouchable one at a time afterwards,
3. repeat per folder.

Each media's title is seeded from the name of the file it came in as, underscores and dashes read as
spaces: `mont-blanc-2019.jpg` lands as *Mont Blanc 2019*. That title is the media page's own heading, its
`alt` text, its url and the name its stored file is given, and the uploaded name is not kept anywhere
afterwards — so **rename the files before uploading them** if they are numbered (`114.jpg` gives a title of
`114`, a url of `/114` and a file called `114-*.webp`), and retouch what matters one at a time from the
category's edit screen, which lists its medias. Retitling one afterwards moves its url and leaves a
redirect behind, but does not rename its stored file (see [renaming a media](#renaming-a-media)).

Upload **the originals**, not the derivatives an older gallery generated alongside them (`-small`,
`-thumb`, a `thumbs/` subfolder…): this bundle derives its own thumbnail, medium and highres from what it
receives, and feeding it an already-shrunk file caps the quality of all three for good — a source
narrower than 1024px leaves the high resolution with nothing to show over the medium one.

### Upload ceilings

A bulk upload meets four of them, and the first three are PHP's own. PHP does not *refuse* a batch that
exceeds them — **it truncates it**: past `max_file_uploads` the extra files are dropped without a word,
past `post_max_size` the request arrives empty, csrf token included. Neither can be recovered from once
the request has landed, so both screens that carry a batch — the upload screen and the category creation
form — check the selection before sending it:

| Setting | Common default | What it caps |
| --- | --- | --- |
| `max_file_uploads` | 20 | Number of files in one submission |
| `upload_max_filesize` | 2M | Each file |
| `post_max_size` | 8M | The whole batch |
| `UploadLimits::MAX_FILES` | 100, this bundle | Number of files, whichever of the two is smaller applying |
| `UploadLimits::MAX_FILE_SIZE` | 20 MB, this bundle | Each file, same rule |

The bundle's own two are there because a host being generous says nothing about what the batch costs
once it lands: every file is decoded, resized three times and written back inside that one request. A
category of 150 files is two uploads, which it takes just as well — positions simply continue where the
first batch left off.

`Service\UploadLimits` reads the three settings from the running PHP, so the screen states the ceilings
that really apply — in the field's help before anything is picked, and again in
`assets/js/gallery-upload-limits.js`, which weighs the selection the moment it is made and names what is
wrong (how many files over, which ones are too heavy, what the batch weighs) — the upload screen also
disables its submit button, the creation form leaving EasyAdmin's own buttons alone. A batch that gets
past the check anyway is caught server-side on both screens and reported rather than silently
redisplayed. Nothing to wire up: the controller ships as this bundle's EasyAdmin entrypoint
(`assets/controllers-admin.js`, contributed through `Service\ScriptProvider` and `Management\ImportmapProvider`).

Raise the three in the site's own `php.ini` if they sit below what the bundle allows —
`max_file_uploads = 100`, `upload_max_filesize = 20M`, `post_max_size = 300M` lets a full batch through.
Note that `max_file_uploads` is `PHP_INI_SYSTEM`: a `.user.ini` cannot raise it, only the server's own
configuration can. Both constants on `UploadLimits` are there to be raised by an app that knows its
server takes more.

This bundle deliberately ships no import command for that. What such a tool would have to guess — which
files are originals and which are an old gallery's derivatives — has no answer that holds from one site
to the next, and getting it wrong imports blurry duplicates that then have to be found and deleted by
hand. Photos already managed by this bundle on *another* c975L site are a different matter: they move
across with [export / import categories](#export--import-categories), files and all.

---

> [!TIP]
> If this project **helps you save development time**:
>
> - [**star** it on GitHub](https://github.com/975L/GalleryBundle) — helps others find it
> - [**open an issue**](https://github.com/975L/GalleryBundle/issues/new) to share how you use it — genuinely useful feedback
>
> And if you'd like to support the work directly, the **Sponsor** button at the top of the GitHub page is there for that. Thank you!
